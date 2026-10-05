<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * User yang dinonaktifkan tidak boleh memakai sesi yang masih terbuka: langsung dikeluarkan
 * (selain sesi dihapus saat dinonaktifkan, ini jaring pengaman untuk sesi yang lolos).
 */
class EnsureUserIsActive
{
    public const PESAN = 'Akun Anda dinonaktifkan. Hubungi administrator.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->header('X-Inertia')
                ? Inertia::location(route('login'))
                : redirect()->route('login')->withErrors(['email' => self::PESAN]);
        }

        return $next($request);
    }
}
