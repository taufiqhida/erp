<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index pendukung query yang dipakai berulang di Dashboard, Keuangan, Konsumen,
 * Penjualan & Proses Bangun. Index FK sudah dibuat otomatis oleh constrained();
 * di sini hanya kombinasi filter/sort yang sebelumnya jatuh ke full scan.
 */
return new class extends Migration
{
    /** @var array<string, array<string, array<int, string>>> tabel => [nama index => kolom] */
    private array $indexes = [
        'jadwal_tagihan' => [
            'jadwal_tagihan_status_jatuh_tempo_idx' => ['status', 'tanggal_jatuh_tempo'],
            'jadwal_tagihan_kk_jenis_idx'           => ['kavling_konsumen_id', 'jenis'],
        ],
        'pembayaran_konsumens' => [
            'pembayaran_konsumens_tanggal_bayar_idx' => ['tanggal_bayar'],
            'pembayaran_konsumens_kk_jenis_idx'      => ['kavling_konsumen_id', 'jenis'],
        ],
        'pencairan_kpr_tahap' => [
            'pencairan_kpr_tahap_tanggal_cair_idx' => ['tanggal_cair'],
        ],
        'kavling_konsumen' => [
            'kavling_konsumen_kavling_status_idx'   => ['kavling_id', 'status'],
            'kavling_konsumen_status_penjualan_idx' => ['status', 'status_penjualan'],
            'kavling_konsumen_tanggal_bast_idx'     => ['tanggal_bast'],
            'kavling_konsumen_expired_sp3k_idx'     => ['tanggal_expired_sp3k'],
        ],
        'kavlings' => [
            'kavlings_project_status_jual_idx'  => ['project_id', 'status_jual'],
            'kavlings_project_stage_idx'        => ['project_id', 'status_bangun_stage_id'],
        ],
        'kavling_konsumen_dajam_sbum' => [
            'kk_dajam_sbum_kk_kategori_idx' => ['kavling_konsumen_id', 'kategori'],
        ],
        'activity_log' => [
            'activity_log_created_at_idx' => ['created_at'],
        ],
        'konsumens' => [
            'konsumens_nama_idx' => ['nama'],
        ],
        'cancellation_requests' => [
            'cancellation_requests_type_status_idx' => ['type', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $t->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $t->dropIndex($name);
                }
            });
        }
    }
};
