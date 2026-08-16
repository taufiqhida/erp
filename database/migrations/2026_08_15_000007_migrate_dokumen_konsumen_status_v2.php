<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menyamakan status dokumen_konsumens dengan vocabulary yang sudah dipakai
 * frontend (DokumenKonsumen/Show.vue) dan spec: belum_ada, sudah_ada,
 * perlu_revisi, ditolak. Skema lama (belum, ada, proses) tidak pernah
 * benar-benar konsisten end-to-end dengan frontend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->string('status', 20)->default('belum_ada')->change();
        });

        DB::table('dokumen_konsumens')->where('status', 'belum')->update(['status' => 'belum_ada']);
        DB::table('dokumen_konsumens')->where('status', 'ada')->update(['status' => 'sudah_ada']);
        DB::table('dokumen_konsumens')->where('status', 'proses')->update(['status' => 'perlu_revisi']);

        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->enum('status', ['belum_ada', 'sudah_ada', 'perlu_revisi', 'ditolak'])->default('belum_ada')->change();
        });
    }

    public function down(): void
    {
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->string('status', 20)->default('belum_ada')->change();
        });

        DB::table('dokumen_konsumens')->where('status', 'belum_ada')->update(['status' => 'belum']);
        DB::table('dokumen_konsumens')->where('status', 'sudah_ada')->update(['status' => 'ada']);
        DB::table('dokumen_konsumens')->whereIn('status', ['perlu_revisi', 'ditolak'])->update(['status' => 'proses']);

        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->enum('status', ['belum', 'ada', 'proses'])->default('belum')->change();
        });
    }
};
