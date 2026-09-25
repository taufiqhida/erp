<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * surat_templates ganti total dari template teks/HTML (isi + placeholder
 * {{..}} di-render via str_replace, tidak pernah punya endpoint generate
 * yang benar2 jalan) jadi template docx asli yang diupload admin, diisi
 * lewat PhpWord TemplateProcessor. Tabel masih kosong (0 baris) saat migrasi
 * ini dibuat, jadi aman diganti tanpa migrasi data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surat_templates', function (Blueprint $table) {
            $table->dropColumn(['subjek', 'isi']);
            $table->string('file_path')->after('nama');
            $table->string('file_original_name')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('surat_templates', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_original_name']);
            $table->string('subjek', 200)->nullable()->after('nama');
            $table->longText('isi')->after('subjek');
        });
    }
};
