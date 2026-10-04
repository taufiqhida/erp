<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache hasil agregasi keuangan yang berat (dihitung di PHP per transaksi) dengan
 * kunci berversi: tiap perubahan data keuangan menaikkan versi lewat bump(), jadi
 * entri lama otomatis tak terpakai — tanpa perlu menghapus kunci satu per satu
 * (cocok untuk driver cache database yang tidak mendukung tag).
 *
 * TTL tetap dipasang sebagai pengaman kalau ada jalur tulis yang melewati event model
 * (update massal) — data paling lama basi selama TTL, atau sampai `finance:recompute`.
 */
class FinanceCache
{
    private const VERSION_KEY = 'finance:version';
    private const TTL_SECONDS = 3600;

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, static::version() + 1);
    }

    public static function remember(string $key, callable $callback): mixed
    {
        return Cache::remember($key . ':v' . static::version(), self::TTL_SECONDS, $callback);
    }
}
