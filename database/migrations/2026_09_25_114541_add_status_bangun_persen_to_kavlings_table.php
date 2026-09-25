<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persen penyelesaian DI DALAM tahap bangun yang sedang dikerjakan (0-100).
 * Progress unit = bobot tahap-tahap sebelumnya + bobot tahap ini x persen / 100.
 * Data lama: tahap yang tercatat sebelumnya berarti "sudah selesai", jadi diisi 100
 * supaya angka progress tiap unit persis sama seperti sebelum kolom ini ada.
 * Tahap default ("Belum Mulai") tetap 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->decimal('status_bangun_persen', 5, 2)->default(0)->after('status_bangun_stage_id');
        });

        DB::table('kavlings')
            ->whereIn('status_bangun_stage_id', DB::table('status_bangun_stages')->where('is_default', false)->pluck('id'))
            ->update(['status_bangun_persen' => 100]);
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropColumn('status_bangun_persen');
        });
    }
};
