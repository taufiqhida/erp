<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo per biaya. Sebelumnya satu transaksi hanya punya satu promo (kavling_konsumen.promo_preset_id) yang jadi "lawan" banyak biaya
 * sekaligus. Kini tiap baris biaya menyimpan promo yang memotongnya sendiri, supaya biaya yang berbeda boleh memakai promo yang
 * berbeda dan total biaya per promo bisa dilaporkan. Kolom promo_preset_id lama dipertahankan sebagai "Promo label" (preset tanpa
 * lawan, mis. Free Kanopi) dan sebagai cadangan bacaan untuk transaksi lama yang belum diisi (lihat perintah promo:isi-per-biaya).
 * Hanya menambah kolom kosong; tidak ada data lama yang diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->foreignId('diskon_promo_id')->nullable()->after('diskon_nominal')->constrained('promo_presets')->nullOnDelete();
            $table->foreignId('biaya_kelebihan_tanah_promo_id')->nullable()->after('biaya_kelebihan_tanah_potongan_nilai')->constrained('promo_presets')->nullOnDelete();
            $table->foreignId('titipan_biaya_akad_promo_id')->nullable()->after('titipan_biaya_akad_potongan_nilai')->constrained('promo_presets')->nullOnDelete();
        });

        Schema::table('kavling_konsumen_biaya_tambahan', function (Blueprint $table) {
            $table->foreignId('promo_preset_id')->nullable()->after('potongan_nilai')->constrained('promo_presets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen_biaya_tambahan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_preset_id');
        });

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diskon_promo_id');
            $table->dropConstrainedForeignId('biaya_kelebihan_tanah_promo_id');
            $table->dropConstrainedForeignId('titipan_biaya_akad_promo_id');
        });
    }
};
