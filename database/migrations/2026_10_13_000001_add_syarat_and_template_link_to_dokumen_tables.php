<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas kondisional: template punya syarat (status pernikahan / pekerjaan) dan tiap berkas konsumen mengingat
 * template asalnya + apakah "berlaku" untuk konsumen itu. Dasar dari Sinkronkan Berkas & progress yang hanya
 * menghitung berkas wajib (+ kondisional yang berlaku). Hanya menambah kolom, tidak mengubah data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dokumen_templates', function (Blueprint $table) {
            // Daftar kunci dipisah koma (mis. "menikah" atau "karyawan_swasta,wirausaha"); kosong = berlaku untuk semua / manual.
            $table->string('syarat_pernikahan', 255)->nullable()->after('sifat');
            $table->string('syarat_pekerjaan', 255)->nullable()->after('syarat_pernikahan');
        });

        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->foreignId('dokumen_template_id')->nullable()->after('kavling_konsumen_id')->constrained('dokumen_templates')->nullOnDelete();
            $table->boolean('berlaku')->default(true)->after('sifat');
        });

        // Hubungkan berkas konsumen yang sudah ada ke template-nya (cara bayar + nama sama).
        DB::statement('UPDATE dokumen_konsumens dk
            JOIN kavling_konsumen kk ON kk.id = dk.kavling_konsumen_id
            JOIN dokumen_templates dt ON dt.cara_bayar = kk.cara_bayar AND dt.nama_dokumen = dk.nama_dokumen
            SET dk.dokumen_template_id = dt.id');
    }

    public function down(): void
    {
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dokumen_template_id');
            $table->dropColumn('berlaku');
        });
        Schema::table('dokumen_templates', function (Blueprint $table) {
            $table->dropColumn(['syarat_pernikahan', 'syarat_pekerjaan']);
        });
    }
};
