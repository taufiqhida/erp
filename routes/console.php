<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jaring pengaman total keuangan tersimpan (kolom fin_*): hitung ulang semua tiap malam
// kalau ada jalur simpan yang terlewat event model. Butuh scheduler aktif di server
// (cron `php artisan schedule:run` tiap menit) — kalau belum, jalankan manual setelah import besar.
Illuminate\Support\Facades\Schedule::command('finance:recompute')->dailyAt('02:00');
