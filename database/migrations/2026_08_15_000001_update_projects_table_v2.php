<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Tambah kolom baru
            $table->decimal('luas_tanah_total', 12, 2)->nullable()->after('kota')->comment('Total luas tanah proyek (m²)');
            $table->string('siteplan_image')->nullable()->after('luas_tanah_total')->comment('Path file gambar siteplan');

            // Hapus kolom yang dihilangkan
            $table->dropColumn(['drive_folder_link', 'tanggal_mulai', 'tanggal_selesai_estimasi']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['luas_tanah_total', 'siteplan_image']);
            $table->string('drive_folder_link')->nullable();
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai_estimasi')->nullable();
        });
    }
};
