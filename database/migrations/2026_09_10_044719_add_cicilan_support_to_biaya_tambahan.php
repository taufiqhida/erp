<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya Tambahan Lain (per-item, multi-preset) selama ini masih pola single-
 * payment (status + pembayaran_konsumen_id) — beda dari Biaya Tanah/Tambahan
 * UM/Titipan Biaya Akad yang sudah dipindah ke cicilan bebas lewat
 * pembayaran_konsumens (lihat migrasi remove_single_payment_link_columns_for_
 * cicilan_items). Bedanya di sini: satu transaksi bisa punya BANYAK item
 * Biaya Tambahan berbeda sekaligus, jadi jenis='biaya_tambahan' saja tidak
 * cukup buat tahu cicilan itu punya item yang mana — makanya perlu 1 kolom
 * FK baru (kavling_konsumen_biaya_tambahan_id) sebagai penanda item, tetap
 * tanpa tabel baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayaran_konsumens', function (Blueprint $table) {
            $table->foreignId('kavling_konsumen_biaya_tambahan_id')->nullable()
                ->after('kavling_konsumen_id')->constrained('kavling_konsumen_biaya_tambahan')->cascadeOnDelete();
        });

        // Backfill link lama (satu-satunya baris pembayaran per item, dari
        // pola single-payment) supaya riwayatnya tidak hilang begitu kolom
        // status/pembayaran_konsumen_id di bawah dihapus.
        DB::table('kavling_konsumen_biaya_tambahan')
            ->whereNotNull('pembayaran_konsumen_id')
            ->orderBy('id')
            ->get(['id', 'pembayaran_konsumen_id'])
            ->each(function ($item) {
                DB::table('pembayaran_konsumens')
                    ->where('id', $item->pembayaran_konsumen_id)
                    ->update(['kavling_konsumen_biaya_tambahan_id' => $item->id]);
            });

        Schema::table('kavling_konsumen_biaya_tambahan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pembayaran_konsumen_id');
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen_biaya_tambahan', function (Blueprint $table) {
            $table->enum('status', ['belum_bayar', 'sebagian', 'lunas'])->default('belum_bayar')->after('nominal');
            $table->foreignId('pembayaran_konsumen_id')->nullable()
                ->after('status')->constrained('pembayaran_konsumens')->nullOnDelete();
        });

        Schema::table('pembayaran_konsumens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kavling_konsumen_biaya_tambahan_id');
        });
    }
};
