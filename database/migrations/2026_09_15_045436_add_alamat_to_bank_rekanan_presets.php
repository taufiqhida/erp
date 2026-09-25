<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_rekanan_presets', function (Blueprint $table) {
            $table->text('alamat')->nullable()->after('keterangan');
        });
    }

    public function down(): void
    {
        Schema::table('bank_rekanan_presets', function (Blueprint $table) {
            $table->dropColumn('alamat');
        });
    }
};
