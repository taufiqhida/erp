<?php

namespace App\Support;

use App\Models\DeveloperProfile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Identitas tampilan aplikasi (logo, nama developer, favicon) — sumbernya satu: Pengaturan →
 * Profil Developer. Dipakai halaman login, sidebar, Beranda, dan tab browser. Kalau logo/nama
 * belum diisi, ada bawaan (ikon rumah ungu + "SSID") supaya tidak pernah ada tampilan kosong.
 */
class Branding
{
    public const NAMA_SISTEM = 'Sedaya Sistem Informasi Developer';
    public const NAMA_BAWAAN = 'SSID';
    private const NAMA_PLACEHOLDER = 'Nama Developer'; // isi awal DeveloperProfile::getSingleton()
    private const FAVICON_PATH = 'developer/favicon.png';

    /** @return array{nama_developer: ?string, nama_sistem: string, nama_singkat: string, logo_url: ?string, favicon_url: string, email_enabled: bool} */
    public static function data(): array
    {
        $nama = null;
        $logoPath = null;

        try {
            $profile = DeveloperProfile::query()->first();
            $nama = $profile?->nama_developer;
            $logoPath = $profile?->logo_path;
        } catch (Throwable) {
            // Tabel belum ada (mis. sebelum migrasi) — pakai bawaan.
        }

        if (!$nama || trim($nama) === self::NAMA_PLACEHOLDER) {
            $nama = null;
        }

        return [
            'nama_developer' => $nama,
            'nama_sistem'    => self::NAMA_SISTEM,
            'nama_singkat'   => self::NAMA_BAWAAN,
            'logo_url'       => $logoPath ? self::mediaUrl($logoPath) : null,
            'favicon_url'    => self::faviconUrl($logoPath),
            // Email dianggap aktif kalau mailer BUKAN log/array (lihat MAIL_MAILER). Dipakai untuk
            // menampilkan tautan "Lupa password" hanya kalau email benar-benar terkirim.
            'email_enabled'  => !in_array(config('mail.default'), ['log', 'array', null], true),
        ];
    }

    private static function mediaUrl(string $path): string
    {
        $v = Storage::disk('public')->exists($path) ? Storage::disk('public')->lastModified($path) : 0;

        return route('media.show', ['path' => $path], false) . ($v ? "?v={$v}" : '');
    }

    private static function faviconUrl(?string $logoPath): string
    {
        if (!$logoPath) {
            return '/favicon.svg';
        }

        // SVG dipakai apa adanya; raster memakai versi persegi 64x64 hasil buatFavicon().
        if (strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'svg') {
            return self::mediaUrl($logoPath);
        }

        return Storage::disk('public')->exists(self::FAVICON_PATH)
            ? self::mediaUrl(self::FAVICON_PATH)
            : self::mediaUrl($logoPath);
    }

    /**
     * Buat favicon persegi 64x64 (transparan, logo di tengah tanpa distorsi) dari logo raster.
     * Logo lebar/tinggi tidak dipotong. Gagal diam-diam (GD tidak ada, gambar rusak) — favicon
     * lalu jatuh kembali ke logo aslinya.
     */
    public static function buatFavicon(string $logoPath): void
    {
        try {
            if (!function_exists('imagecreatefromstring') || strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'svg') {
                Storage::disk('public')->delete(self::FAVICON_PATH);
                return;
            }

            $src = @imagecreatefromstring(Storage::disk('public')->get($logoPath));
            if (!$src) return;

            $sw = imagesx($src);
            $sh = imagesy($src);
            $size = 64;
            $scale = min($size / $sw, $size / $sh);
            $dw = max(1, (int) round($sw * $scale));
            $dh = max(1, (int) round($sh * $scale));

            $canvas = imagecreatetruecolor($size, $size);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagecopyresampled($canvas, $src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $sw, $sh);

            ob_start();
            imagepng($canvas);
            Storage::disk('public')->put(self::FAVICON_PATH, ob_get_clean());
        } catch (Throwable) {
            // abaikan
        }
    }
}
