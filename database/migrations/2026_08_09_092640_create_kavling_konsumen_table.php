<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Many-to-many: konsumen bisa beli banyak kavling di banyak project
        Schema::create('kavling_konsumen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_id')->constrained()->cascadeOnDelete();
            $table->foreignId('konsumen_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['active', 'cancelled', 'completed'])->default('active');
            $table->date('tanggal_akad')->nullable();
            $table->decimal('harga_deal', 15, 2)->nullable();
            $table->string('metode_bayar', 50)->nullable()->comment('Cash, KPR, Cicilan, dll');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kavling_konsumen');
    }
};
