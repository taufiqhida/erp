<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipe_unit_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('nama', 100);
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
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipe_unit_presets');
    }
};
