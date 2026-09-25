<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('developer_profiles', function (Blueprint $table) {
            $table->string('nama_penandatangan', 100)->nullable()->after('npwp');
            $table->string('jabatan_penandatangan', 100)->nullable()->after('nama_penandatangan');
        });
    }

    public function down(): void
    {
        Schema::table('developer_profiles', function (Blueprint $table) {
            $table->dropColumn(['nama_penandatangan', 'jabatan_penandatangan']);
        });
    }
};
