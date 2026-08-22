<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn(['rekening_kpr_dibuka', 'biaya_akad_lunas']);
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->boolean('rekening_kpr_dibuka')->default(false);
            $table->boolean('biaya_akad_lunas')->default(false);
        });
    }
};
