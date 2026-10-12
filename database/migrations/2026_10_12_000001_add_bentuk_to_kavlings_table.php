<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bentuk kavling di siteplan: poligon (daftar titik [x, y] dalam persen 0–100 dari lebar/tinggi gambar), hasil
 * "klik kotak lalu isi area" di editor tagging siteplan. Kosong = unit belum ditandai (tetap tampil sebagai titik
 * koordinat_x/koordinat_y seperti sebelumnya). Hanya menambah kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->json('bentuk')->nullable()->after('koordinat_y');
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropColumn('bentuk');
        });
    }
};
