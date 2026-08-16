<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->string('foto_rumah')->nullable()->after('drive_folder_link');
            $table->string('denah_rumah')->nullable()->after('foto_rumah');
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropColumn(['foto_rumah', 'denah_rumah']);
        });
    }
};
