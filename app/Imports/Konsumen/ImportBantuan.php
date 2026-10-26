<?php

namespace App\Imports\Konsumen;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Models\AlihPembeliRiwayat;
use App\Models\CancellationRequest;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Bantuan bersama untuk importer Konsumen (sheet cara bayar & sheet Batal): kolom Peralihan Pembeli di ujung sheet dan peringatan
 * kemungkinan konsumen ganda. Peringatan TIDAK memblokir: barisnya tetap diimpor, hanya ditandai di laporan.
 */
class ImportBantuan
{
    public const KUNCI_PERALIHAN = ['alih_nama', 'alih_nik', 'alih_hp', 'alih_hubungan', 'alih_tgl', 'alih_alasan'];

    /** Kolom peralihan opsional (ditaruh di ujung kanan agar file lama tetap valid). */
    public static function kolomPeralihan(): array
    {
        return [
            ['alih_nama', 'Pembeli Sebelumnya - Nama', 'o', 'Isi hanya kalau transaksi ini hasil ALIH PEMBELI (mis. A tidak jadi, UTJ dialihkan ke B; baris ini diisi data B). Kosong = tidak ada peralihan. Kolom peralihan lain wajib kosong kalau ini kosong.'],
            ['alih_nik', 'Pembeli Sebelumnya - NIK', 'o', 'Kalau cocok dengan konsumen yang sudah ada, konsumen itu dipakai ulang. Tidak boleh sama dengan NIK pembeli di baris ini. Kosongkan kalau tidak ada.'],
            ['alih_hp', 'Pembeli Sebelumnya - No. HP', 'o', 'Tidak boleh sama dengan No. HP pembeli di baris ini. Kosongkan kalau tidak ada.'],
            ['alih_hubungan', 'Hubungan dengan Pembeli Sebelumnya', 'o', 'Dropdown. Kosong = Lainnya.'],
            ['alih_tgl', 'Tanggal Peralihan', 'o', 'Tidak boleh sebelum Tanggal Booking (dan tidak boleh sesudah Tanggal Batal untuk sheet Batal). Kosong = memakai Tanggal Booking.'],
            ['alih_alasan', 'Alasan Peralihan', 'o', 'Mis. "BI Checking pembeli awal tidak lolos". Kosong = "Import data lama".'],
        ];
    }

    /**
     * Baca & validasi kolom peralihan satu baris. Mengembalikan [data|null, error[]]; data null berarti tidak ada peralihan.
     * $batasAtas = Tanggal Batal untuk sheet Batal (peralihan tidak boleh lebih akhir), null untuk sheet cara bayar.
     */
    public static function bacaPeralihan(array $v, ?Carbon $tglBooking, ?Carbon $batasAtas, ?string $nikSekarang, ?string $hpSekarang, callable $parseTanggal): array
    {
        $str = fn (string $k) => isset($v[$k]) && trim((string) $v[$k]) !== '' ? trim((string) $v[$k]) : null;
        if (!collect(self::KUNCI_PERALIHAN)->contains(fn ($k) => $str($k) !== null)) return [null, []];

        $errors = [];
        $nama = $str('alih_nama');
        if (!$nama) $errors[] = 'Pembeli Sebelumnya: isi namanya (kolom peralihan lain terisi).';

        $hubungan = 'lainnya';
        if ($label = $str('alih_hubungan')) {
            $hubungan = array_search(mb_strtolower($label), array_map('mb_strtolower', AlihPembeliRiwayat::hubunganLabel()), true) ?: null;
            if (!$hubungan) $errors[] = "Hubungan peralihan '{$label}' tidak dikenal.";
        }

        [$tanggal, $err] = $parseTanggal($v['alih_tgl'] ?? null);
        if ($err) $errors[] = "Tanggal Peralihan: {$err}";
        $tanggal ??= $tglBooking;
        if ($tanggal && $tglBooking && $tanggal->lt($tglBooking)) $errors[] = 'Tanggal Peralihan lebih awal dari Tanggal Booking.';
        if ($tanggal && $batasAtas && $tanggal->gt($batasAtas)) $errors[] = 'Tanggal Peralihan melewati Tanggal Batal.';

        $nik = $str('alih_nik');
        $hp = $str('alih_hp');
        if ($nik && $nikSekarang && $nik === $nikSekarang) $errors[] = 'NIK Pembeli Sebelumnya sama dengan NIK pembeli di baris ini.';
        if ($hp && $hpSekarang && $hp === $hpSekarang) $errors[] = 'No. HP Pembeli Sebelumnya sama dengan No. HP pembeli di baris ini.';

        if ($errors) return [null, $errors];

        return [['nama' => $nama, 'nik' => $nik, 'hp' => $hp, 'hubungan' => $hubungan, 'tanggal' => $tanggal, 'alasan' => $str('alih_alasan') ?? 'Import data lama'], []];
    }

