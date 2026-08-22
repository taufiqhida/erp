<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skema_dp_presets', function (Blueprint $table) {
            // Dasar hitung kalau tipe = persen. Default disamakan dengan
            // perilaku yang sudah berjalan: booking fee dari harga dasar,
            // DP dari harga jual netto (dasar + biaya tambahan - diskon).
            $table->enum('booking_fee_basis', ['harga_dasar', 'harga_netto'])
                ->default('harga_dasar')->after('booking_fee_masuk_harga_jual');
            $table->enum('dp_basis', ['harga_dasar', 'harga_netto'])
                ->default('harga_netto')->after('dp_masuk_harga_jual');
        });
    }

    public function down(): void
    {
        Schema::table('skema_dp_presets', function (Blueprint $table) {
            $table->dropColumn(['booking_fee_basis', 'dp_basis']);
        });
    }
};
