<?php

namespace App\Support;

use App\Models\KavlingKonsumen;

/**
 * Peta tindak lanjut sisi bank untuk transaksi KPR yang masih berjalan: SBUM belum diinput (KPR Subsidi sudah akad) dan retensi Dajam yang
 * belum dirinci. Dihitung di PHP per transaksi (kartuPiutangBreakdown), jadi di-cache dan kedaluwarsa otomatis begitu data keuangan
 * berubah (FinanceCache). Dipakai daftar Pencairan KPR (penanda + filter) dan kartu peringatan Dashboard.
 */
class TindakLanjutBank
{
    /** @return array<int, array{perlu_sbum: bool, belum_dirinci: float, persen: float}> */
    public static function peta(): array
    {
        return FinanceCache::remember('tindak-lanjut-bank', function () {
            $peta = [];
            KavlingKonsumen::query()
                ->whereIn('cara_bayar', ['kpr_subsidi', 'kpr_komersil'])
                ->where('status', 'active')
                ->where('status_penjualan', '!=', 'batal')
                ->with(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
                ->chunkById(200, function ($semua) use (&$peta) {
                    foreach ($semua as $kk) {
                        $pk = $kk->kartuPiutangBreakdown()['pencairan_kpr'];
                        if (!$pk) continue;
                        $peta[$kk->id] = [
                            'perlu_sbum'    => (bool) $pk['perlu_sbum'],
                            'belum_dirinci' => $pk['perlu_rincian_dajam'] ? round($pk['belum_dirinci'], 2) : 0.0,
                            'persen'        => (float) $pk['persen_pencairan'],
                        ];
                    }
                });

            return $peta;
        });
    }

    /** @return int[] id transaksi KPR Subsidi sudah akad yang belum punya item SBUM. */
    public static function idPerluSbum(): array
    {
        return array_keys(array_filter(self::peta(), fn ($p) => $p['perlu_sbum']));
    }

    /** @return int[] id transaksi yang masih punya retensi Dajam belum dirinci (sudah akad). */
    public static function idDajamBelumDirinci(): array
    {
        return array_keys(array_filter(self::peta(), fn ($p) => $p['belum_dirinci'] > 0.5));
    }
}
