<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->text('catatan_revisi')->nullable()->after('catatan')
                ->comment('Catatan revisi dari staff KPR saat status perlu_revisi/ditolak');
            $table->timestamp('tanggal_upload')->nullable()->after('catatan_revisi');
            $table->timestamp('tanggal_verifikasi')->nullable()->after('tanggal_upload');
            $table->foreignId('verified_by')->nullable()->after('tanggal_verifikasi')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dokumen_konsumens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['catatan_revisi', 'tanggal_upload', 'tanggal_verifikasi']);
        });
    }
};
