<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pencatatan pembayaran sekarang tidak lagi biner (belum_bayar/lunas) —
 * admin bisa input nominal kurang dari nominal yang harus dibayar (human
 * error, atau memang baru bayar sebagian), status jadi 'sebagian' sampai
 * kumulatif jumlahnya pas/lebih dari nominal yang harus dibayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE jadwal_tagihan MODIFY COLUMN status ENUM('belum_bayar', 'sebagian', 'lunas') NOT NULL DEFAULT 'belum_bayar'");
        DB::statement("ALTER TABLE kavling_konsumen MODIFY COLUMN biaya_kelebihan_tanah_status ENUM('belum_bayar', 'sebagian', 'lunas') NOT NULL DEFAULT 'belum_bayar'");
        DB::statement("ALTER TABLE kavling_konsumen_biaya_tambahan MODIFY COLUMN status ENUM('belum_bayar', 'sebagian', 'lunas') NOT NULL DEFAULT 'belum_bayar'");
        DB::statement("ALTER TABLE kavling_konsumen_dajam_sbum MODIFY COLUMN status ENUM('belum_bayar', 'sebagian', 'lunas') NOT NULL DEFAULT 'belum_bayar'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE jadwal_tagihan MODIFY COLUMN status ENUM('belum_bayar', 'lunas') NOT NULL DEFAULT 'belum_bayar'");
        DB::statement("ALTER TABLE kavling_konsumen MODIFY COLUMN biaya_kelebihan_tanah_status ENUM('belum_bayar', 'lunas') NOT NULL DEFAULT 'belum_bayar'");
        DB::statement("ALTER TABLE kavling_konsumen_biaya_tambahan MODIFY COLUMN status ENUM('belum_bayar', 'lunas') NOT NULL DEFAULT 'belum_bayar'");
        DB::statement("ALTER TABLE kavling_konsumen_dajam_sbum MODIFY COLUMN status ENUM('belum_bayar', 'lunas') NOT NULL DEFAULT 'belum_bayar'");
    }
};
