<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rapikan urutan lama (banyak yang 0 semua) jadi berurutan 1..N per cara
     * bayar, sesuai urutan tampil sebelumnya (urutan lalu id), supaya tombol
     * geser naik/turun bekerja.
     */
    public function up(): void
    {
        $rows = DB::table('dokumen_templates')->orderBy('cara_bayar')->orderBy('urutan')->orderBy('id')->get(['id', 'cara_bayar']);

        $counter = [];
        foreach ($rows as $row) {
            $counter[$row->cara_bayar] = ($counter[$row->cara_bayar] ?? 0) + 1;
            DB::table('dokumen_templates')->where('id', $row->id)->update(['urutan' => $counter[$row->cara_bayar]]);
        }
    }

    public function down(): void
    {
        // Data-only, tidak ada yang perlu dikembalikan.
    }
};
