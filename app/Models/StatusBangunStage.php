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

    /** @var array<int, array{sebelum: float, bobot: float}>|null */
    private static ?array $progressMap = null;

    protected static function booted(): void
    {
        // Bobot/urutan berubah → peta progres harus dihitung ulang.
        static::saved(fn () => static::$progressMap = null);
        static::deleted(fn () => static::$progressMap = null);
    }

    /**
     * Per tahap: total bobot tahap-tahap SEBELUMNYA dan bobot tahap itu sendiri.
     * Dimuat sekali per request (bukan satu query per kavling).
     */
    private static function progressMap(): array
    {
        if (static::$progressMap === null) {
            static::$progressMap = [];
            $sebelum = 0.0;
            foreach (static::query()->orderBy('urutan')->get(['id', 'bobot']) as $stage) {
                static::$progressMap[$stage->id] = ['sebelum' => $sebelum, 'bobot' => (float) $stage->bobot];
                $sebelum += (float) $stage->bobot;
            }
        }

        return static::$progressMap;
    }

    /**
     * Progress unit (%) = bobot tahap-tahap sebelumnya + bobot tahap yang sedang
     * dikerjakan x persen penyelesaian tahap itu / 100.
     */
    public static function progressFor(?int $stageId, float $persen): float
    {
        $entry = $stageId ? (static::progressMap()[$stageId] ?? null) : null;
        if (!$entry) return 0.0;

        $persen = max(0.0, min(100.0, $persen));

        return round($entry['sebelum'] + $entry['bobot'] * $persen / 100, 2);
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
