<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usulan Perubahan Rincian Pemesanan (oleh Admin Sales, disetujui SPV/Leader): jenis pengajuan baru dengan isi usulan disimpan
 * sebagai JSON (`payload`) dan baru diterapkan saat disetujui. Hanya menambah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE cancellation_requests MODIFY type ENUM('cancellation', 'unit_swap', 'buyer_transfer', 'rincian_change') NOT NULL DEFAULT 'cancellation'");

        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('hubungan');
        });
    }

    public function down(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
        DB::statement("ALTER TABLE cancellation_requests MODIFY type ENUM('cancellation', 'unit_swap', 'buyer_transfer') NOT NULL DEFAULT 'cancellation'");
    }
};
