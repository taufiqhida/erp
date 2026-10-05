<?php

namespace App\Http\Controllers;

use App\Models\Pengumuman;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Pengaturan → Pengumuman Login (superadmin: permission 'manage system settings'). */
class PengumumanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pengaturan/Pengumuman', [
            'pengumuman' => Pengumuman::urut()->get()->map(fn ($p) => [
                'id'         => $p->id,
                'judul'      => $p->judul,
                'isi'        => $p->isi,
                'tanggal'    => $p->tanggal->format('Y-m-d'),
                'disematkan' => $p->disematkan,
                'aktif'      => $p->aktif,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Pengumuman::create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return back()->with('success', 'Pengumuman ditambahkan.');
    }

    public function update(Request $request, Pengumuman $pengumuman): RedirectResponse
    {
        $pengumuman->update($this->validated($request));

        return back()->with('success', 'Pengumuman diperbarui.');
    }

    public function destroy(Pengumuman $pengumuman): RedirectResponse
    {
        $pengumuman->delete();

        return back()->with('success', 'Pengumuman dihapus.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'judul'      => 'required|string|max:150',
            'isi'        => 'required|string|max:3000',
            'tanggal'    => 'required|date',
            'disematkan' => 'boolean',
            'aktif'      => 'boolean',
        ]);

        return [...$data, 'disematkan' => $request->boolean('disematkan'), 'aktif' => $request->boolean('aktif', true)];
    }
}
