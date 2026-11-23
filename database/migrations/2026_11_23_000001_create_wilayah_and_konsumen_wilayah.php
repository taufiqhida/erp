<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data wilayah Indonesia (provinsi > kabupaten/kota > kecamatan > kelurahan/desa) untuk alamat terstruktur konsumen.
 * Kode memakai format titik (32 / 32.73 / 32.73.01 / 32.73.01.1001). Isi tabel dimuat oleh migrasi berikutnya dari berkas CSV di repositori.
 * Konsumen menyimpan kode DAN nama tiap tingkat: analisis domisili tetap terbaca walau kode wilayah berubah (pemekaran).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wilayah', function (Blueprint $table) {
            $table->string('kode', 16)->primary();
            $table->unsignedTinyInteger('level');          // 1 provinsi, 2 kabupaten/kota, 3 kecamatan, 4 kelurahan/desa
            $table->string('induk', 16)->nullable();
            $table->string('nama', 120);
            $table->string('kode_pos', 8)->nullable();
            $table->index(['induk', 'nama']);
            $table->index(['level', 'nama']);
        });

        Schema::table('konsumens', function (Blueprint $table) {
            $table->string('provinsi_kode', 16)->nullable()->after('alamat');
            $table->string('provinsi_nama', 120)->nullable()->after('provinsi_kode');
            $table->string('kabupaten_kode', 16)->nullable()->after('provinsi_nama');
            $table->string('kabupaten_nama', 120)->nullable()->after('kabupaten_kode');
            $table->string('kecamatan_kode', 16)->nullable()->after('kabupaten_nama');
            $table->string('kecamatan_nama', 120)->nullable()->after('kecamatan_kode');
            $table->string('kelurahan_kode', 16)->nullable()->after('kecamatan_nama');
            $table->string('kelurahan_nama', 120)->nullable()->after('kelurahan_kode');
            $table->index('provinsi_kode');
            $table->index('kabupaten_kode');
        });
    }

    public function down(): void
    {
        Schema::table('konsumens', function (Blueprint $table) {
            $table->dropIndex(['provinsi_kode']);
            $table->dropIndex(['kabupaten_kode']);
            $table->dropColumn(['provinsi_kode', 'provinsi_nama', 'kabupaten_kode', 'kabupaten_nama', 'kecamatan_kode', 'kecamatan_nama', 'kelurahan_kode', 'kelurahan_nama']);
        });
        Schema::dropIfExists('wilayah');
    }
};
