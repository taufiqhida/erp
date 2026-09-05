<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `tanggal_disetujui_sp3k` redundan dengan `tanggal_sp3k` ("Tanggal
     * Terbit") — dua-duanya diisi bareng di form yang sama tanpa beda makna,
     * dan cuma `tanggal_sp3k` yang benar-benar dipakai di logic manapun
     * (kartuPiutangBreakdown, ringkasan pipeline). Dihapus supaya `tanggal_sp3k`
     * jadi satu-satunya sumber kebenaran tanggal SP3K.
     */
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn('tanggal_disetujui_sp3k');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->date('tanggal_disetujui_sp3k')->nullable()->after('tanggal_expired_sp3k');
        });
    }
};
