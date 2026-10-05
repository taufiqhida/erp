<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengumuman yang diatur superadmin dan tampil di halaman login (changelog, info penting). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengumuman', function (Blueprint $table) {
            $table->id();
            $table->string('judul', 150);
            $table->text('isi');
            $table->date('tanggal');
            $table->boolean('disematkan')->default(false);
            $table->boolean('aktif')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['aktif', 'disematkan', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengumuman');
    }
};
