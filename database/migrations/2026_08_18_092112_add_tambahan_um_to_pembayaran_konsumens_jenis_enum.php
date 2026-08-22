<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambahan Uang Muka (turun plafon) sekarang jadi baris Kartu Piutang
 * sungguhan yang bisa dicatat pembayarannya, butuh jenis baru di
 * pembayaran_konsumens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE pembayaran_konsumens MODIFY COLUMN jenis ENUM('booking_fee', 'dp', 'angsuran', 'pelunasan', 'biaya_tanah', 'biaya_tambahan', 'sbum', 'dajam', 'biaya_akad', 'tambahan_um') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pembayaran_konsumens MODIFY COLUMN jenis ENUM('booking_fee', 'dp', 'angsuran', 'pelunasan', 'biaya_tanah', 'biaya_tambahan', 'sbum', 'dajam', 'biaya_akad') NOT NULL");
    }
};
