<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Di belakang reverse proxy (Nginx host/aaPanel -> container) Laravel harus
        // percaya header X-Forwarded-*, kalau tidak URL aset dibuat http:// dan
        // diblokir browser di halaman https (layar putih). Aktif lewat TRUSTED_PROXIES=*.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }

        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\EnsurePasswordIsChanged::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Daftarkan alias middleware Spatie Permission
        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Redirect ke dashboard jika tidak punya akses (role/permission tidak cukup)
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->expectsJson()) return null;
            return redirect()->route('dashboard')->with('error', 'Anda tidak memiliki akses ke halaman tersebut.');
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            if ($request->expectsJson()) return null;
            return redirect()->route('dashboard')->with('error', 'Anda tidak memiliki akses ke halaman tersebut.');
        });

        // Halaman error berbahasa Indonesia untuk akses LANGSUNG (buka alamat/muat ulang). Permintaan
        // Inertia/XHR dibiarkan: aksi yang ditolak tetap muncul sebagai toast (lihat router.on('invalid')
        // di app.js), dan mode debug tetap menampilkan halaman debug untuk error 500.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (!in_array($status, [403, 404, 419, 500, 503], true)) return $response;
            if ($request->header('X-Inertia') || $request->expectsJson()) return $response;
            if ($status === 500 && config('app.debug')) return $response;

            try {
                return \Inertia\Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            } catch (\Throwable) {
                return $response; // kalau halaman error sendiri gagal dirender, pakai respons bawaan
            }
        });
    })->create();
