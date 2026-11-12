<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persiapan alur cash rapi (R2b): jatuh tempo cicilan boleh KOSONG, artinya "menunggu tanggal rencana akad". Sebelumnya jatuh
 * tempo DP/Pelunasan otomatis jatuh di hari booking sehingga langsung terbaca terlambat padahal konsumen boleh melunasi kapan saja
 * sebelum akad. Migrasi ini TIDAK mengubah data apa pun (tidak ada baris yang dikosongkan); hanya mengizinkan nilai kosong. Semua
 * pembaca sudah dibuat aman-null pada rilis yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_tagihan', function (Blueprint $table) {
            $table->date('tanggal_jatuh_tempo')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Mengembalikan NOT NULL: baris yang masih kosong diisi dulu dengan tanggal booking transaksinya.
        DB::statement(
            'UPDATE jadwal_tagihan j JOIN kavling_konsumen k ON k.id = j.kavling_konsumen_id
             SET j.tanggal_jatuh_tempo = k.tanggal_booking WHERE j.tanggal_jatuh_tempo IS NULL'
        );
        Schema::table('jadwal_tagihan', function (Blueprint $table) {
            $table->date('tanggal_jatuh_tempo')->nullable(false)->change();
        });
    }
};
