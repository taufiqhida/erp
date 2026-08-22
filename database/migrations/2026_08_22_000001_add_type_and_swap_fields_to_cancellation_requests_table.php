<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->enum('type', ['cancellation', 'unit_swap'])->default('cancellation')->after('id');
            $table->foreignId('kavling_baru_id')->nullable()->after('kavling_id')->constrained('kavlings')->nullOnDelete();
            $table->string('kavling_status_before', 30)->nullable()->comment('Snapshot status_jual sebelum pengajuan, untuk revert akurat saat reject');
        });
    }

    public function down(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kavling_baru_id');
            $table->dropColumn(['type', 'kavling_status_before']);
        });
    }
};
