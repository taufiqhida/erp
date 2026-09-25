<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sumber_leads', function (Blueprint $table) {
            $table->boolean('is_referral')->default(false)->after('keterangan')
                ->comment('true = booking minta keterangan referral dari siapa');
        });

        DB::table('sumber_leads')->whereRaw('LOWER(nama) LIKE ?', ['%referral%'])->update(['is_referral' => true]);

        Schema::table('konsumens', function (Blueprint $table) {
            $table->string('referral_keterangan', 150)->nullable()->after('sumber_lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('konsumens', function (Blueprint $table) {
            $table->dropColumn('referral_keterangan');
        });

        Schema::table('sumber_leads', function (Blueprint $table) {
            $table->dropColumn('is_referral');
        });
    }
};
