<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->string('tipe_unit', 150)->nullable()->after('denah_rumah');
            $table->tinyInteger('kamar_tidur')->nullable()->after('tipe_unit');
            $table->tinyInteger('kamar_mandi')->nullable()->after('kamar_tidur');
            $table->string('spek_atap', 100)->nullable()->after('kamar_mandi');
            $table->string('spek_dinding', 100)->nullable()->after('spek_atap');
            $table->string('spek_lantai', 100)->nullable()->after('spek_dinding');
            $table->string('spek_pondasi', 100)->nullable()->after('spek_lantai');
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropColumn([
                'tipe_unit', 'kamar_tidur', 'kamar_mandi',
                'spek_atap', 'spek_dinding', 'spek_lantai', 'spek_pondasi',
            ]);
        });
    }
};
