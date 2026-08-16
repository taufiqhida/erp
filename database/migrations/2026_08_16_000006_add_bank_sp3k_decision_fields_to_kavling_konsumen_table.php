<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            // Proses Bank / SLIK
            $table->date('tanggal_pengajuan_bank')->nullable()->after('status_penjualan');
            $table->date('tanggal_keputusan_bank')->nullable()->after('tanggal_pengajuan_bank');
            $table->enum('status_bank', ['diajukan', 'disetujui', 'ditolak'])->nullable()->after('tanggal_keputusan_bank');
            $table->text('catatan_bank')->nullable()->after('status_bank');

            // SP3K
            $table->date('tanggal_disetujui_sp3k')->nullable()->after('tanggal_expired_sp3k');
            $table->enum('status_sp3k', ['approved', 'turun_plafon', 'ditolak'])->nullable()->after('tanggal_disetujui_sp3k');
            $table->text('catatan_sp3k')->nullable()->after('status_sp3k');
            $table->boolean('rekening_kpr_dibuka')->default(false)->after('catatan_sp3k');
            $table->boolean('biaya_akad_lunas')->default(false)->after('rekening_kpr_dibuka');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn([
                'tanggal_pengajuan_bank', 'tanggal_keputusan_bank', 'status_bank', 'catatan_bank',
                'tanggal_disetujui_sp3k', 'status_sp3k', 'catatan_sp3k', 'rekening_kpr_dibuka', 'biaya_akad_lunas',
            ]);
        });
    }
};
