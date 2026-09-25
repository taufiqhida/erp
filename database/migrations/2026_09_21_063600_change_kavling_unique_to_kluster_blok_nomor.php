<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas unit = kluster + blok + nomor (kluster boleh kosong), bukan lagi
 * nomor saja. kluster_key/blok_key adalah kolom turunan yang mengubah NULL
 * jadi '' supaya unit tanpa kluster tetap kena pengecekan duplikat (MySQL
 * menganggap tiap NULL berbeda). Indeks baru dibuat sebelum yang lama
 * dilepas karena FK project_id bersandar pada indeks tersebut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->string('kluster_key', 50)->storedAs("COALESCE(kluster, '')")->after('kluster');
            $table->string('blok_key', 10)->storedAs("COALESCE(blok, '')")->after('blok');
        });

        Schema::table('kavlings', function (Blueprint $table) {
            $table->unique(['project_id', 'kluster_key', 'blok_key', 'nomor_kavling'], 'kavlings_identitas_unit_unique');
        });

        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropUnique('kavlings_project_id_nomor_kavling_unique');
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->unique(['project_id', 'nomor_kavling']);
        });

        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropUnique('kavlings_identitas_unit_unique');
            $table->dropColumn(['kluster_key', 'blok_key']);
        });
    }
};
