<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            // Tambah kolom baru
            $table->decimal('koordinat_x', 6, 3)->nullable()->after('catatan')->comment('Posisi X di siteplan (persen 0-100)');
            $table->decimal('koordinat_y', 6, 3)->nullable()->after('koordinat_x')->comment('Posisi Y di siteplan (persen 0-100)');
            $table->string('keterangan', 255)->nullable()->after('koordinat_y')->comment('Keterangan unit: hook, strategis, dll');
            $table->enum('status_unit', ['available', 'not_for_sale'])->default('available')->after('keterangan');

            // Hapus kolom yang dihilangkan
            $table->dropColumn('drive_folder_link');
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropColumn(['koordinat_x', 'koordinat_y', 'keterangan', 'status_unit']);
            $table->string('drive_folder_link')->nullable();
        });
    }
};
