<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_profile_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('developer_profile_id')->constrained('developer_profiles')->cascadeOnDelete();
            $table->string('nama_bank', 100);
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('atas_nama_rekening', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // Migrasikan data bank tunggal yang sudah ada ke baris pertama relasi baru.
        $profiles = DB::table('developer_profiles')
            ->whereNotNull('nama_bank')
            ->get(['id', 'nama_bank', 'nomor_rekening', 'atas_nama_rekening']);

        foreach ($profiles as $profile) {
            DB::table('developer_profile_banks')->insert([
                'developer_profile_id' => $profile->id,
                'nama_bank'            => $profile->nama_bank,
                'nomor_rekening'       => $profile->nomor_rekening,
                'atas_nama_rekening'   => $profile->atas_nama_rekening,
                'is_primary'           => true,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        Schema::table('developer_profiles', function (Blueprint $table) {
            $table->dropColumn(['nama_bank', 'nomor_rekening', 'atas_nama_rekening']);
        });
    }

    public function down(): void
    {
        Schema::table('developer_profiles', function (Blueprint $table) {
            $table->string('nama_bank', 100)->nullable();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('atas_nama_rekening', 100)->nullable();
        });

        $primaryBanks = DB::table('developer_profile_banks')
            ->where('is_primary', true)
            ->get(['developer_profile_id', 'nama_bank', 'nomor_rekening', 'atas_nama_rekening']);

        foreach ($primaryBanks as $bank) {
            DB::table('developer_profiles')->where('id', $bank->developer_profile_id)->update([
                'nama_bank'          => $bank->nama_bank,
                'nomor_rekening'     => $bank->nomor_rekening,
                'atas_nama_rekening' => $bank->atas_nama_rekening,
            ]);
        }

        Schema::dropIfExists('developer_profile_banks');
    }
};
