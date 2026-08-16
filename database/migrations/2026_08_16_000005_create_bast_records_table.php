<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bast_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->unique()->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->date('tanggal_bast')->nullable();
            $table->string('penerima', 100)->nullable()->comment('Nama penerima unit (konsumen/kuasa)');
            $table->text('catatan')->nullable();
            $table->string('dokumen_path', 255)->nullable()->comment('Path scan/foto dokumen BAST bertanda tangan');
            $table->enum('status_ttd', ['belum_ttd', 'sudah_ttd'])->default('belum_ttd');
            $table->json('checklist')->nullable()
                ->comment('listrik, air, meteran, sanitair, pintu, jendela, kunci, siap (boolean)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bast_records');
    }
};
