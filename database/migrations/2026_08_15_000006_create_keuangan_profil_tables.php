<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riwayat pembayaran DP / angsuran / booking fee konsumen
        Schema::create('pembayaran_konsumens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->enum('jenis', ['booking_fee', 'dp', 'angsuran', 'pelunasan'])->default('dp');
            $table->decimal('jumlah', 15, 2);
            $table->date('tanggal_bayar');
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Monitoring klaim SBUM per unit KPR Subsidi
        Schema::create('sbum_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete()->unique();
            $table->decimal('jumlah_sbum', 15, 2)->nullable();
            $table->enum('status', ['belum', 'proses', 'cair', 'ditolak'])->default('belum');
            $table->date('tanggal_pengajuan')->nullable();
            $table->date('tanggal_cair')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        // Pengaturan komponen pembiayaan per proyek (SBUM, Dajam, dll)
        Schema::create('pembiayaan_proyeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete()->unique();
            // Simpan semua konfigurasi sebagai JSON untuk fleksibilitas
            $table->json('kpr_subsidi_config')->nullable()->comment('Komponen: sbum, dajam_sertifikat, dajam_air, dajam_listrik, dll');
            $table->json('kpr_komersil_config')->nullable();
            $table->timestamps();
        });

        // Profil developer (single record)
        Schema::create('developer_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('nama_developer', 200)->nullable();
            $table->text('alamat')->nullable();
            $table->string('telepon', 30)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->string('nama_bank', 100)->nullable();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('atas_nama_rekening', 100)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('kop_surat_path', 255)->nullable();
            $table->timestamps();
        });

        // Template surat
        Schema::create('surat_templates', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->string('subjek', 200)->nullable();
            $table->longText('isi')->comment('Teks template dengan placeholder {{nama_konsumen}}, dll');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surat_templates');
        Schema::dropIfExists('developer_profiles');
        Schema::dropIfExists('pembiayaan_proyeks');
        Schema::dropIfExists('sbum_records');
        Schema::dropIfExists('pembayaran_konsumens');
    }
};
