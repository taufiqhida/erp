<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap terakhir transaksi saat dibatalkan (booking, pemberkasan, proses_bank, sp3k, rencana_akad, akad, bast). Sebelumnya hilang karena
 * status transaksi langsung berubah menjadi "batal". Diisi saat pembatalan disetujui, atau dari kolom "Tahap Terakhir" saat import.
 * Data lama tetap kosong (tidak bisa direkonstruksi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->string('tahap_batal', 30)->nullable()->after('kavling_status_before');
        });
    }

    public function down(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->dropColumn('tahap_batal');
        });
    }
};
