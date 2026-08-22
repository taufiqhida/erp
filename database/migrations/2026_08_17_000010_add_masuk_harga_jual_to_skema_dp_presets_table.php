<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skema_dp_presets', function (Blueprint $table) {
            // Apakah nilai booking fee/DP ini mengurangi sisa harga jual yang harus
            // dilunasi (masuk hitungan harga jual), atau di luar itu (biaya terpisah).
            $table->boolean('booking_fee_masuk_harga_jual')->default(true)->after('booking_fee_tenor');
            $table->boolean('dp_masuk_harga_jual')->default(true)->after('dp_tenor');
        });
    }

    public function down(): void
    {
        Schema::table('skema_dp_presets', function (Blueprint $table) {
            $table->dropColumn(['booking_fee_masuk_harga_jual', 'dp_masuk_harga_jual']);
        });
    }
};
