<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom urutan untuk master data yang urutannya bisa diatur admin (tombol
 * geser naik/turun). Data lama dinomori sesuai urutan tampil sebelumnya
 * (alfabet nama) supaya tampilan tidak berubah sampai admin menggesernya.
 * sales_agents diurutkan per tipe (kategori).
 */
return new class extends Migration
{
    private array $tables = [
        'biaya_tambahan_presets',
        'program_all_in_presets',
        'sumber_leads',
        'bank_rekanan_presets',
        'notaris_presets',
        'sales_agents',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedInteger('urutan')->default(0);
            });

            $counter = [];
            $query = DB::table($table)->orderBy('nama')->orderBy('id');
            foreach ($query->get(['id', $table === 'sales_agents' ? 'tipe' : 'id as tipe']) as $row) {
                $group = $table === 'sales_agents' ? $row->tipe : '_';
                $counter[$group] = ($counter[$group] ?? 0) + 1;
                DB::table($table)->where('id', $row->id)->update(['urutan' => $counter[$group]]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('urutan');
            });
        }
    }
};
