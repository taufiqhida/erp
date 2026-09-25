<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening & skema komisi Sales/Agent sengaja tidak lagi dicatat di sistem
 * ini — dipindah ke sistem HR terpisah. Sistem ini cukup simpan atribusi
 * (sales_agent_id di kavling_konsumen), itu sudah jadi rekap datanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_agents', function (Blueprint $table) {
            $table->dropColumn(['nama_bank', 'nomor_rekening', 'atas_nama_rekening', 'komisi_tipe', 'komisi_nilai']);
        });

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn(['komisi_tipe', 'komisi_nilai']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_agents', function (Blueprint $table) {
            $table->string('nama_bank', 100)->nullable()->after('email');
            $table->string('nomor_rekening', 50)->nullable()->after('nama_bank');
            $table->string('atas_nama_rekening', 100)->nullable()->after('nomor_rekening');
            $table->enum('komisi_tipe', ['nominal', 'persen'])->default('persen')->after('agency_nama');
            $table->decimal('komisi_nilai', 15, 2)->nullable()->after('komisi_tipe');
        });

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->enum('komisi_tipe', ['nominal', 'persen'])->nullable()->after('sales_agent_id');
            $table->decimal('komisi_nilai', 15, 2)->nullable()->after('komisi_tipe');
        });
    }
};
