<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pencairan KPR (uang cair dari bank ke developer) sebelumnya cuma angka
 * hasil hitung, belum ada tempat mencatat "sudah cair berapa" — beda dari
 * Booking Fee/DP yang punya jadwal_tagihan, bank tidak ikut skema/tenor
 * preset apa pun jadi baris di sini murni ditambahkan manual oleh admin
 * tiap kali bank benar-benar mencairkan (bisa berkali-kali/bertahap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pencairan_kpr_tahap', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->decimal('nominal', 15, 2);
            $table->date('tanggal_cair');
            $table->string('keterangan', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pencairan_kpr_tahap');
    }
};
