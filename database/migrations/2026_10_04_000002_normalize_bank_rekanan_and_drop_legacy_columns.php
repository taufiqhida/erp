<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Bank Rekanan KPR: dari teks bebas (kavling_konsumen.bank_rekanan_kpr) menjadi
 *    foreign key ke bank_rekanan_presets. Dengan teks, ganti nama bank di master
 *    memutus hubungan ke transaksi lama (lookup surat pakai WHERE nama = ...),
 *    dan filter/sort/grup harus mencocokkan string.
 *    Nama lama yang belum ada di master dibuat sebagai preset NONAKTIF supaya tidak ada data hilang.
 *
 * 2. Buang kolom/tabel legacy yang sudah tidak dibaca/ditulis kode mana pun:
 *    metode_bayar (diganti cara_bayar), skema_dp string (diganti skema_dp_preset_id),
 *    realisasi_cair & dajam_ditahan (diganti pencairan_kpr_tahap & kavling_konsumen_dajam_sbum),
 *    tabel sbum_records (diganti kavling_konsumen_dajam_sbum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->foreignId('bank_rekanan_preset_id')->nullable()->after('cara_bayar')
                ->constrained('bank_rekanan_presets')->restrictOnDelete();
        });

        $names = DB::table('kavling_konsumen')
            ->whereNotNull('bank_rekanan_kpr')
            ->whereRaw("TRIM(bank_rekanan_kpr) != ''")
            ->selectRaw('DISTINCT TRIM(bank_rekanan_kpr) as nama')
            ->pluck('nama');

        foreach ($names as $nama) {
            $presetId = DB::table('bank_rekanan_presets')->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->value('id');

            if (!$presetId) {
                $presetId = DB::table('bank_rekanan_presets')->insertGetId([
                    'nama'       => $nama,
                    'keterangan' => 'Dibuat otomatis dari data transaksi lama',
                    'is_active'  => false,
                    'urutan'     => (int) DB::table('bank_rekanan_presets')->max('urutan') + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('kavling_konsumen')
                ->whereRaw('LOWER(TRIM(bank_rekanan_kpr)) = ?', [mb_strtolower($nama)])
                ->update(['bank_rekanan_preset_id' => $presetId]);
        }

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn(['bank_rekanan_kpr', 'metode_bayar', 'skema_dp', 'realisasi_cair', 'dajam_ditahan']);
        });

        Schema::dropIfExists('sbum_records');
    }

    public function down(): void
    {
        Schema::create('sbum_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->decimal('jumlah_sbum', 15, 2)->nullable();
            $table->string('status')->default('belum');
            $table->date('tanggal_pengajuan')->nullable();
            $table->date('tanggal_cair')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->string('bank_rekanan_kpr', 100)->nullable()->after('cara_bayar');
            $table->string('metode_bayar', 50)->nullable();
            $table->string('skema_dp', 255)->nullable();
            $table->decimal('realisasi_cair', 15, 2)->nullable();
            $table->decimal('dajam_ditahan', 15, 2)->nullable();
        });

        DB::statement(
            'UPDATE kavling_konsumen kk JOIN bank_rekanan_presets b ON b.id = kk.bank_rekanan_preset_id SET kk.bank_rekanan_kpr = b.nama'
        );

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_rekanan_preset_id');
        });
    }
};
