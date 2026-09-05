<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('kavlings', 'luas_tanah')) {
            Schema::table('kavlings', function (Blueprint $table) {
                $table->dropColumn([
                    'luas_tanah', 'luas_bangunan', 'kamar_tidur', 'kamar_mandi',
                    'spek_atap', 'spek_dinding', 'spek_lantai', 'spek_pondasi',
                    'foto_rumah', 'denah_rumah', 'tipe_unit',
                ]);
            });
        }

        // FK lama (dari migrasi sebelumnya) pakai SET NULL, tidak kompatibel
        // dengan NOT NULL — pastikan tidak ada FK constraint tersisa di
        // kolom ini sebelum di-set wajib & ditambah ulang dengan RESTRICT
        // (cegah hapus tipe yang masih dipakai; nonaktifkan lewat is_active).
        $fkExists = collect(DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kavlings'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'kavlings_tipe_unit_preset_id_foreign'
        "))->isNotEmpty();

        if ($fkExists) {
            Schema::table('kavlings', function (Blueprint $table) {
                $table->dropForeign(['tipe_unit_preset_id']);
            });
        }

        Schema::table('kavlings', function (Blueprint $table) {
            $table->foreignId('tipe_unit_preset_id')->nullable(false)->change();
            $table->foreign('tipe_unit_preset_id')->references('id')->on('tipe_unit_presets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropForeign(['tipe_unit_preset_id']);
        });
        Schema::table('kavlings', function (Blueprint $table) {
            $table->foreignId('tipe_unit_preset_id')->nullable()->change();
            $table->foreign('tipe_unit_preset_id')->references('id')->on('tipe_unit_presets')->nullOnDelete();
            $table->decimal('luas_tanah', 8, 2)->nullable();
            $table->decimal('luas_bangunan', 8, 2)->nullable();
            $table->unsignedTinyInteger('kamar_tidur')->nullable();
            $table->unsignedTinyInteger('kamar_mandi')->nullable();
            $table->string('spek_atap', 100)->nullable();
            $table->string('spek_dinding', 100)->nullable();
            $table->string('spek_lantai', 100)->nullable();
            $table->string('spek_pondasi', 100)->nullable();
            $table->string('foto_rumah', 255)->nullable();
            $table->string('denah_rumah', 255)->nullable();
            $table->string('tipe_unit', 150)->nullable();
        });
    }
};
