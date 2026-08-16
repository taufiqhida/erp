<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ganti is_wajib (boolean) jadi sifat (wajib/kondisional/opsional) di
 * dokumen_templates & dokumen_konsumens. Data lama: true->wajib, false->kondisional
 * (opsional adalah state baru, tidak ada mapping dari boolean lama).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['dokumen_templates', 'dokumen_konsumens'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('sifat', 20)->default('wajib')->after('is_wajib');
            });

            DB::table($tableName)->where('is_wajib', true)->update(['sifat' => 'wajib']);
            DB::table($tableName)->where('is_wajib', false)->update(['sifat' => 'kondisional']);

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('is_wajib');
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->enum('sifat', ['wajib', 'kondisional', 'opsional'])->default('wajib')->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['dokumen_templates', 'dokumen_konsumens'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('is_wajib')->default(true)->after('sifat');
            });

            DB::table($tableName)->where('sifat', 'wajib')->update(['is_wajib' => true]);
            DB::table($tableName)->whereIn('sifat', ['kondisional', 'opsional'])->update(['is_wajib' => false]);

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('sifat');
            });
        }
    }
};
