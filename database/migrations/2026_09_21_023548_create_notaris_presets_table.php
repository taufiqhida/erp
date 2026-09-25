<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notaris_presets', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->foreignId('notaris_preset_id')->nullable()->after('tanggal_rencana_akad')
                ->constrained('notaris_presets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notaris_preset_id');
        });

        Schema::dropIfExists('notaris_presets');
    }
};
