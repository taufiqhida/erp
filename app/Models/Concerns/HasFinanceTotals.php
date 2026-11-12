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
        'biaya_kelebihan_tanah_aktif', 'biaya_kelebihan_tanah_nominal', 'biaya_kelebihan_tanah_potongan', 'total_biaya_tambahan',
        'titipan_biaya_akad_nominal', 'titipan_biaya_akad_potongan', 'diskon_nominal',
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

        // Jalur event (pembayaran/jadwal/rincian/pencairan berubah): transaksi Selesai yang tak lagi lunas dibuka kembali.
        static::query()->find($id)?->refreshFinance(tinjauSelesai: true);
    }

    /**
     * @param  bool  $tinjauSelesai  true hanya dari jalur event (perubahan data). Perintah massal (finance:recompute, perbaikan data)
     *                               sengaja TIDAK mengubah status, supaya penghitungan ulang tidak membuka transaksi diam-diam.
     */
    public function refreshFinance(bool $tinjauSelesai = false): void
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

        \App\Support\FinanceCache::bump();

        foreach ($values as $key => $value) $this->setAttribute($key, $value);
        $this->syncOriginalAttributes(array_keys($values));

        if ($tinjauSelesai) $this->bukaKembaliBilaTakLunas($b);
    }

    /**
     * Status Selesai (completed) mengunci transaksi dan sebelumnya satu arah: setelah pembayaran/pencairan/rincian dikoreksi lewat
     * override, tidak ada yang meninjau ulang. Kini transaksi Selesai yang hasil koreksinya tidak lagi lunas otomatis kembali
     * berjalan (active) dan kuncinya lepas, supaya Keuangan bisa melanjutkan pencatatan lalu menandai Selesai lagi bila sudah tuntas.
     * Dicatat di activity log (Audit Trail) beserta nominal sisanya.
     */
    private function bukaKembaliBilaTakLunas(array $b): void
    {
        if ($this->status !== 'completed' || static::lunasPenuh($b)) return;

        $sisaKonsumen = round(max(0, $b['total_piutang_konsumen'] - $b['total_terbayar_konsumen']), 2);
        $sisaBank = round(max(0, $b['total_piutang_bank'] - $b['total_terbayar_bank']), 2);

        static::query()->whereKey($this->getKey())->update(['status' => 'active']);
        $this->setAttribute('status', 'active');
        $this->syncOriginalAttributes(['status']);

        $rp = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
        $rincian = collect([$sisaKonsumen > 0.5 ? "sisa konsumen {$rp($sisaKonsumen)}" : null, $sisaBank > 0.5 ? "sisa bank {$rp($sisaBank)}" : null])->filter()->implode(', ');
        $pesan = "Status Selesai dibuka kembali otomatis karena belum lunas ({$rincian}).";

        activity('transaksi')->performedOn($this)->causedBy(auth()->user())
            ->withProperties(['sisa_konsumen' => $sisaKonsumen, 'sisa_bank' => $sisaBank])->log($pesan);

        // Beri tahu pengguna yang sedang bekerja (toast), bila ini terjadi di dalam permintaan web.
        if (app()->bound('request') && request()->hasSession()) {
            request()->session()->flash('warning', "Transaksi dibuka kembali (tidak lagi Selesai): {$rincian}. Lengkapi pembayaran lalu tandai Selesai lagi.");
        }
    }
}
