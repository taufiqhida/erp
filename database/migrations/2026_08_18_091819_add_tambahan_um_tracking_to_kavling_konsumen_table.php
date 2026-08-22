<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahan Uang Muka (muncul kalau bank turun plafon) sebelumnya cuma
 * angka tampilan di section Pencairan KPR — sekarang jadi baris Kartu
 * Piutang sungguhan (subjek Konsumen) yang bisa dicatat pembayarannya,
 * pola kolomnya sama seperti biaya_kelebihan_tanah_status/pembayaran_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->enum('tambahan_um_status', ['belum_bayar', 'sebagian', 'lunas'])->default('belum_bayar')->after('biaya_kelebihan_tanah_pembayaran_id');
            $table->foreignId('tambahan_um_pembayaran_id')->nullable()->after('tambahan_um_status')->constrained('pembayaran_konsumens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropForeign(['tambahan_um_pembayaran_id']);
            $table->dropColumn(['tambahan_um_status', 'tambahan_um_pembayaran_id']);
        });
    }
};
