<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_agents', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->enum('tipe', ['inhouse', 'freelance'])->default('inhouse');
            // Kalau inhouse dan sekaligus punya akun login ERP (role sales), tautkan
            // ke User supaya data nama/kontak tidak dobel dicatat.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nik', 20)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->string('no_hp', 20)->nullable();
            $table->string('email', 100)->nullable();

            $table->string('nama_bank', 100)->nullable();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('atas_nama_rekening', 100)->nullable();

            // Relasi agency untuk agent freelance (nama agensi/perusahaan naungan)
            $table->string('agency_nama', 150)->nullable();

            $table->enum('komisi_tipe', ['nominal', 'persen'])->default('persen');
            $table->decimal('komisi_nilai', 15, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_agents');
    }
};
