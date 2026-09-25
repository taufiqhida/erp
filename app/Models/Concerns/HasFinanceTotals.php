<?php

namespace App\Models\Concerns;

/**
 * Total keuangan tersimpan di kavling_konsumen (kolom fin_*) — turunan dari
 * kartuPiutangBreakdown(), dipakai halaman Keuangan untuk sort/filter/paginasi
 * di SQL. Bukan sumber kebenaran: rumusnya tetap satu, di kartuPiutangBreakdown().
 *
 * Dihitung ulang otomatis lewat event model (lihat AppServiceProvider) tiap
 * pembayaran/jadwal/rincian/pencairan atau field keuangan transaksi berubah.
 * Untuk operasi yang membuat banyak baris sekaligus (booking, import) bungkus
 * dengan withoutFinanceRefresh() supaya dihitung sekali di akhir, bukan per baris.
 * Kalau ada jalur simpan yang melewati event model (update/hapus massal),
 * panggil refreshFinance() manual, atau jalankan `php artisan finance:recompute`.
 */
trait HasFinanceTotals
{
    private static int $financeSuspended = 0;

    /** @var array<int, true> */
    private static array $financePending = [];

    public const FINANCE_TRIGGER_FIELDS = [
        'harga_dasar', 'harga_deal', 'booking_fee', 'cara_bayar', 'skema_dp_preset_id', 'plafon_kpr',
        'biaya_kelebihan_tanah_aktif', 'biaya_kelebihan_tanah_nominal', 'total_biaya_tambahan',
        'titipan_biaya_akad_nominal', 'diskon_nominal',
    ];

    public static function withoutFinanceRefresh(callable $callback)
    {
        static::$financeSuspended++;
        try {
            return $callback();
        } finally {
            static::$financeSuspended--;
            if (static::$financeSuspended === 0) {
                $ids = array_keys(static::$financePending);
                static::$financePending = [];
                foreach ($ids as $id) static::refreshFinanceFor($id);
            }
        }
    }

    public static function refreshFinanceFor(?int $id): void
    {
        if (!$id) return;

        if (static::$financeSuspended > 0) {
            static::$financePending[$id] = true;
            return;
        }

        static::query()->find($id)?->refreshFinance();
    }

    public function refreshFinance(): void
    {
        $this->unsetRelations()->load([
            'jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran',
            'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps',
        ]);
        $b = $this->kartuPiutangBreakdown();

        $values = [
            'fin_piutang_konsumen'  => round($b['total_piutang_konsumen'], 2),
            'fin_terbayar_konsumen' => round($b['total_terbayar_konsumen'], 2),
            'fin_sisa_konsumen'     => round($b['total_piutang_konsumen'] - $b['total_terbayar_konsumen'], 2),
            'fin_piutang_bank'      => round($b['total_piutang_bank'], 2),
            'fin_terbayar_bank'     => round($b['total_terbayar_bank'], 2),
            'fin_sisa_bank'         => round($b['total_piutang_bank'] - $b['total_terbayar_bank'], 2),
            'fin_jatuh_tempo_berikutnya' => $this->jadwalTagihans->where('status', '!=', 'lunas')->min('tanggal_jatuh_tempo')?->format('Y-m-d'),
            'fin_updated_at'        => now(),
        ];

        // Query builder langsung: tidak memicu event model (tanpa rekursi & tanpa noise activity log).
        static::query()->whereKey($this->getKey())->update($values);

        foreach ($values as $key => $value) $this->setAttribute($key, $value);
        $this->syncOriginalAttributes(array_keys($values));
    }
}
