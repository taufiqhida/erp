<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * User yang ditandai "wajib ganti password" (akun baru dari admin, hasil reset, atau perintah
 * users:wajib-ganti-password) hanya boleh membuka halaman ganti password & keluar, sampai
 * password-nya diganti.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && !$request->routeIs('password.wajib', 'password.wajib.update', 'logout') && !$request->is('media/*')) {
            // Inertia (klik menu) butuh pengalihan penuh; permintaan biasa cukup redirect.
            return $request->header('X-Inertia')
                ? Inertia::location(route('password.wajib'))
                : redirect()->route('password.wajib');
        }

        return $next($request);
    }
}
