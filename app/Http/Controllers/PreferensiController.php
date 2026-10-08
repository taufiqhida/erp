<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Preferensi tampilan milik pengguna sendiri: ukuran teks dan tema (gelap/terang/sistem). */
class PreferensiController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ukuran_font' => ['required_without:tema', 'nullable', Rule::in(User::UKURAN_FONT)],
            'tema'        => ['required_without:ukuran_font', 'nullable', Rule::in(User::TEMA)],
        ]);

        $user = $request->user();
        $user->forceFill(['preferences' => [...($user->preferences ?? []), ...array_filter($validated, fn ($v) => $v !== null)]])->save();

        return back();
    }
}
