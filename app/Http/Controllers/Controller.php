<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /** Pilihan "per halaman" yang diterima (sama dengan pilihan di komponen Pagination.vue). */
    protected const PER_PAGE_OPTIONS = [20, 50, 100];

    /**
     * Jumlah baris per halaman untuk tabel server-side. Pilihan user (?per_page=) hanya diterima
     * kalau termasuk PER_PAGE_OPTIONS, dan DIINGAT per halaman (session) — jadi pindah halaman atau
     * kembali ke tabel yang sama tetap memakai pilihan terakhir.
     */
    protected function perPage(Request $request, int $default = 20): int
    {
        $key = 'per_page.' . ($request->route()?->getName() ?? $request->path());
        $diminta = (int) $request->query('per_page');

        if (in_array($diminta, self::PER_PAGE_OPTIONS, true)) {
            session([$key => $diminta]);

            return $diminta;
        }

        $tersimpan = (int) session($key);

        return in_array($tersimpan, self::PER_PAGE_OPTIONS, true) ? $tersimpan : $default;
    }
}
