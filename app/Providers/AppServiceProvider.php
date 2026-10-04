<?php

namespace App\Providers;

use App\Models\CancellationRequest;
use App\Models\JadwalTagihan;
use App\Models\KavlingKonsumen;
use App\Models\KavlingKonsumenBiayaTambahan;
use App\Models\KavlingKonsumenDajamSbum;
use App\Models\PembayaranKonsumen;
use App\Models\PencairanKprTahap;
use App\Support\FinanceCache;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Total keuangan tersimpan (kolom fin_* di kavling_konsumen) dihitung ulang tiap
        // ada perubahan pada sumbernya — lihat App\Models\Concerns\HasFinanceTotals.
        $refresh = fn ($model) => KavlingKonsumen::refreshFinanceFor($model->kavling_konsumen_id);
        foreach ([PembayaranKonsumen::class, JadwalTagihan::class, KavlingKonsumenBiayaTambahan::class,
                  KavlingKonsumenDajamSbum::class, PencairanKprTahap::class] as $child) {
            $child::saved($refresh);
            $child::deleted($refresh);
        }
        // Cache ringkasan keuangan Dashboard (lihat App\Support\FinanceCache) ikut kedaluwarsa
        // tiap ada perubahan transaksi/pembatalan; perubahan anak sudah tercakup refreshFinance().
        KavlingKonsumen::saved(fn () => FinanceCache::bump());
        KavlingKonsumen::deleted(fn () => FinanceCache::bump());
        CancellationRequest::saved(fn () => FinanceCache::bump());
        CancellationRequest::deleted(fn () => FinanceCache::bump());
        KavlingKonsumen::saved(function (KavlingKonsumen $kk) {
            if ($kk->wasRecentlyCreated || $kk->wasChanged(KavlingKonsumen::FINANCE_TRIGGER_FIELDS)) {
                KavlingKonsumen::refreshFinanceFor($kk->id);
            }
        });
    }
}
