<?php

namespace App\Http\Controllers;

use App\Enums\StatusBangun;
use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\Kavling;
use App\Models\Konsumen;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class KavlingController extends Controller
{
    use AuthorizesProjectAccess;

    public function index(Request $request, Project $project): Response
    {
        $this->authorizeProjectAccess($project);

        $kavlings = $project->kavlings()
            ->when($request->status_jual, fn($q) =>
                $q->where('status_jual', $request->status_jual)
            )
            ->when($request->status_bangun, fn($q) =>
                $q->where('status_bangun', $request->status_bangun)
            )
            ->when($request->blok, fn($q) =>
                $q->where('blok', $request->blok)
            )
            ->with(['activeTransaction.konsumen'])
            ->orderBy('blok')
            ->orderBy('nomor_kavling')
            ->paginate(20)
            ->withQueryString()
            ->through(fn($k) => $this->formatKavling($k));

        return Inertia::render('Kavlings/Index', [
            'project'       => ['id' => $project->id, 'nama' => $project->nama, 'kode' => $project->kode],
            'kavlings'      => $kavlings,
            'filters'       => $request->only(['status_jual', 'status_bangun', 'blok']),
            'statusJualOptions'   => array_map(fn($s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()], StatusJual::cases()),
            'statusBangunOptions' => array_map(fn($s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()], StatusBangun::cases()),
            'konsumens'     => Konsumen::orderBy('nama')->get(['id', 'nama', 'no_hp']),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('create kavlings'), 403);

        $validated = $request->validate([
            'nomor_kavling'  => "required|string|max:20|unique:kavlings,nomor_kavling,NULL,id,project_id,{$project->id}",
            'blok'           => 'nullable|string|max:10',
            'luas_tanah'     => 'nullable|numeric|min:0',
            'luas_bangunan'  => 'nullable|numeric|min:0',
            'harga'          => 'nullable|numeric|min:0',
            'status_jual'    => 'required|in:' . implode(',', StatusJual::values()),
            'status_bangun'  => 'required|in:' . implode(',', StatusBangun::values()),
            'tipe_unit'      => 'nullable|string|max:150',
            'kamar_tidur'    => 'nullable|integer|min:0|max:20',
            'kamar_mandi'    => 'nullable|integer|min:0|max:20',
            'spek_atap'      => 'nullable|string|max:100',
            'spek_dinding'   => 'nullable|string|max:100',
            'spek_lantai'    => 'nullable|string|max:100',
            'spek_pondasi'   => 'nullable|string|max:100',
            'catatan'        => 'nullable|string',
        ]);

        $kavling = $project->kavlings()->create($validated);

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} berhasil ditambahkan.");
    }

    public function update(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $validated = $request->validate([
            'nomor_kavling'  => "required|string|max:20|unique:kavlings,nomor_kavling,{$kavling->id},id,project_id,{$kavling->project_id}",
            'blok'           => 'nullable|string|max:10',
            'luas_tanah'     => 'nullable|numeric|min:0',
            'luas_bangunan'  => 'nullable|numeric|min:0',
            'harga'          => 'nullable|numeric|min:0',
            'tipe_unit'      => 'nullable|string|max:150',
            'kamar_tidur'    => 'nullable|integer|min:0|max:20',
            'kamar_mandi'    => 'nullable|integer|min:0|max:20',
            'spek_atap'      => 'nullable|string|max:100',
            'spek_dinding'   => 'nullable|string|max:100',
            'spek_lantai'    => 'nullable|string|max:100',
            'spek_pondasi'   => 'nullable|string|max:100',
            'catatan'        => 'nullable|string',
        ]);

        $kavling->update($validated);

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} berhasil diperbarui.");
    }

    public function destroy(Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('delete kavlings'), 403);

        $kavling->delete();

        return back()->with('success', 'Kavling berhasil dihapus.');
    }

    /**
     * Upload foto rumah atau denah rumah kavling
     */
    public function uploadGambar(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $request->validate([
            'tipe'   => 'required|in:foto_rumah,denah_rumah',
            'gambar' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120', // max 5MB
        ]);

        $tipe = $request->input('tipe'); // 'foto_rumah' atau 'denah_rumah'

        // Hapus file lama jika ada
        if ($kavling->$tipe) {
            Storage::disk('public')->delete($kavling->$tipe);
        }

        // Simpan file baru
        $path = $request->file('gambar')->store("kavlings/{$kavling->id}", 'public');

        $kavling->update([$tipe => $path]);

        $label = $tipe === 'foto_rumah' ? 'Foto rumah' : 'Denah rumah';
        return back()->with('success', "{$label} kavling {$kavling->nomor_lengkap} berhasil diupload.");
    }

    /**
     * Update status pembangunan (khusus staff lapangan)
     */
    public function updateStatusBangun(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('update status bangun'), 403);

        $validated = $request->validate([
            'status_bangun' => 'required|in:' . implode(',', StatusBangun::values()),
            'catatan'       => 'nullable|string',
        ]);

        $kavling->update($validated);

        return back()->with('success', "Status bangun kavling {$kavling->nomor_lengkap} diperbarui ke: {$kavling->status_bangun->label()}");
    }

    /* ---------------------------------------------------------------
     | Private Helper
     --------------------------------------------------------------- */

    private function formatKavling(Kavling $k): array
    {
        return [
            'id'                  => $k->id,
            'nomor_kavling'       => $k->nomor_kavling,
            'blok'                => $k->blok,
            'nomor_lengkap'       => $k->nomor_lengkap,
            'svg_id'              => $k->svg_id,
            'luas_tanah'          => $k->luas_tanah,
            'luas_bangunan'       => $k->luas_bangunan,
            'harga'               => $k->harga,
            'status_jual'         => $k->status_jual->value,
            'status_jual_label'   => $k->status_jual->label(),
            'status_jual_color'   => $k->status_jual->color(),
            'status_bangun'       => $k->status_bangun->value,
            'status_bangun_label' => $k->status_bangun->label(),
            'status_bangun_color' => $k->status_bangun->color(),
            'progress_bangun'     => $k->progress_bangun,
            'konsumen_nama'       => $k->activeTransaction?->konsumen?->nama,
            'konsumen_id'         => $k->activeTransaction?->konsumen_id,
            'foto_rumah'          => $k->foto_rumah ? Storage::disk('public')->url($k->foto_rumah) : null,
            'denah_rumah'         => $k->denah_rumah ? Storage::disk('public')->url($k->denah_rumah) : null,
            'tipe_unit'           => $k->tipe_unit,
            'kamar_tidur'         => $k->kamar_tidur,
            'kamar_mandi'         => $k->kamar_mandi,
            'spek_atap'           => $k->spek_atap,
            'spek_dinding'        => $k->spek_dinding,
            'spek_lantai'         => $k->spek_lantai,
            'spek_pondasi'        => $k->spek_pondasi,
            'catatan'             => $k->catatan,
        ];
    }
}
