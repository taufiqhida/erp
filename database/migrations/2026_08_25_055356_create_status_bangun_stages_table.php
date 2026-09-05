<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_bangun_stages', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->decimal('bobot', 5, 2)->default(0);
            $table->unsignedInteger('urutan');
            $table->string('warna', 7)->default('#64748b');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Seed persis 6 tahap yang sudah ada sekarang (enum StatusBangun lama)
        // supaya cutover tidak mengubah apapun secara visual — bobot masing2
        // tahap sengaja disamakan dengan progressPercent() lama (0/20/20/20/20/20),
        // admin bisa atur ulang belakangan lewat halaman Kelola Status Bangun.
        $now = now();
        DB::table('status_bangun_stages')->insert([
            ['nama' => 'Belum Mulai',        'bobot' => 0,  'urutan' => 1, 'warna' => '#64748b', 'is_default' => true,  'created_at' => $now, 'updated_at' => $now],
            ['nama' => 'Pondasi',            'bobot' => 20, 'urutan' => 2, 'warna' => '#f97316', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['nama' => 'Struktur',           'bobot' => 20, 'urutan' => 3, 'warna' => '#3b82f6', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['nama' => 'Atap',               'bobot' => 20, 'urutan' => 4, 'warna' => '#6366f1', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['nama' => 'Finishing',          'bobot' => 20, 'urutan' => 5, 'warna' => '#a855f7', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['nama' => 'Siap Serah Terima',  'bobot' => 20, 'urutan' => 6, 'warna' => '#10b981', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('status_bangun_stages');
    }
};
