<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alamat Domisili terpisah dari Alamat KTP. Kolom `alamat` + wilayah yang sudah ada = alamat KTP.
 * domisili_sama_ktp: null = belum diisi (data lama tidak ditebak), true = sama dengan KTP, false = berbeda (kolom domisili_* dipakai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsumens', function (Blueprint $table) {
            $table->boolean('domisili_sama_ktp')->nullable()->after('kelurahan_nama');
            $table->text('domisili_alamat')->nullable()->after('domisili_sama_ktp');
            $table->string('domisili_provinsi_kode', 16)->nullable()->after('domisili_alamat');
            $table->string('domisili_provinsi_nama', 120)->nullable()->after('domisili_provinsi_kode');
            $table->string('domisili_kabupaten_kode', 16)->nullable()->after('domisili_provinsi_nama');
            $table->string('domisili_kabupaten_nama', 120)->nullable()->after('domisili_kabupaten_kode');
            $table->string('domisili_kecamatan_kode', 16)->nullable()->after('domisili_kabupaten_nama');
            $table->string('domisili_kecamatan_nama', 120)->nullable()->after('domisili_kecamatan_kode');
            $table->string('domisili_kelurahan_kode', 16)->nullable()->after('domisili_kecamatan_nama');
            $table->string('domisili_kelurahan_nama', 120)->nullable()->after('domisili_kelurahan_kode');
            $table->index('domisili_kabupaten_kode');
        });
    }

    public function down(): void
    {
        Schema::table('konsumens', function (Blueprint $table) {
            $table->dropIndex(['domisili_kabupaten_kode']);
            $table->dropColumn(['domisili_sama_ktp', 'domisili_alamat', 'domisili_provinsi_kode', 'domisili_provinsi_nama', 'domisili_kabupaten_kode',
                'domisili_kabupaten_nama', 'domisili_kecamatan_kode', 'domisili_kecamatan_nama', 'domisili_kelurahan_kode', 'domisili_kelurahan_nama']);
        });
    }
};
