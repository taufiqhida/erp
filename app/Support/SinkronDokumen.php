<?php

namespace App\Support;

use App\Models\DokumenKonsumen;
use App\Models\DokumenTemplate;
use App\Models\KavlingKonsumen;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menyamakan daftar berkas konsumen dengan Template Pemberkasan terbaru. Daftar berkas disalin dari template saat booking,
 * jadi perubahan template (tambah/hapus/ubah sifat/nama/syarat) tidak otomatis sampai ke konsumen yang sudah tercatat.
 *
 * Aturan yang menjaga data: status berkas yang sudah diisi TIDAK pernah ditimpa; berkas yang sudah punya status selain
 * "Belum Ada" tidak dihapus walau template-nya sudah tidak ada (dipertahankan dan ditandai); hanya berkas "Belum Ada" yang dihapus.
 */
class SinkronDokumen
{
    /** Transaksi yang layak disinkronkan: masih berjalan (bukan batal/selesai). */
    public static function transaksiAktif()
    {
        return KavlingKonsumen::query()->where('status', 'active')->where('status_penjualan', '!=', 'batal');
    }

    /**
     * Rencana perubahan untuk satu transaksi. Tiap butir: ['aksi' => tambah|ubah|hapus|pertahankan, 'nama' => ..., 'detail' => ...].
     * 'terapkan' (callable) hanya diisi di dalam terapkan(); rencana murni tanpa efek samping.
     */
    public static function rencana(KavlingKonsumen $kk): array
    {
        $kk->loadMissing('konsumen', 'dokumens');
        $templates = DokumenTemplate::where('cara_bayar', $kk->cara_bayar)->orderBy('urutan')->orderBy('id')->get();
        [$cocok, $sisaBerkas, $templateBaru] = self::cocokkan($kk->dokumens, $templates);

        $butir = [];
        foreach ($cocok as [$berkas, $template]) {
            $ubah = self::perubahan($berkas, $template, $kk);
            // Perubahan hanya tautan ke template (tanpa teks) dilakukan diam-diam: aksi 'tautan', tidak dihitung/ditampilkan.
            if ($ubah) $butir[] = ['aksi' => $ubah['teks'] ? 'ubah' : 'tautan', 'berkas_id' => $berkas->id, 'template_id' => $template->id, 'nama' => $template->nama_dokumen, 'detail' => implode('; ', $ubah['teks']), 'data' => $ubah['data']];
        }
        foreach ($templateBaru as $template) {
            $butir[] = ['aksi' => 'tambah', 'berkas_id' => null, 'template_id' => $template->id, 'nama' => $template->nama_dokumen,
                'detail' => $template->sifat_label . (($template->berlakuUntuk($kk->konsumen) ?? true) ? '' : ' (tidak berlaku untuk konsumen ini)'), 'data' => []];
        }
        foreach ($sisaBerkas as $berkas) {
            if ($berkas->status === 'belum_ada') {
                $butir[] = ['aksi' => 'hapus', 'berkas_id' => $berkas->id, 'template_id' => null, 'nama' => $berkas->nama_dokumen, 'detail' => 'Tidak ada lagi di template (belum diisi)', 'data' => []];
            } else {
                $butir[] = ['aksi' => 'pertahankan', 'berkas_id' => $berkas->id, 'template_id' => null, 'nama' => $berkas->nama_dokumen,
                    'detail' => "Tidak ada lagi di template, tetap disimpan karena statusnya \"{$berkas->status_label}\"", 'data' => []];
            }
        }

        return $butir;
    }

    /** Terapkan rencana ke database (satu transaksi DB). Mengembalikan jumlah per aksi. */
    public static function terapkan(KavlingKonsumen $kk): array
    {
        $butir = self::rencana($kk);
        DB::transaction(function () use ($butir, $kk) {
            foreach ($butir as $b) {
                match ($b['aksi']) {
                    'tambah' => DokumenKonsumen::create(['kavling_konsumen_id' => $kk->id] + DokumenKonsumen::atributDariTemplate(DokumenTemplate::find($b['template_id']), $kk->konsumen)),
                    'ubah', 'tautan' => DokumenKonsumen::whereKey($b['berkas_id'])->update($b['data']),
                    'hapus'  => DokumenKonsumen::whereKey($b['berkas_id'])->delete(),
                    default  => null,
                };
            }
        });
        $kk->unsetRelation('dokumens');

        return self::hitung($butir);
    }

