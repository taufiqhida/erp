<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai SPK: satu angka untuk seluruh SPK (borongan), bukan per unit. Nullable supaya SPK lama yang belum punya nilai tetap valid
 * (tampil "-"); SPK baru diwajibkan mengisinya lewat validasi di aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spks', function (Blueprint $table) {
            $table->decimal('nilai_spk', 15, 2)->nullable()->after('tanggal_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('spks', function (Blueprint $table) {
            $table->dropColumn('nilai_spk');
        });
    }
};
