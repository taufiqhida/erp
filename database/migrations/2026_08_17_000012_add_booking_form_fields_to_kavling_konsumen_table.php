<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            // ── Section 1: Tanggal, Sales/Agent ──────────────────────────
            $table->date('tanggal_booking')->nullable()->after('konsumen_id');

            $table->foreignId('sales_agent_id')->nullable()->after('created_by')
                ->constrained('sales_agents')->nullOnDelete();
            // Snapshot skema komisi SalesAgent pada saat booking (terkunci —
            // perubahan preset komisi di master data setelahnya tidak
            // mengubah transaksi yang sudah jalan).
            $table->enum('komisi_tipe', ['nominal', 'persen'])->nullable()->after('sales_agent_id');
            $table->decimal('komisi_nilai', 15, 2)->nullable()->after('komisi_tipe');

            // Snapshot harga kavling saat booking, dasar perhitungan harga jual netto.
            $table->decimal('harga_dasar', 15, 2)->nullable()->after('harga_deal');

            // ── Section 2: Biaya Kelebihan Tanah ──────────────────────────
            $table->boolean('biaya_kelebihan_tanah_aktif')->default(false)->after('harga_dasar');
            $table->decimal('biaya_kelebihan_tanah_luas', 10, 2)->nullable()->after('biaya_kelebihan_tanah_aktif');
            $table->enum('biaya_kelebihan_tanah_mode', ['per_m2', 'nominal'])->nullable()->after('biaya_kelebihan_tanah_luas');
            $table->decimal('biaya_kelebihan_tanah_harga_per_m2', 15, 2)->nullable()->after('biaya_kelebihan_tanah_mode');
            $table->decimal('biaya_kelebihan_tanah_nominal', 15, 2)->nullable()->after('biaya_kelebihan_tanah_harga_per_m2');

            // ── Section 2: Diskon / Promo ─────────────────────────────────
            $table->foreignId('promo_preset_id')->nullable()->after('biaya_kelebihan_tanah_nominal')
                ->constrained('promo_presets')->nullOnDelete();
            $table->enum('diskon_mode', ['persen', 'nominal'])->nullable()->after('promo_preset_id');
            $table->decimal('diskon_nilai', 15, 2)->nullable()->after('diskon_mode');
            $table->decimal('diskon_nominal', 15, 2)->nullable()->after('diskon_nilai');

            // Total biaya tambahan (kelebihan tanah + item biaya lain) — hasil
            // hitung server, disimpan supaya tidak perlu dihitung ulang tiap tampil.
            $table->decimal('total_biaya_tambahan', 15, 2)->nullable()->after('diskon_nominal');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_agent_id');
            $table->dropConstrainedForeignId('promo_preset_id');
            $table->dropColumn([
                'tanggal_booking',
                'komisi_tipe',
                'komisi_nilai',
                'harga_dasar',
                'biaya_kelebihan_tanah_aktif',
                'biaya_kelebihan_tanah_luas',
                'biaya_kelebihan_tanah_mode',
                'biaya_kelebihan_tanah_harga_per_m2',
                'biaya_kelebihan_tanah_nominal',
                'diskon_mode',
                'diskon_nilai',
                'diskon_nominal',
                'total_biaya_tambahan',
            ]);
        });
    }
};
