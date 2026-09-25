<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya Kelebihan Tanah, Tambahan Uang Muka, dan Titipan Biaya Akad sekarang
 * bisa dicicil berkali-kali — statusnya dihitung dari SUM seluruh baris
 * `pembayaran_konsumens` berjenis terkait (bukan disimpan), persis pola
 * Pencairan KPR Tahap. Field status & FK single-payment ini jadi tidak
 * terpakai lagi. Data pembayaran yang sudah ada TIDAK hilang — baris
 * pembayaran_konsumens-nya sendiri tetap ada & tetap ke-detect via kolom
 * `jenis`, cuma pointer single-nya yang dibuang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropForeign(['biaya_kelebihan_tanah_pembayaran_id']);
            $table->dropForeign(['tambahan_um_pembayaran_id']);
            $table->dropForeign(['titipan_biaya_akad_pembayaran_id']);
            $table->dropColumn([
                'biaya_kelebihan_tanah_status',
                'biaya_kelebihan_tanah_pembayaran_id',
                'tambahan_um_status',
                'tambahan_um_pembayaran_id',
                'titipan_biaya_akad_status',
                'titipan_biaya_akad_pembayaran_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->enum('biaya_kelebihan_tanah_status', ['belum_bayar', 'sebagian', 'lunas'])
                ->default('belum_bayar')->after('biaya_kelebihan_tanah_nominal');
            $table->foreignId('biaya_kelebihan_tanah_pembayaran_id')->nullable()
                ->after('biaya_kelebihan_tanah_status')->constrained('pembayaran_konsumens')->nullOnDelete();

            $table->enum('tambahan_um_status', ['belum_bayar', 'sebagian', 'lunas'])
                ->default('belum_bayar')->after('biaya_kelebihan_tanah_pembayaran_id');
            $table->foreignId('tambahan_um_pembayaran_id')->nullable()
                ->after('tambahan_um_status')->constrained('pembayaran_konsumens')->nullOnDelete();

            $table->enum('titipan_biaya_akad_status', ['belum_bayar', 'sebagian', 'lunas'])
                ->default('belum_bayar')->after('titipan_biaya_akad_nominal');
            $table->foreignId('titipan_biaya_akad_pembayaran_id')->nullable()
                ->after('titipan_biaya_akad_status')->constrained('pembayaran_konsumens')->nullOnDelete();
        });
    }
};
