<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master preset global tahap progress pembangunan kavling — jumlah tahap
 * & bobot masing2 bebas diatur admin (lihat halaman Kelola Status Bangun
 * di Pengaturan), TIDAK lagi hardcode seperti enum StatusBangun lama.
 * Progress kavling dihitung linear-kumulatif berdasarkan `urutan`, jadi
 * total bobot seluruh tahap harus selalu 100 (divalidasi di controller).
 *
 * Tahap dengan `is_default=true` ("Belum Mulai") tidak bisa dihapus/
 * direorder/diberi bobot — selalu ada sebagai fallback saat tahap lain
 * dihapus (lihat StatusBangunStageController::destroy()).
 */
class StatusBangunStage extends Model
{
    protected $fillable = [
        'nama',
        'bobot',
        'urutan',
        'warna',
        'is_default',
    ];

    protected $casts = [
        'bobot'      => 'decimal:2',
        'urutan'     => 'integer',
        'is_default' => 'boolean',
    ];

    public function kavlings(): HasMany
    {
        return $this->hasMany(Kavling::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('urutan');
    }

    /**
     * Progress kumulatif (%) sampai & termasuk tahap ini — jumlah bobot
     * semua tahap dengan urutan <= tahap ini.
     */
    public function progressPercent(): float
    {
        return (float) static::query()->where('urutan', '<=', $this->urutan)->sum('bobot');
    }

    /**
     * Tahap terakhir (urutan tertinggi) = gate "siap serah terima" (BAST),
     * bukan flag terpisah — dijamin selalu = 100% berkat validasi total
     * bobot di StatusBangunStageController.
     */
    public function isFinalStage(): bool
    {
        return $this->urutan === static::max('urutan');
    }

    public static function finalStage(): ?self
    {
        return static::orderByDesc('urutan')->first();
    }

    public static function defaultStage(): ?self
    {
        return static::where('is_default', true)->first();
    }

    public static function totalBobot(): float
    {
        return (float) static::query()->sum('bobot');
    }
}
