<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kavlings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('nomor_kavling', 20);
            $table->string('blok', 10)->nullable();
            $table->decimal('luas_tanah', 8, 2)->nullable()->comment('m2');
            $table->decimal('luas_bangunan', 8, 2)->nullable()->comment('m2');
            $table->decimal('harga', 15, 2)->nullable();
            $table->enum('status_jual', [
                'available',
                'hold',
                'booked',
                'sold',
                'cancellation_requested',
            ])->default('available');
            $table->enum('status_bangun', [
                'not_started',
                'foundation',
                'structure',
                'roofing',
                'finishing',
                'handover_ready',
            ])->default('not_started');
            $table->string('drive_folder_link')->nullable()->comment('Link Google Drive folder');
            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'nomor_kavling']);
            $table->index('status_jual');
            $table->index('status_bangun');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kavlings');
    }
};
