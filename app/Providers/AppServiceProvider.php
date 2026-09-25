<?php

namespace App\Providers;

use App\Models\JadwalTagihan;
use App\Models\KavlingKonsumen;
use App\Models\KavlingKonsumenBiayaTambahan;
use App\Models\KavlingKonsumenDajamSbum;
use App\Models\PembayaranKonsumen;
use App\Models\PencairanKprTahap;
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
        KavlingKonsumen::saved(function (KavlingKonsumen $kk) {
            if ($kk->wasRecentlyCreated || $kk->wasChanged(KavlingKonsumen::FINANCE_TRIGGER_FIELDS)) {
                KavlingKonsumen::refreshFinanceFor($kk->id);
            }
        });
    }
}
