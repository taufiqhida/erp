<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('kavlings', 'status_bangun_stage_id')) {
            Schema::table('kavlings', function (Blueprint $table) {
                $table->foreignId('status_bangun_stage_id')->nullable()
                    ->after('status_bangun')
                    ->constrained('status_bangun_stages')
                    ->restrictOnDelete();
            });
        }

        // Backfill dari nilai enum lama ke stage baru yang namanya persis sama
        // (lihat seed di migrasi create_status_bangun_stages_table).
        if (Schema::hasColumn('kavlings', 'status_bangun')) {
            $map = [
                'not_started'    => 'Belum Mulai',
                'foundation'     => 'Pondasi',
                'structure'      => 'Struktur',
                'roofing'        => 'Atap',
                'finishing'      => 'Finishing',
                'handover_ready' => 'Siap Serah Terima',
            ];

            foreach ($map as $enumValue => $stageName) {
                $stageId = DB::table('status_bangun_stages')->where('nama', $stageName)->value('id');
                if ($stageId) {
                    DB::table('kavlings')->where('status_bangun', $enumValue)->update(['status_bangun_stage_id' => $stageId]);
                }
            }

            // Unit yang somehow belum ke-assign (harusnya tidak ada) jatuh ke
            // default "Belum Mulai" supaya kolom bisa di-set NOT NULL dengan aman.
            $defaultStageId = DB::table('status_bangun_stages')->where('is_default', true)->value('id');
            DB::table('kavlings')->whereNull('status_bangun_stage_id')->update(['status_bangun_stage_id' => $defaultStageId]);

            $fkExists = collect(DB::select("
                SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kavlings'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'kavlings_status_bangun_stage_id_foreign'
            "))->isNotEmpty();

            if ($fkExists) {
                Schema::table('kavlings', function (Blueprint $table) {
                    $table->dropForeign(['status_bangun_stage_id']);
                });
            }

            Schema::table('kavlings', function (Blueprint $table) {
                $table->dropColumn('status_bangun');
            });

            Schema::table('kavlings', function (Blueprint $table) {
                $table->foreignId('status_bangun_stage_id')->nullable(false)->change();
                $table->foreign('status_bangun_stage_id')->references('id')->on('status_bangun_stages')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropForeign(['status_bangun_stage_id']);
        });
        Schema::table('kavlings', function (Blueprint $table) {
            $table->enum('status_bangun', ['not_started', 'foundation', 'structure', 'roofing', 'finishing', 'handover_ready'])
                ->default('not_started');
        });
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropColumn('status_bangun_stage_id');
        });
    }
};
