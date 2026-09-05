<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            // Nullable dulu — diisi lewat skrip backfill sebelum kolom spek
            // lama di-drop & kolom ini dijadikan wajib (lihat migrasi
            // drop_spec_columns_from_kavlings_table).
            $table->foreignId('tipe_unit_preset_id')->nullable()->after('project_id')
                ->constrained('tipe_unit_presets')->nullOnDelete();
            $table->boolean('perlu_biaya_tambahan')->default(false)->after('keterangan')
                ->comment('Flag pengingat untuk sales: unit hook/pojok/strategis yang butuh komponen biaya tambahan saat booking');
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipe_unit_preset_id');
            $table->dropColumn('perlu_biaya_tambahan');
        });
    }
};
