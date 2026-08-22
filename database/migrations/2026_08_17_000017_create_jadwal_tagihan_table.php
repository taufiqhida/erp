<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Kartu Piutang" — jadwal tagihan/angsuran booking fee & DP, di-generate
 * otomatis saat booking dari tenor preset Skema Pembayaran yang dipilih.
 * Terpisah dari pembayaran_konsumens (catatan pembayaran AKTUAL) karena ini
 * representasi RENCANA ke depan — begitu dibayar, ditautkan ke baris
 * pembayaran_konsumens yang melunasinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_tagihan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->enum('jenis', ['booking_fee', 'dp']);
            $table->unsignedSmallInteger('nomor_cicilan');
            $table->decimal('jumlah', 15, 2);
            $table->date('tanggal_jatuh_tempo');
            $table->enum('status', ['belum_bayar', 'lunas'])->default('belum_bayar');
            $table->foreignId('pembayaran_konsumen_id')->nullable()
                ->constrained('pembayaran_konsumens')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_tagihan');
    }
};
