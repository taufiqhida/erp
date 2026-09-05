<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\Project;
use App\Models\TipeUnitPreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TipeUnitPresetController extends Controller
{
    use AuthorizesProjectAccess;

    public function index(Project $project): Response
    {
        $this->authorizeProjectAccess($project);

        $tipeUnits = $project->tipeUnitPresets()
            ->withCount('kavlings')
            ->orderBy('nama')
            ->get()
            ->map(fn($t) => $this->formatTipeUnit($t));

        return Inertia::render('Projects/TipeUnit/Index', [
            'project'   => ['id' => $project->id, 'nama' => $project->nama, 'kode' => $project->kode],
            'tipeUnits' => $tipeUnits,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('create kavlings'), 403);

        $validated = $this->validateTipeUnit($request, $project);

        $tipeUnit = $project->tipeUnitPresets()->create($validated);

        return back()->with('success', "Tipe Unit \"{$tipeUnit->nama}\" berhasil ditambahkan.");
    }

    public function update(Request $request, TipeUnitPreset $tipeUnitPreset): RedirectResponse
    {
        $this->authorizeProjectAccess($tipeUnitPreset->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $validated = $this->validateTipeUnit($request, $tipeUnitPreset->project, $tipeUnitPreset);
        $validated['is_active'] = $request->boolean('is_active', $tipeUnitPreset->is_active);

        $tipeUnitPreset->update($validated);

        return back()->with('success', "Tipe Unit \"{$tipeUnitPreset->nama}\" berhasil diperbarui.");
    }

    /**
     * Hapus permanen kalau belum ada unit yang pakai tipe ini, kalau sudah
     * ada — nonaktifkan saja (is_active=false) supaya histori unit yang
     * sudah pakai tipe ini tetap utuh, tapi tidak muncul lagi di pilihan
     * tambah unit baru.
     */
    public function destroy(TipeUnitPreset $tipeUnitPreset): RedirectResponse
    {
        $this->authorizeProjectAccess($tipeUnitPreset->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        if ($tipeUnitPreset->kavlings()->exists()) {
            $tipeUnitPreset->update(['is_active' => false]);
            return back()->with('success', "Tipe Unit \"{$tipeUnitPreset->nama}\" masih dipakai unit yang ada, jadi dinonaktifkan (bukan dihapus).");
        }

        $nama = $tipeUnitPreset->nama;
        $tipeUnitPreset->delete();

        return back()->with('success', "Tipe Unit \"{$nama}\" berhasil dihapus.");
    }

    public function uploadGambar(Request $request, TipeUnitPreset $tipeUnitPreset): RedirectResponse
    {
        $this->authorizeProjectAccess($tipeUnitPreset->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $request->validate([
            'tipe'   => 'required|in:foto_rumah,denah_rumah',
            'gambar' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $field = $request->input('tipe');

        if ($tipeUnitPreset->$field) {
            Storage::disk('public')->delete($tipeUnitPreset->$field);
        }

        $path = $request->file('gambar')->store("tipe-unit/{$tipeUnitPreset->id}", 'public');
        $tipeUnitPreset->update([$field => $path]);

        $label = $field === 'foto_rumah' ? 'Foto rumah' : 'Denah rumah';
        return back()->with('success', "{$label} Tipe Unit \"{$tipeUnitPreset->nama}\" berhasil diupload.");
    }

    private function validateTipeUnit(Request $request, Project $project, ?TipeUnitPreset $ignore = null): array
    {
        return $request->validate([
            'nama'          => [
                'required', 'string', 'max:100',
                Rule::unique('tipe_unit_presets', 'nama')->where('project_id', $project->id)->ignore($ignore?->id),
            ],
            'luas_tanah'    => 'nullable|numeric|min:0',
            'luas_bangunan' => 'nullable|numeric|min:0',
            'kamar_tidur'   => 'nullable|integer|min:0|max:20',
            'kamar_mandi'   => 'nullable|integer|min:0|max:20',
            'spek_atap'     => 'nullable|string|max:100',
            'spek_dinding'  => 'nullable|string|max:100',
            'spek_lantai'   => 'nullable|string|max:100',
            'spek_pondasi'  => 'nullable|string|max:100',
        ]);
    }

    private function formatTipeUnit(TipeUnitPreset $t): array
    {
        return [
            'id'             => $t->id,
            'nama'           => $t->nama,
            'luas_tanah'     => $t->luas_tanah,
            'luas_bangunan'  => $t->luas_bangunan,
            'kamar_tidur'    => $t->kamar_tidur,
            'kamar_mandi'    => $t->kamar_mandi,
            'spek_atap'      => $t->spek_atap,
            'spek_dinding'   => $t->spek_dinding,
            'spek_lantai'    => $t->spek_lantai,
            'spek_pondasi'   => $t->spek_pondasi,
            'foto_rumah'     => $t->foto_rumah ? route('media.show', ['path' => $t->foto_rumah]) : null,
            'denah_rumah'    => $t->denah_rumah ? route('media.show', ['path' => $t->denah_rumah]) : null,
            'is_active'      => $t->is_active,
            'kavlings_count' => $t->kavlings_count,
        ];
    }
}
