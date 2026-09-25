<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->string('hgb_no', 50)->nullable()->after('id_rumah');
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropColumn('hgb_no');
        });
    }
};
