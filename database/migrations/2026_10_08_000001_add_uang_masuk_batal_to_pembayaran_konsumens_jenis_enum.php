<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jenis pembayaran baru "uang_masuk_batal": total uang yang pernah diterima dari konsumen yang
 * kemudian batal (dipakai Import Konsumen sheet "Batal", yang hanya punya 1 angka total, bukan
 * rincian per komponen). Hanya menambah nilai enum — data lama tidak berubah.
 */
return new class extends Migration
{
    private const LAMA = "'booking_fee', 'dp', 'angsuran', 'pelunasan', 'biaya_tanah', 'biaya_tambahan', 'sbum', 'dajam', 'tambahan_um', 'titipan_biaya_akad'";

    public function up(): void
    {
        DB::statement("ALTER TABLE pembayaran_konsumens MODIFY COLUMN jenis ENUM(" . self::LAMA . ", 'uang_masuk_batal') NOT NULL");
    }

    public function down(): void
    {
        DB::table('pembayaran_konsumens')->where('jenis', 'uang_masuk_batal')->update(['jenis' => 'booking_fee']);
        DB::statement("ALTER TABLE pembayaran_konsumens MODIFY COLUMN jenis ENUM(" . self::LAMA . ") NOT NULL");
    }
};
