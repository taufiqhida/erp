<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan (pembatalan / tukar unit / alih pembeli) menyimpan salinan PEMBELI SAAT DIAJUKAN. Sebelumnya daftar Pembatalan membaca
 * pembeli transaksi saat ini, sehingga setelah Alih Pembeli disetujui pengajuannya tampil "B → B" dan pembeli lama (A) tak terlihat,
 * begitu pula pengajuan lama milik A. Data yang sudah ada diisi ulang dari riwayat peralihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->foreignId('konsumen_id')->nullable()->after('kavling_konsumen_id')->constrained('konsumens')->nullOnDelete();
        });

        // Dasar: pembeli transaksi saat ini.
        DB::statement('UPDATE cancellation_requests cr JOIN kavling_konsumen kk ON kk.id = cr.kavling_konsumen_id SET cr.konsumen_id = kk.konsumen_id');

        // Koreksi untuk transaksi yang pernah dialihkan: pengajuan alih pembeli itu sendiri dan pengajuan lain yang dibuat sebelum
        // peralihan pertama memakai pembeli LAMA (dari riwayat).
        $riwayat = DB::table('alih_pembeli_riwayats')->orderBy('disetujui_at')->orderBy('id')->get()->groupBy('kavling_konsumen_id');
        foreach ($riwayat as $kkId => $baris) {
            foreach ($baris as $r) {
                if ($r->cancellation_request_id) {
                    DB::table('cancellation_requests')->where('id', $r->cancellation_request_id)->update(['konsumen_id' => $r->dari_konsumen_id]);
                }
            }
            // Pengajuan lain: pembeli saat dibuat = "dari" pada peralihan pertama yang disetujui SETELAH pengajuan itu dibuat.
            foreach (DB::table('cancellation_requests')->where('kavling_konsumen_id', $kkId)->where('type', '!=', 'buyer_transfer')->get() as $cr) {
                $berikut = $baris->first(fn ($r) => $r->disetujui_at && $r->disetujui_at >= $cr->created_at);
                if ($berikut) DB::table('cancellation_requests')->where('id', $cr->id)->update(['konsumen_id' => $berikut->dari_konsumen_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('konsumen_id');
        });
    }
};
