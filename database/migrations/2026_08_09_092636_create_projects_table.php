<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->string('kode', 20)->unique()->comment('Kode singkat proyek, misal: "VILLA-01"');
            $table->text('deskripsi')->nullable();
            $table->string('lokasi')->nullable();
            $table->string('kota', 50)->nullable();
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai_estimasi')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('drive_folder_link')->nullable()->comment('Link Google Drive folder proyek');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
