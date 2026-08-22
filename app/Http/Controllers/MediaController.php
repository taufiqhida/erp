<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menyajikan file dari disk 'public' lewat PHP, bukan lewat symlink
 * public/storage. Beberapa environment Windows/Laragon tidak mem-follow
 * symlink NTFS dengan benar di web server-nya, jadi URL storage/... bisa
 * 404/gambar rusak walau file-nya ada. Route ini tidak bergantung pada
 * symlink sama sekali.
 */
class MediaController extends Controller
{
    public function show(string $path): StreamedResponse|Response
    {
        if (str_contains($path, '..') || !Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }
}
