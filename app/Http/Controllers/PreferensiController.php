<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Preferensi tampilan milik pengguna sendiri (saat ini: ukuran teks). */
class PreferensiController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ukuran_font' => ['required', Rule::in(User::UKURAN_FONT)],
        ]);

        $user = $request->user();
        $user->forceFill(['preferences' => [...($user->preferences ?? []), ...$validated]])->save();

        return back();
    }
}
