<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Nama dan email tidak bisa diubah sendiri: email adalah nama login dan nama tampil di Audit Trail. Perubahan dilakukan
     * superadmin di Manajemen Role (tercatat di Audit Trail).
     */
    public function update(Request $request): RedirectResponse
    {
        abort(403, 'Nama dan email hanya bisa diubah oleh superadmin.');
    }

    /**
     * Delete the user's account.
     */
    /**
     * Akun tidak boleh dihapus sendiri: riwayat kerja (Audit Trail, transaksi) terikat ke akun. Penonaktifan dan
     * penghapusan akun yang belum punya riwayat dilakukan superadmin di Manajemen Role.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort(403, 'Akun hanya bisa dinonaktifkan atau dihapus oleh superadmin.');
    }
}
