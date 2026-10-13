<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan yang diketik di modal "Lanjutkan ke tahap berikutnya" dulu menimpa kolom `catatan` (catatan booking), jadi hanya
 * satu yang tersisa dan tidak tampil di mana pun. Sekarang catatan itu ditambahkan (berurutan, dengan tanggal/penulis/tahap)
 * ke kolom ini, sedangkan `catatan` kembali hanya berisi catatan booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->text('catatan_pemberkasan')->nullable()->after('catatan');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn('catatan_pemberkasan');
        });
    }
};
