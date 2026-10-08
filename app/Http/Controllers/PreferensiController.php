<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Preferensi tampilan milik pengguna sendiri: ukuran teks, tema (gelap/terang/sistem), dan bentuk menu samping. */
class PreferensiController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ukuran_font' => ['required_without_all:tema,sidebar', 'nullable', Rule::in(User::UKURAN_FONT)],
            'tema'        => ['required_without_all:ukuran_font,sidebar', 'nullable', Rule::in(User::TEMA)],
            'sidebar'     => ['required_without_all:ukuran_font,tema', 'nullable', Rule::in(User::SIDEBAR)],
        ]);

        $user = $request->user();
        $user->forceFill(['preferences' => [...($user->preferences ?? []), ...array_filter($validated, fn ($v) => $v !== null)]])->save();

        return back();
    }
}
