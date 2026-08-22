<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen_dajam_sbum', function (Blueprint $table) {
            $table->enum('status', ['belum_bayar', 'lunas'])->default('belum_bayar')->after('nominal');
            $table->foreignId('pembayaran_konsumen_id')->nullable()
                ->after('status')->constrained('pembayaran_konsumens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen_dajam_sbum', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pembayaran_konsumen_id');
            $table->dropColumn('status');
        });
    }
};
