<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bank_rekanan_presets', function (Blueprint $table) {
            $table->string('nama_pt', 150)->nullable()->after('nama');
            $table->string('kantor_cabang', 100)->nullable()->after('nama_pt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_rekanan_presets', function (Blueprint $table) {
            $table->dropColumn(['nama_pt', 'kantor_cabang']);
        });
    }
};
