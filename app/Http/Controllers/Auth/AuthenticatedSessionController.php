<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            // Tautan "Lupa password" hanya kalau email benar-benar aktif (MAIL_MAILER bukan log) —
            // kalau tidak, user menunggu email yang tidak pernah datang.
            'canResetPassword' => Route::has('password.request') && \App\Support\Branding::data()['email_enabled'],
            'status' => session('status'),
            // Pengumuman (Pengaturan → Pengumuman) — publik, tampil di sebelah kotak login.
            'pengumuman' => $this->pengumuman(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('beranda', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    /** Gagal diam-diam (daftar kosong) kalau tabel belum dimigrasi, supaya halaman login tidak pernah ikut error. */
    private function pengumuman(): array
    {
        try {
            return \App\Models\Pengumuman::untukLogin()->get()->map(fn ($p) => [
                'id'         => $p->id,
                'judul'      => $p->judul,
                'isi'        => $p->isi,
                'tanggal'    => $p->tanggal->translatedFormat('d F Y'),
                'disematkan' => $p->disematkan,
            ])->all();
        } catch (\Illuminate\Database\QueryException) {
            return [];
        }
    }
}
