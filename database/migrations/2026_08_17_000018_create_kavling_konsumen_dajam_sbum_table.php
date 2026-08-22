<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Rincian Biaya Akad" — item Dana Jaminan/SBUM/Biaya Akad yang dipilih untuk
 * satu transaksi konsumen, dengan nominal override per konsumen (preset
 * globalnya sengaja tanpa nominal, lihat dajam_sbum_presets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kavling_konsumen_dajam_sbum', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->foreignId('dajam_sbum_preset_id')->nullable()
                ->constrained('dajam_sbum_presets')->nullOnDelete();
            // Snapshot nama & kategori saat dipilih, supaya histori aman kalau
            // preset di master data diubah/dihapus belakangan.
            $table->string('nama', 150);
            $table->enum('kategori', ['dajam', 'sbum', 'biaya_akad']);
            $table->decimal('nominal', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kavling_konsumen_dajam_sbum');
    }
};
