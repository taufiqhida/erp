<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Warna badge/marker untuk status_jual & status_penjualan (pipeline KPR) —
 * hanya warna yang admin-editable, daftar status & labelnya tetap tetap
 * system-driven (lihat StatusJual enum & KavlingKonsumen::getStatusPenjualanLabelAttribute()).
 * Tidak ada tambah/hapus baris — set kategori+kode sudah tetap, di-seed
 * lengkap saat migrasi.
 */
class StatusColor extends Model
{
    protected $fillable = ['kategori', 'kode', 'warna'];

    /** [kode => warna] untuk satu kategori ('status_jual' / 'status_penjualan') */
    public static function map(string $kategori): array
    {
        return static::where('kategori', $kategori)->pluck('warna', 'kode')->all();
    }

    public static function allMapped(): array
    {
        return [
            'status_jual'      => static::map('status_jual'),
            'status_penjualan' => static::map('status_penjualan'),
        ];
    }
}
