<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan promo pada Titipan Biaya Akad (lawan promo baru "titipan_biaya_akad"; kolom jenis di promo_preset_lawan berupa teks, jadi tanpa
 * perubahan struktur di sana). Pola sama dengan Kelebihan Tanah: titipan_biaya_akad_nominal tetap BRUTO (Program All In dikurangi
 * Booking Fee/DP yang sudah tercakup), potongan disimpan terpisah, dan yang ditagih = bruto - potongan. Transaksi lama tidak berubah
 * (potongan 0). Dipakai juga untuk kasus konsumen yang memakai notaris sendiri: potongan nominal sebesar biaya notaris + catatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->decimal('titipan_biaya_akad_potongan', 15, 2)->default(0)->after('titipan_biaya_akad_nominal');
            $table->string('titipan_biaya_akad_potongan_mode', 10)->nullable()->after('titipan_biaya_akad_potongan');
            $table->decimal('titipan_biaya_akad_potongan_nilai', 15, 2)->nullable()->after('titipan_biaya_akad_potongan_mode');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn(['titipan_biaya_akad_potongan', 'titipan_biaya_akad_potongan_mode', 'titipan_biaya_akad_potongan_nilai']);
        });
    }
};
