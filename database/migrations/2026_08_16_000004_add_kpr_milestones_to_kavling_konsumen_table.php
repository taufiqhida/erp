<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->date('tanggal_sp3k')->nullable()->after('tanggal_rencana_akad');
            $table->date('tanggal_expired_sp3k')->nullable()->after('tanggal_sp3k')
                ->comment('Masa berlaku SP3K sesuai dokumen bank, diinput manual (tidak ada aturan universal)');
            $table->date('tanggal_bast')->nullable()->after('tanggal_akad');
        });

        // Tambah stage 'proses_bank' (antara pemberkasan & sp3k) dan 'bast' (setelah akad).
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->string('status_penjualan', 20)->default('booking')->change();
        });
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->enum('status_penjualan', [
                'booking', 'pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad', 'akad', 'bast', 'batal',
            ])->default('booking')->change();
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn(['tanggal_sp3k', 'tanggal_expired_sp3k', 'tanggal_bast']);
        });

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->string('status_penjualan', 20)->default('booking')->change();
        });
        DB::table('kavling_konsumen')->where('status_penjualan', 'proses_bank')->update(['status_penjualan' => 'pemberkasan']);
        DB::table('kavling_konsumen')->where('status_penjualan', 'bast')->update(['status_penjualan' => 'akad']);
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->enum('status_penjualan', [
                'booking', 'pemberkasan', 'sp3k', 'rencana_akad', 'akad', 'batal',
            ])->default('booking')->change();
        });
    }
};
