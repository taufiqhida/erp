<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->enum('biaya_kelebihan_tanah_status', ['belum_bayar', 'lunas'])
                ->default('belum_bayar')->after('biaya_kelebihan_tanah_nominal');
            $table->foreignId('biaya_kelebihan_tanah_pembayaran_id')->nullable()
                ->after('biaya_kelebihan_tanah_status')->constrained('pembayaran_konsumens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('biaya_kelebihan_tanah_pembayaran_id');
            $table->dropColumn('biaya_kelebihan_tanah_status');
        });
    }
};
