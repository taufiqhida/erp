<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan promo pada biaya (Kelebihan Tanah dan Biaya Tambahan). Preset promo menentukan "lawan" (biaya mana yang boleh dipotong,
 * tanpa nominal); nominal/persen potongan diisi per transaksi. Biaya disimpan BRUTO, potongan terpisah; yang ditagih = bruto - potongan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_preset_lawan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_preset_id')->constrained('promo_presets')->cascadeOnDelete();
            $table->string('jenis', 20);                                  // 'tanah' | 'biaya_tambahan'
            $table->foreignId('biaya_tambahan_preset_id')->nullable()->constrained('biaya_tambahan_presets')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['promo_preset_id', 'jenis', 'biaya_tambahan_preset_id'], 'promo_lawan_unik');
        });

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->decimal('biaya_kelebihan_tanah_potongan', 15, 2)->default(0)->after('biaya_kelebihan_tanah_nominal');
            $table->string('biaya_kelebihan_tanah_potongan_mode', 10)->nullable()->after('biaya_kelebihan_tanah_potongan');
            $table->decimal('biaya_kelebihan_tanah_potongan_nilai', 15, 2)->nullable()->after('biaya_kelebihan_tanah_potongan_mode');
        });

        Schema::table('kavling_konsumen_biaya_tambahan', function (Blueprint $table) {
            $table->decimal('potongan', 15, 2)->default(0)->after('nominal');
            $table->string('potongan_mode', 10)->nullable()->after('potongan');
            $table->decimal('potongan_nilai', 15, 2)->nullable()->after('potongan_mode');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen_biaya_tambahan', function (Blueprint $table) {
            $table->dropColumn(['potongan', 'potongan_mode', 'potongan_nilai']);
        });
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn(['biaya_kelebihan_tanah_potongan', 'biaya_kelebihan_tanah_potongan_mode', 'biaya_kelebihan_tanah_potongan_nilai']);
        });
        Schema::dropIfExists('promo_preset_lawan');
    }
};
