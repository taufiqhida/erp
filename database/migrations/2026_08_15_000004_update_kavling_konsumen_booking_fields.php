<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->decimal('booking_fee', 15, 2)->nullable()->after('metode_bayar');
            $table->enum('cara_bayar', ['cash', 'cash_bertahap', 'kpr_subsidi', 'kpr_komersil'])->nullable()->after('booking_fee');
            $table->unsignedSmallInteger('cicilan_kali')->nullable()->after('cara_bayar')->comment('Jumlah cicilan jika cash bertahap');
            $table->string('skema_dp', 255)->nullable()->after('cicilan_kali')->comment('Format: tanpa_dp | nominal:50000000 | persen:10:3x');
            $table->decimal('plafon_kpr', 15, 2)->nullable()->after('skema_dp');
            $table->enum('status_penjualan', [
                'booking', 'pemberkasan', 'sp3k', 'rencana_akad', 'akad', 'batal'
            ])->default('booking')->after('plafon_kpr');
            $table->date('tanggal_rencana_akad')->nullable()->after('status_penjualan');
            $table->decimal('realisasi_cair', 15, 2)->nullable()->after('tanggal_rencana_akad')->comment('Pencairan KPR netto dari bank');
            $table->decimal('dajam_ditahan', 15, 2)->nullable()->after('realisasi_cair')->comment('Dana jaminan yang ditahan bank');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn([
                'booking_fee', 'cara_bayar', 'cicilan_kali', 'skema_dp',
                'plafon_kpr', 'status_penjualan', 'tanggal_rencana_akad',
                'realisasi_cair', 'dajam_ditahan',
            ]);
        });
    }
};
