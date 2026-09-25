<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master Sales/Agent disederhanakan jadi nama + asal (tipe) untuk tagging.
 * Kontak, identitas, tautan akun, dan nama agensi dilepas — urusan fee &
 * agensi ada di luar sistem ini. Tipe jadi string supaya pilihan asal bisa
 * ditambah tanpa ubah skema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['nik', 'npwp', 'no_hp', 'email', 'agency_nama']);
        });

        Schema::table('sales_agents', function (Blueprint $table) {
            $table->string('tipe', 20)->default('inhouse')->change();
        });
    }

    public function down(): void
    {
        DB::table('sales_agents')->whereNotIn('tipe', ['inhouse', 'freelance'])->update(['tipe' => 'freelance']);

        Schema::table('sales_agents', function (Blueprint $table) {
            $table->enum('tipe', ['inhouse', 'freelance'])->default('inhouse')->change();
        });

        Schema::table('sales_agents', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('tipe')->constrained('users')->nullOnDelete();
            $table->string('nik', 20)->nullable()->after('user_id');
            $table->string('npwp', 30)->nullable()->after('nik');
            $table->string('no_hp', 20)->nullable()->after('npwp');
            $table->string('email', 100)->nullable()->after('no_hp');
            $table->string('agency_nama', 150)->nullable()->after('email');
        });
    }
};
