<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->string('id_rumah', 50)->nullable()->unique()
                ->comment('ID Rumah Tapera/SIKUMBANG, diisi manual oleh admin');
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropColumn('id_rumah');
        });
    }
};