    /** Ringkasan jumlah per aksi. */
    public static function hitung(array $butir): array
    {
        $per = collect($butir)->countBy('aksi');

        return ['tambah' => $per['tambah'] ?? 0, 'ubah' => $per['ubah'] ?? 0, 'hapus' => $per['hapus'] ?? 0, 'pertahankan' => $per['pertahankan'] ?? 0];
    }

    /**
     * Hitung ulang HANYA kolom `berlaku` untuk berkas kondisional ber-syarat, setelah data konsumen (status pernikahan /
     * pekerjaan) berubah. Tidak menambah/menghapus berkas apa pun. Mengembalikan jumlah berkas yang berubah.
     */
    public static function segarkanBerlaku(KavlingKonsumen $kk): int
    {
        $kk->loadMissing('konsumen', 'dokumens.template');
        $berubah = 0;
        foreach ($kk->dokumens as $berkas) {
            $baru = $berkas->template?->berlakuUntuk($kk->konsumen);
            if ($berkas->sifat === 'kondisional' && $baru !== null && $berkas->berlaku !== $baru) {
                $berkas->update(['berlaku' => $baru]);
                $berubah++;
            }
        }

        return $berubah;
    }

    /**
     * Cocokkan berkas dengan template: pertama lewat dokumen_template_id, sisanya lewat nama (tanpa beda huruf besar/kecil).
     * Mengembalikan [pasangan [berkas, template], berkas tanpa template, template tanpa berkas].
     */
    private static function cocokkan(Collection $berkas, Collection $templates): array
    {
        $pasangan = [];
        $templateSisa = $templates->keyBy('id');
        $berkasSisa = collect();

        foreach ($berkas as $b) {
            if ($b->dokumen_template_id && $templateSisa->has($b->dokumen_template_id)) {
                $pasangan[] = [$b, $templateSisa->pull($b->dokumen_template_id)];
            } else {
                $berkasSisa->push($b);
            }
        }

        $berkasTanpa = collect();
        foreach ($berkasSisa as $b) {
            $kunci = mb_strtolower(trim($b->nama_dokumen));
            $t = $templateSisa->first(fn ($t) => mb_strtolower(trim($t->nama_dokumen)) === $kunci);
            if ($t) {
                $pasangan[] = [$b, $templateSisa->pull($t->id)];
            } else {
                $berkasTanpa->push($b);
            }
        }

        return [$pasangan, $berkasTanpa, $templateSisa->values()];
    }

    /** Selisih antara berkas dan templatenya: ['teks' => [...], 'data' => kolom yang diperbarui] atau null bila sama. */
    private static function perubahan(DokumenKonsumen $berkas, DokumenTemplate $template, KavlingKonsumen $kk): ?array
    {
        $teks = [];
        $data = [];
        if ($berkas->dokumen_template_id !== $template->id) $data['dokumen_template_id'] = $template->id;       // tautan saja, tidak dilaporkan
        if ($berkas->nama_dokumen !== $template->nama_dokumen) {
            $teks[] = "Nama \"{$berkas->nama_dokumen}\" → \"{$template->nama_dokumen}\"";
            $data['nama_dokumen'] = $template->nama_dokumen;
        }
        if ($berkas->sifat !== $template->sifat) {
            $teks[] = "Sifat {$berkas->sifat_label} → {$template->sifat_label}";
            $data['sifat'] = $template->sifat;
            $data['berlaku'] = $template->berlakuUntuk($kk->konsumen) ?? true;
        } else {
            $baru = $template->berlakuUntuk($kk->konsumen);
            if ($baru !== null && $berkas->berlaku !== $baru) {
                $teks[] = $baru ? 'Sekarang berlaku untuk konsumen ini' : 'Tidak berlaku untuk konsumen ini';
                $data['berlaku'] = $baru;
            }
        }

        return $data ? ['teks' => $teks, 'data' => $data] : null;
    }
}
