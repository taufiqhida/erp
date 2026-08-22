<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cash & Cash Bertahap sekarang juga generate jadwal tagihan (bukan cuma
 * booking_fee/dp) — Pelunasan dicicil sesuai cicilan_kali persis kayak
 * DP, supaya "1 kali input pembayaran = langsung lunas semua" gak lagi
 * kejadian buat cash bertahap yg 12x tenor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE jadwal_tagihan MODIFY COLUMN jenis ENUM('booking_fee', 'dp', 'pelunasan') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE jadwal_tagihan MODIFY COLUMN jenis ENUM('booking_fee', 'dp') NOT NULL");
    }
};
