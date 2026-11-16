<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program All In: nominal DP yang dikurangkan bisa DITETAPKAN di preset (mis. All In 10,5 jt selalu "termasuk DP 1,66 jt", apa pun
 * DP skema yang dipilih — penting untuk cash yang pengikatannya 10 jt). Kosong = perilaku lama (ikut DP skema bila include_dp aktif).
 * Tidak mengubah data apa pun: semua preset lama tetap kosong dan Titipan Biaya Akad transaksi lama sudah dikunci saat booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_all_in_presets', function (Blueprint $table) {
            $table->decimal('dp_nominal_tetap', 15, 2)->nullable()->after('include_dp');
        });
    }

    public function down(): void
    {
        Schema::table('program_all_in_presets', function (Blueprint $table) {
            $table->dropColumn('dp_nominal_tetap');
        });
    }
};
