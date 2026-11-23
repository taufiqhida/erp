<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Memuat data wilayah dari database/data/wilayah.csv.gz ke tabel `wilayah` (sekali, otomatis saat deploy). Dilewati saat menjalankan test
 * (test yang butuh wilayah membuat data contohnya sendiri) dan bila berkas tidak ada. Memperbarui data di kemudian hari: `php artisan wilayah:impor`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) return;
        if (!is_file(database_path('data/wilayah.csv.gz'))) return;

        Artisan::call('wilayah:impor');
    }

    public function down(): void
    {
        // data referensi: dihapus bersama tabelnya oleh migrasi sebelumnya
    }
};
