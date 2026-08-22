<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skema_dp_presets', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            // Null = berlaku untuk semua cara bayar
            $table->enum('cara_bayar', ['cash', 'cash_bertahap', 'kpr_subsidi', 'kpr_komersil'])->nullable();

            $table->boolean('booking_fee_aktif')->default(true);
            $table->enum('booking_fee_tipe', ['nominal', 'persen'])->nullable();
            $table->decimal('booking_fee_nilai', 15, 2)->nullable();
            $table->unsignedSmallInteger('booking_fee_tenor')->default(1);

            $table->boolean('dp_aktif')->default(false);
            $table->enum('dp_tipe', ['nominal', 'persen'])->nullable();
            $table->decimal('dp_nilai', 15, 2)->nullable();
            $table->unsignedSmallInteger('dp_tenor')->default(1);

            $table->string('keterangan', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skema_dp_presets');
    }
};
