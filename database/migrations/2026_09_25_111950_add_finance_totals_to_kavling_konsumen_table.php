<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Total keuangan tersimpan per transaksi (hasil KavlingKonsumen::kartuPiutangBreakdown())
 * supaya halaman Keuangan bisa sort/filter/paginasi/jumlahkan langsung di SQL tanpa
 * menghitung Kartu Piutang tiap baris di PHP. Diisi & dijaga oleh
 * KavlingKonsumen::refreshFinance() (lihat AppServiceProvider untuk pemicunya) dan
 * perintah `php artisan finance:recompute`. Nilai turunan — bukan sumber kebenaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->decimal('fin_piutang_konsumen', 15, 2)->default(0);
            $table->decimal('fin_terbayar_konsumen', 15, 2)->default(0);
            $table->decimal('fin_sisa_konsumen', 15, 2)->default(0);
            $table->decimal('fin_piutang_bank', 15, 2)->default(0);
            $table->decimal('fin_terbayar_bank', 15, 2)->default(0);
            $table->decimal('fin_sisa_bank', 15, 2)->default(0);
            $table->date('fin_jatuh_tempo_berikutnya')->nullable();
            $table->timestamp('fin_updated_at')->nullable();

            $table->index(['status', 'fin_sisa_konsumen'], 'kk_fin_konsumen_idx');
            $table->index(['cara_bayar', 'status_penjualan', 'fin_sisa_bank'], 'kk_fin_bank_idx');
            $table->index('tanggal_akad');
            $table->index('tanggal_booking');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropIndex('kk_fin_konsumen_idx');
            $table->dropIndex('kk_fin_bank_idx');
            $table->dropIndex(['tanggal_akad']);
            $table->dropIndex(['tanggal_booking']);
            $table->dropColumn([
                'fin_piutang_konsumen', 'fin_terbayar_konsumen', 'fin_sisa_konsumen',
                'fin_piutang_bank', 'fin_terbayar_bank', 'fin_sisa_bank',
                'fin_jatuh_tempo_berikutnya', 'fin_updated_at',
            ]);
        });
    }
};
