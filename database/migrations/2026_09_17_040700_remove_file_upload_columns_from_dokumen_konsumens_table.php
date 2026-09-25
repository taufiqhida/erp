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
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'tanggal_upload']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->string('file_path', 255)->nullable()->after('status')
                ->comment('Path file upload dokumen');
            $table->timestamp('tanggal_upload')->nullable()->after('catatan_revisi');
        });
    }
};
