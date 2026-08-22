<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire "Pembiayaan Proyek" (config komponen KPR per-project dengan nominal
 * tetap) — digantikan "Dana Jaminan & SBUM" (library global tanpa nominal,
 * lihat migration create_dajam_sbum_presets_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pembiayaan_proyeks');
    }

    public function down(): void
    {
        Schema::create('pembiayaan_proyeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete()->unique();
            $table->json('kpr_subsidi_config')->nullable();
            $table->json('kpr_komersil_config')->nullable();
            $table->timestamps();
        });
    }
};
