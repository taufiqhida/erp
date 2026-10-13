<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alih Pembeli: pembeli sebuah transaksi diganti (mis. konsumen A tak lolos BI Checking lalu UTJ dialihkan ke saudaranya B)
 * tanpa membatalkan transaksi — UTJ, kartu piutang, dan pembayaran tetap pada transaksi yang sama. Hanya menambah struktur:
 * jenis pengajuan baru, kolom tujuan pada pengajuan, penanda berkas yang diarsipkan, dan tabel riwayat peralihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE cancellation_requests MODIFY type ENUM('cancellation', 'unit_swap', 'buyer_transfer') NOT NULL DEFAULT 'cancellation'");

        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->foreignId('konsumen_baru_id')->nullable()->after('kavling_baru_id')->constrained('konsumens')->nullOnDelete();
            $table->string('hubungan', 60)->nullable()->after('alasan');
        });

        // Berkas pembeli lama tidak dihapus: diarsipkan (tidak tampil di daftar/progress) dengan penanda milik siapa.
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->timestamp('diarsipkan_at')->nullable();
            $table->foreignId('diarsipkan_untuk_konsumen_id')->nullable()->constrained('konsumens')->nullOnDelete();
        });

        Schema::create('alih_pembeli_riwayats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->foreignId('dari_konsumen_id')->nullable()->constrained('konsumens')->nullOnDelete();
            $table->foreignId('ke_konsumen_id')->nullable()->constrained('konsumens')->nullOnDelete();
            $table->string('hubungan', 60)->nullable();
            $table->text('alasan');
            $table->string('tahap_sebelum', 30)->nullable();      // status_penjualan saat dialihkan
            $table->foreignId('cancellation_request_id')->nullable()->constrained('cancellation_requests')->nullOnDelete();
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disetujui_at')->nullable();
            $table->timestamps();
            $table->index('dari_konsumen_id');
            $table->index('ke_konsumen_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alih_pembeli_riwayats');
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diarsipkan_untuk_konsumen_id');
            $table->dropColumn('diarsipkan_at');
        });
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('konsumen_baru_id');
            $table->dropColumn('hubungan');
        });
        DB::statement("ALTER TABLE cancellation_requests MODIFY type ENUM('cancellation', 'unit_swap') NOT NULL DEFAULT 'cancellation'");
    }
};
