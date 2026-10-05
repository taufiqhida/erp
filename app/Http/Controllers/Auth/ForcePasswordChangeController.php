<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Halaman "buat password baru" untuk akun yang ditandai wajib ganti password. */
class ForcePasswordChangeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (!$request->user()->must_change_password) {
            return redirect()->route('beranda');
        }

        return Inertia::render('Auth/ForcePasswordChange');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
        ]);

        $request->user()->forceFill([
            'password'             => Hash::make($validated['password']),
            'must_change_password' => false,
            'password_changed_at'  => now(),
        ])->save();

        return redirect()->route('beranda')->with('success', 'Password berhasil diganti. Selamat bekerja!');
    }
}
