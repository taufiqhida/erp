<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsumens', function (Blueprint $table) {
            $table->string('status_pernikahan', 20)->nullable()->after('pekerjaan');
            $table->foreignId('sumber_lead_id')->nullable()->after('status_pernikahan')
                ->constrained('sumber_leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('konsumens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sumber_lead_id');
            $table->dropColumn('status_pernikahan');
        });
    }
};
