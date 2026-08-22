<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE kavlings MODIFY status_jual ENUM('available', 'hold', 'booked', 'sold', 'cancellation_requested', 'swap_requested') NOT NULL DEFAULT 'available'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE kavlings MODIFY status_jual ENUM('available', 'hold', 'booked', 'sold', 'cancellation_requested') NOT NULL DEFAULT 'available'");
    }
};
