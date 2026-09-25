<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPK (Surat Perintah Kerja) — 1 SPK bisa mencakup banyak kavling sekaligus
 * (lihat spk_kavling), diterbitkan ke 1 kontraktor. Deadline diisi manual
 * (bukan dihitung dari tenor) — lihat diskusi Tahap 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kontraktor_id')->constrained('kontraktors')->restrictOnDelete();
            $table->string('nomor_spk', 100);
            $table->date('tanggal_terbit');
            $table->date('tanggal_deadline');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('spk_kavling', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spk_id')->constrained('spks')->cascadeOnDelete();
            $table->foreignId('kavling_id')->constrained('kavlings')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['spk_id', 'kavling_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spk_kavling');
        Schema::dropIfExists('spks');
    }
};
