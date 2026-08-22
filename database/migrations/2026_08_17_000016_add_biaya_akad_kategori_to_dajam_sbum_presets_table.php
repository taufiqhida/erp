<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE dajam_sbum_presets MODIFY COLUMN kategori ENUM('dajam', 'sbum', 'biaya_akad') NOT NULL");
    }

    public function down(): void
    {
        DB::table('dajam_sbum_presets')->where('kategori', 'biaya_akad')->delete();
        DB::statement("ALTER TABLE dajam_sbum_presets MODIFY COLUMN kategori ENUM('dajam', 'sbum') NOT NULL");
    }
};
