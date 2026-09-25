<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_all_in_presets', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->decimal('nominal', 15, 2);
            // Menentukan apakah nominal All In sudah termasuk Booking Fee/DP
            // konsumen — dipakai buat hitung sisa "Titipan Biaya Akad" yang
            // masih perlu ditagih (nominal All In - komponen yang di-include).
            $table->boolean('include_booking_fee')->default(false);
            $table->boolean('include_dp')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_all_in_presets');
    }
};
