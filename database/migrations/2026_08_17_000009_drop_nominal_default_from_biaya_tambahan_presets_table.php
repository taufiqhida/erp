<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biaya_tambahan_presets', function (Blueprint $table) {
            $table->dropColumn('nominal_default');
        });
    }

    public function down(): void
    {
        Schema::table('biaya_tambahan_presets', function (Blueprint $table) {
            $table->decimal('nominal_default', 15, 2)->nullable();
        });
    }
};
