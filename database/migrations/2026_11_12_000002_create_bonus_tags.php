<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus saat booking (tandon, kipas, AC, dan sebagainya): label tanpa nominal dan tanpa dampak ke keuangan. Master berisi nama saja;
 * satu transaksi boleh memilih banyak bonus. Berbeda dari promo, bonus tidak memotong biaya apa pun. Hanya menambah tabel baru.
 * Bonus yang sudah dipakai transaksi tidak bisa dihapus (nonaktifkan saja), supaya riwayat tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_presets', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('keterangan', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });

        Schema::create('kavling_konsumen_bonus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->foreignId('bonus_preset_id')->constrained('bonus_presets')->restrictOnDelete();
            $table->timestamps();

            // Nama indeks dipendekkan: nama otomatis melebihi batas 64 karakter MySQL.
            $table->unique(['kavling_konsumen_id', 'bonus_preset_id'], 'kk_bonus_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kavling_konsumen_bonus');
        Schema::dropIfExists('bonus_presets');
    }
};
