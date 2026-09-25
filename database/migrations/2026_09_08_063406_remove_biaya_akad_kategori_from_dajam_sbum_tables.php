<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Biaya Akad tidak lagi jadi preset/pilihan di Dana Jaminan & SBUM — diganti
 * konsep "Program All In" (lihat migrasi setelahnya). Data biaya_akad yang
 * sudah ada masih data demo, jadi aman dihapus total (bukan diarsipkan).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Hapus pembayaran yang terhubung ke item biaya_akad supaya tidak
        // menyisakan baris pembayaran yatim.
        $pembayaranIds = DB::table('kavling_konsumen_dajam_sbum')
            ->where('kategori', 'biaya_akad')
            ->whereNotNull('pembayaran_konsumen_id')
            ->pluck('pembayaran_konsumen_id');
        DB::table('pembayaran_konsumens')->whereIn('id', $pembayaranIds)->delete();

        DB::table('kavling_konsumen_dajam_sbum')->where('kategori', 'biaya_akad')->delete();
        DB::table('dajam_sbum_presets')->where('kategori', 'biaya_akad')->delete();

        DB::statement("ALTER TABLE kavling_konsumen_dajam_sbum MODIFY COLUMN kategori ENUM('dajam', 'sbum') NOT NULL");
        DB::statement("ALTER TABLE dajam_sbum_presets MODIFY COLUMN kategori ENUM('dajam', 'sbum') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE kavling_konsumen_dajam_sbum MODIFY COLUMN kategori ENUM('dajam', 'sbum', 'biaya_akad') NOT NULL");
        DB::statement("ALTER TABLE dajam_sbum_presets MODIFY COLUMN kategori ENUM('dajam', 'sbum', 'biaya_akad') NOT NULL");
    }
};
