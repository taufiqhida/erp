<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan standar untuk semua respons web.
 *
 * - X-Frame-Options: cegah ERP dibingkai (iframe) oleh situs lain (clickjacking).
 * - X-Content-Type-Options: browser tidak menebak tipe file (cegah file unggahan dieksekusi sebagai skrip).
 * - Referrer-Policy: URL internal tidak bocor penuh ke situs lain.
 * - Permissions-Policy: matikan fitur browser yang tidak dipakai ERP.
 * - HSTS: paksa HTTPS di browser — hanya dikirim lewat HTTPS dan TANPA includeSubDomains
 *   (domain induk dipakai aplikasi lain yang mungkin masih HTTP).
 *
 * Content-Security-Policy sengaja belum dipasang: Vite/Inertia memakai skrip inline dan
 * perlu penyetelan hati-hati agar tidak merusak halaman.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=15552000');
        }

        return $response;
    }
}
