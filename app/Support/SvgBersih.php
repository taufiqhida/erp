<?php

namespace App\Support;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * SVG unggahan (logo, siteplan) bisa memuat <script>, atribut on*, atau tautan javascript:.
 * Siteplan disuntikkan ke halaman lewat innerHTML (InlineSiteplanSvg.vue) dan file juga bisa
 * dibuka langsung dari /storage/..., jadi skripnya akan jalan di domain ERP dengan sesi pengunjung.
 * File dibersihkan SAAT DISIMPAN; file lama dibersihkan dengan `php artisan svg:bersihkan`.
 */
class SvgBersih
{
    /** Simpan unggahan ke disk 'public'; kalau SVG, bersihkan dulu dan tolak yang bukan SVG valid. */
    public static function simpan(UploadedFile $file, string $dir, string $field): string
    {
        $path = $file->store($dir, 'public');

        if (self::adalahSvg($path)) {
            $bersih = self::bersihkan((string) Storage::disk('public')->get($path));

            if ($bersih === null) {
                Storage::disk('public')->delete($path);

                throw ValidationException::withMessages([$field => 'File SVG tidak valid atau tidak aman.']);
            }

            Storage::disk('public')->put($path, $bersih);
        }

        return $path;
    }

    public static function adalahSvg(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
    }

    /** Isi SVG tanpa skrip/atribut berbahaya, atau null kalau isinya bukan XML SVG yang bisa dibaca. */
    public static function bersihkan(string $svg): ?string
    {
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);

        $bersih = $sanitizer->sanitize($svg);

        return $bersih === false || $bersih === '' ? null : $bersih;
    }
}
