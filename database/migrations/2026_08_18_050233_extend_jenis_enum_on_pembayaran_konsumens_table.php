<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kartu Piutang sekarang mencakup lebih dari sekadar booking_fee/dp/angsuran/
 * pelunasan — biaya tanah, biaya tambahan, SBUM, dana jaminan, dan biaya akad
 * juga perlu bisa dicatat pembayarannya lewat tabel yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE pembayaran_konsumens MODIFY COLUMN jenis ENUM('booking_fee', 'dp', 'angsuran', 'pelunasan', 'biaya_tanah', 'biaya_tambahan', 'sbum', 'dajam', 'biaya_akad') NOT NULL DEFAULT 'dp'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pembayaran_konsumens MODIFY COLUMN jenis ENUM('booking_fee', 'dp', 'angsuran', 'pelunasan') NOT NULL DEFAULT 'dp'");
    }
};
