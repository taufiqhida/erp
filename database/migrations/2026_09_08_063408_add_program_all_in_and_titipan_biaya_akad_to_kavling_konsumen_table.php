<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->foreignId('program_all_in_preset_id')->nullable()->after('promo_preset_id')
                ->constrained('program_all_in_presets')->nullOnDelete();
            // Snapshot nominal & status dikunci sekali di booking (persis pola
            // biaya_kelebihan_tanah) — dihitung dari nominal preset dikurangi
            // Booking Fee/DP saat itu, supaya perubahan preset di kemudian
            // hari tidak mengubah transaksi yang sudah dibooking.
            $table->decimal('titipan_biaya_akad_nominal', 15, 2)->nullable()->after('program_all_in_preset_id');
            $table->enum('titipan_biaya_akad_status', ['belum_bayar', 'sebagian', 'lunas'])
                ->default('belum_bayar')->after('titipan_biaya_akad_nominal');
            $table->foreignId('titipan_biaya_akad_pembayaran_id')->nullable()->after('titipan_biaya_akad_status')
                ->constrained('pembayaran_konsumens')->nullOnDelete();
        });

        // 'biaya_akad' ikut dibuang di sini (bukan cuma ditambah
        // 'titipan_biaya_akad') — sudah tidak ada baris berjenis itu lagi
        // setelah migrasi sebelumnya menghapus seluruh data biaya_akad.
        DB::statement("ALTER TABLE pembayaran_konsumens MODIFY COLUMN jenis ENUM('booking_fee', 'dp', 'angsuran', 'pelunasan', 'biaya_tanah', 'biaya_tambahan', 'sbum', 'dajam', 'tambahan_um', 'titipan_biaya_akad') NOT NULL");
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('titipan_biaya_akad_pembayaran_id');
            $table->dropColumn(['titipan_biaya_akad_status', 'titipan_biaya_akad_nominal']);
            $table->dropConstrainedForeignId('program_all_in_preset_id');
        });

        DB::statement("ALTER TABLE pembayaran_konsumens MODIFY COLUMN jenis ENUM('booking_fee', 'dp', 'angsuran', 'pelunasan', 'biaya_tanah', 'biaya_tambahan', 'sbum', 'dajam', 'tambahan_um') NOT NULL");
    }
};
