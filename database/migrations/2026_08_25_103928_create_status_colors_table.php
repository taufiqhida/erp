<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_colors', function (Blueprint $table) {
            $table->id();
            $table->string('kategori', 30); // 'status_jual' | 'status_penjualan'
            $table->string('kode', 30);      // nilai enum/status, mis. 'available', 'akad'
            $table->string('warna', 7);
            $table->timestamps();
            $table->unique(['kategori', 'kode']);
        });

        // Seed warna default persis sama dengan yang sudah hardcode di seluruh
        // halaman (Tailwind shade-500 hex equivalent) — supaya cutover tidak
        // mengubah tampilan apapun, admin bisa atur ulang belakangan lewat
        // halaman Warna Status.
        $now = now();
        DB::table('status_colors')->insert([
            // status_jual
            ['kategori' => 'status_jual', 'kode' => 'available',              'warna' => '#10b981', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_jual', 'kode' => 'hold',                   'warna' => '#eab308', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_jual', 'kode' => 'booked',                 'warna' => '#3b82f6', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_jual', 'kode' => 'sold',                   'warna' => '#f43f5e', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_jual', 'kode' => 'cancellation_requested', 'warna' => '#f97316', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_jual', 'kode' => 'swap_requested',         'warna' => '#a855f7', 'created_at' => $now, 'updated_at' => $now],
            // status_penjualan (pipeline KPR)
            ['kategori' => 'status_penjualan', 'kode' => 'booking',      'warna' => '#3b82f6', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_penjualan', 'kode' => 'pemberkasan',  'warna' => '#f59e0b', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_penjualan', 'kode' => 'proses_bank',  'warna' => '#f97316', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_penjualan', 'kode' => 'sp3k',         'warna' => '#6366f1', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_penjualan', 'kode' => 'rencana_akad', 'warna' => '#8b5cf6', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_penjualan', 'kode' => 'akad',         'warna' => '#10b981', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_penjualan', 'kode' => 'bast',         'warna' => '#14b8a6', 'created_at' => $now, 'updated_at' => $now],
            ['kategori' => 'status_penjualan', 'kode' => 'batal',        'warna' => '#f43f5e', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('status_colors');
    }
};
