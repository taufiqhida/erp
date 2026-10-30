<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kategori baru "penyesuaian" untuk item "Selisih / penyesuaian bank": sisa retensi Dajam yang tidak akan dirinci/ditagih lagi.
 * Bukan Dajam dan bukan piutang bank, jadi dipisah dari kategori dajam supaya tidak tertagih selamanya atau tercatat sebagai uang masuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE kavling_konsumen_dajam_sbum MODIFY COLUMN kategori ENUM('dajam', 'sbum', 'penyesuaian') NOT NULL");

        // Item "Selisih / penyesuaian bank" yang sudah sempat dibuat sebagai Dajam biasa (belum dibayar) dipindah ke kategori baru.
        DB::table('kavling_konsumen_dajam_sbum')
            ->where('kategori', 'dajam')->whereNull('dajam_sbum_preset_id')->where('nama', 'Selisih / penyesuaian bank')->whereNull('pembayaran_konsumen_id')
            ->update(['kategori' => 'penyesuaian', 'status' => 'lunas']);
    }

    public function down(): void
    {
        DB::table('kavling_konsumen_dajam_sbum')->where('kategori', 'penyesuaian')->update(['kategori' => 'dajam', 'status' => 'belum_bayar']);
        DB::statement("ALTER TABLE kavling_konsumen_dajam_sbum MODIFY COLUMN kategori ENUM('dajam', 'sbum') NOT NULL");
    }
};