    /**
     * Catat peralihan setelah transaksi dibuat: pembeli sebelumnya (dipakai ulang lewat NIK atau dibuat), riwayat peralihan, dan
     * pengajuan Alih Pembeli berstatus disetujui (agar tampil di menu Pengajuan seperti peralihan lewat sistem).
     */
    public static function terapkanPeralihan(KavlingKonsumen $trx, Konsumen $sekarang, array $d, KonsumenImportResult $result, string $sheet, int $baris): void
    {
        $lama = $d['nik'] ? Konsumen::where('nik', $d['nik'])->first() : null;
        if ($lama) {
            if ($pesan = self::peringatanNikBeda($d['nama'], $d['nik'], $lama)) $result->warn($sheet, $baris, 'Pembeli Sebelumnya: ' . $pesan);
        } else {
            // Periksa nama kembar SEBELUM membuat konsumen baru, kalau tidak yang ditemukan justru konsumen yang baru dibuat ini.
            if (!$d['nik'] && ($pesan = self::peringatanTanpaNik($d['nama']))) $result->warn($sheet, $baris, 'Pembeli Sebelumnya: ' . $pesan);
            $lama = Konsumen::create(['nama' => $d['nama'], 'nik' => $d['nik'], 'no_hp' => $d['hp']]);
        }

        $pengajuan = CancellationRequest::create([
            'type'                  => CancellationRequestType::BuyerTransfer,
            'kavling_id'            => $trx->kavling_id,
            'kavling_konsumen_id'   => $trx->id,
            'konsumen_id'           => $lama->id,
            'konsumen_baru_id'      => $sekarang->id,
            'hubungan'              => $d['hubungan'],
            'kavling_status_before' => $trx->kavling->status_jual->value,
            'alasan'                => $d['alasan'],
            'requested_by'          => Auth::id(),
            'reviewed_by'           => Auth::id(),
            'reviewed_at'           => $d['tanggal'],
            'status'                => CancellationStatus::Approved,
        ]);
        AlihPembeliRiwayat::create([
            'kavling_konsumen_id' => $trx->id, 'dari_konsumen_id' => $lama->id, 'ke_konsumen_id' => $sekarang->id, 'hubungan' => $d['hubungan'],
            'alasan' => $d['alasan'], 'tahap_sebelum' => null, 'cancellation_request_id' => $pengajuan->id,
            'diajukan_oleh' => Auth::id(), 'disetujui_oleh' => Auth::id(), 'disetujui_at' => $d['tanggal'],
        ]);
    }

    /** Peringatan kemungkinan ganda untuk pembeli di satu baris: NIK kosong dengan nama yang sudah ada, atau NIK cocok tetapi nama jauh berbeda. */
    public static function peringatanKemiripan(string $nama, ?string $nik, ?Konsumen $cocokNik): ?string
    {
        if ($nik === null) return self::peringatanTanpaNik($nama);

        return $cocokNik ? self::peringatanNikBeda($nama, $nik, $cocokNik) : null;
    }

    private static function peringatanTanpaNik(string $nama): ?string
    {
        $ada = Konsumen::whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->first();

        return $ada ? "\"{$nama}\" tanpa NIK, sedangkan sudah ada konsumen bernama sama (ID {$ada->id}). Dibuat sebagai konsumen baru — cek kemungkinan ganda." : null;
    }

    private static function peringatanNikBeda(string $nama, string $nik, Konsumen $cocok): ?string
    {
        similar_text(mb_strtolower($nama), mb_strtolower($cocok->nama), $persen);

        return $persen < 50 ? "NIK {$nik} cocok dengan konsumen \"{$cocok->nama}\" (ID {$cocok->id}), sedangkan baris menulis \"{$nama}\" — pastikan NIK-nya benar." : null;
    }
}
