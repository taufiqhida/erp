<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            // Dicatat sebagai informasi saja untuk sekarang — breakdown tenor &
            // pemilihan dajam/SBUM per transaksi menyusul di halaman database
            // konsumen (bagian pembayaran), di luar scope form booking saat ini.
            $table->foreignId('skema_dp_preset_id')->nullable()->after('skema_dp')
                ->constrained('skema_dp_presets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('skema_dp_preset_id');
        });
    }
};
