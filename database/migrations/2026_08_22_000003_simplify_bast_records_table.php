<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bast_records', function (Blueprint $table) {
            $table->dropColumn(['penerima', 'dokumen_path', 'checklist']);
        });
    }

    public function down(): void
    {
        Schema::table('bast_records', function (Blueprint $table) {
            $table->string('penerima', 100)->nullable();
            $table->string('dokumen_path', 255)->nullable();
            $table->json('checklist')->nullable();
        });
    }
};
