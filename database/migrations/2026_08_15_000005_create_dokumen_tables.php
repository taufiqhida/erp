<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Template dokumen per cara bayar (dikelola admin di Pengaturan)
        Schema::create('dokumen_templates', function (Blueprint $table) {
            $table->id();
            $table->enum('cara_bayar', ['cash', 'cash_bertahap', 'kpr_subsidi', 'kpr_komersil'])->index();
            $table->string('nama_dokumen', 100);
            $table->boolean('is_wajib')->default(true)->comment('true=Wajib, false=Kondisional');
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });

        // Instance dokumen per transaksi konsumen
        Schema::create('dokumen_konsumens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->string('nama_dokumen', 100);
            $table->boolean('is_wajib')->default(true);
            $table->enum('status', ['belum', 'ada', 'proses'])->default('belum');
            $table->string('file_path', 255)->nullable()->comment('Path file upload dokumen');
            $table->text('catatan')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_konsumens');
        Schema::dropIfExists('dokumen_templates');
    }
};
