<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kavling_konsumen_biaya_tambahan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->foreignId('biaya_tambahan_preset_id')->nullable()
                ->constrained('biaya_tambahan_presets')->nullOnDelete();
            // Snapshot nama preset saat dipilih — supaya kalau preset di master data
            // diganti nama/dihapus, catatan biaya di transaksi lama tidak berubah/hilang.
            $table->string('nama', 150);
            $table->decimal('nominal', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kavling_konsumen_biaya_tambahan');
    }
};
