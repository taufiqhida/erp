<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->decimal('nominal_diterima', 15, 2)->nullable()->after('catatan_reviewer')
                ->comment('Total dana yang sudah masuk dari konsumen, diambil dari riwayat pembayaran saat disetujui');
            $table->decimal('nominal_dikembalikan', 15, 2)->nullable()->after('nominal_diterima')
                ->comment('Nominal yang dikembalikan ke konsumen, diinput manual oleh Finance');
            $table->decimal('nominal_hangus', 15, 2)->nullable()->after('nominal_dikembalikan')
                ->comment('nominal_diterima - nominal_dikembalikan');
        });
    }

    public function down(): void
    {
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->dropColumn(['nominal_diterima', 'nominal_dikembalikan', 'nominal_hangus']);
        });
    }
};
