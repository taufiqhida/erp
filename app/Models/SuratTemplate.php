<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;

class SuratTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nama',
        'file_path',
        'file_original_name',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Placeholder yang tersedia untuk ditaruh di file docx — dipakai sebagai
     * panel referensi di form admin, bukan buat insert otomatis (templatenya
     * file asli, bukan diketik di sistem). Isi nilainya lewat
     * KavlingKonsumen::suratPlaceholders(), lihat method itu untuk sumber
     * datanya masing-masing.
     */
    public static function availablePlaceholders(): array
    {
        return [
            '${nama_konsumen}'    => 'Nama Konsumen',
            '${nik_konsumen}'     => 'NIK Konsumen',
            '${npwp_konsumen}'    => 'NPWP Konsumen',
            '${no_hp_konsumen}'   => 'No. HP Konsumen',
            '${alamat_konsumen}'  => 'Alamat Konsumen',
            '${nama_proyek}'      => 'Nama Proyek',
            '${nomor_kavling}'    => 'Nomor Kavling',
            '${blok_kavling}'     => 'Blok-Nomor Kavling (mis. A1-1)',
            '${kluster_kavling}'  => 'Kluster Kavling (kosong kalau tidak ada)',
            '${tipe_unit}'        => 'Tipe Unit',
            '${luas_tanah}'       => 'Luas Tanah (m²)',
            '${luas_bangunan}'    => 'Luas Bangunan (m²)',
            '${hgb_no}'           => 'Nomor HGB',
            '${id_lokasi_rumah}'  => 'ID Rumah (Tapera/SIKUMBANG)',
            '${harga_deal}'       => 'Harga Jual (Total)',
            '${harga_dasar}'      => 'Harga Dasar',
            '${cara_bayar}'       => 'Cara Pembayaran',
            '${uang_muka}'        => 'Uang Muka (DP)',
            '${sbum}'             => 'Nominal SBUM',
            '${kpr_diajukan}'     => 'KPR yang Diajukan',
            '${nama_bank}'        => 'Nama Bank Tujuan (singkat)',
            '${nama_bank_pt}'     => 'Nama PT Resmi Bank Tujuan',
            '${kantor_cabang_bank}' => 'Kantor Cabang Bank Tujuan',
            '${alamat_bank}'      => 'Alamat Bank Tujuan',
            '${tanggal_akad}'     => 'Tanggal Akad',
            '${tanggal_hari_ini}' => 'Tanggal Hari Ini',
            '${tgl_spk}'          => 'Tanggal Terbit SPK Aktif',
            '${tahun_pembangunan}' => 'Tahun Pembangunan (dari tahun terbit SPK aktif)',
            '${nomor_surat}'      => 'Nomor Surat (diisi manual saat cetak)',
            '${nama_penandatangan}'    => 'Nama Penandatangan',
            '${jabatan_penandatangan}' => 'Jabatan Penandatangan',
            '${nama_developer}'   => 'Nama Developer',
            '${alamat_developer}' => 'Alamat Developer',
        ];
    }

    /**
     * Baris tabel Jadwal Pembayaran (khusus template yang punya tabel ini,
     * mis. SPR) — dianchor lewat placeholder ${tahap} di baris pertama tabel,
     * di-clone otomatis sesuai jumlah cicilan lewat TemplateProcessor::cloneRow().
     */
    public static function jadwalPembayaranPlaceholders(): array
    {
        return [
            '${tahap}'          => 'Nama Tahap (mis. Uang Tanda Jadi, Pembayaran 1)',
            '${tanggal_bayar}'  => 'Tanggal Jatuh Tempo/Bayar',
            '${jumlah_bayar}'   => 'Jumlah',
        ];
    }

    /**
     * Isi placeholder dari data 1 transaksi + input manual (nomor surat,
     * override penandatangan, dll) ke file docx template ini, simpan hasilnya
     * ke storage sementara, kembalikan path-nya. Pemanggil (SuratGenerateController)
     * yang tanggung jawab stream-download & hapus file setelah selesai.
     */
    public function generateDocx(KavlingKonsumen $kk, array $extra = []): string
    {
        $processor = new TemplateProcessor(Storage::path($this->file_path));

        $data = array_merge($kk->suratPlaceholders(), $extra);
        foreach ($data as $key => $value) {
            $processor->setValue($key, htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
        }

        $jadwal = $kk->jadwalPembayaranRows();
        if (in_array('tahap', $processor->getVariables(), true) && count($jadwal) > 0) {
            $processor->cloneRow('tahap', count($jadwal));
            foreach ($jadwal as $i => $row) {
                $n = $i + 1;
                $processor->setValue("tahap#{$n}", htmlspecialchars($row['tahap'], ENT_QUOTES, 'UTF-8'));
                $processor->setValue("tanggal_bayar#{$n}", htmlspecialchars($row['tanggal_bayar'], ENT_QUOTES, 'UTF-8'));
                $processor->setValue("jumlah_bayar#{$n}", htmlspecialchars($row['jumlah_bayar'], ENT_QUOTES, 'UTF-8'));
            }
        }

        return $this->saveToTmp($processor);
    }

    private function saveToTmp(TemplateProcessor $processor): string
    {
        $outputDir = storage_path('app/tmp');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        $outputPath = $outputDir . '/' . uniqid('surat_') . '.docx';
        $processor->saveAs($outputPath);

        return $outputPath;
    }
}
