<?php

namespace App\Http\Controllers;

use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\Kavling;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class KavlingController extends Controller
{
    use AuthorizesProjectAccess;


    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('create kavlings'), 403);

        $validated = $request->validate([
            'nomor_kavling'  => "required|string|max:20|unique:kavlings,nomor_kavling,NULL,id,project_id,{$project->id}",
            'tipe_unit_preset_id' => "required|exists:tipe_unit_presets,id,project_id,{$project->id}",
            'kluster'        => 'nullable|string|max:50',
            'blok'           => 'nullable|string|max:10',
            'harga'          => 'nullable|numeric|min:0',
            'status_jual'    => 'required|in:' . implode(',', StatusJual::values()),
            'status_bangun_stage_id' => 'required|exists:status_bangun_stages,id',
            'keterangan'     => 'nullable|string|max:255',
            'perlu_biaya_tambahan' => 'boolean',
            'catatan'        => 'nullable|string',
            'id_rumah'       => ['nullable', 'string', 'max:50', Rule::unique('kavlings', 'id_rumah')],
        ]);

        $validated['id_rumah'] = $validated['id_rumah'] ?: null;

        $kavling = $project->kavlings()->create($validated);

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} berhasil ditambahkan.");
    }

    public function update(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $validated = $request->validate([
            'nomor_kavling'  => "required|string|max:20|unique:kavlings,nomor_kavling,{$kavling->id},id,project_id,{$kavling->project_id}",
            'tipe_unit_preset_id' => "required|exists:tipe_unit_presets,id,project_id,{$kavling->project_id}",
            'kluster'        => 'nullable|string|max:50',
            'blok'           => 'nullable|string|max:10',
            'harga'          => 'nullable|numeric|min:0',
            'keterangan'     => 'nullable|string|max:255',
            'perlu_biaya_tambahan' => 'boolean',
            'catatan'        => 'nullable|string',
            'id_rumah'       => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'id_rumah')->ignore($kavling->id),
            ],
        ]);

        $validated['id_rumah'] = $validated['id_rumah'] ?: null;

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
     * Simpan ID Rumah (Tapera/SIKUMBANG) — bisa diedit langsung dari kolom
     * tabel list Kavling, tidak perlu buka detail. Melekat di unit fisik
     * (bukan transaksi), jadi bisa diisi kapan pun terlepas status jual.
     */
    public function updateIdRumah(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $validated = $request->validate([
            'id_rumah' => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'id_rumah')->ignore($kavling->id),
            ],
        ]);

        $kavling->update(['id_rumah' => $validated['id_rumah'] ?: null]);

        return back()->with('success', 'ID Rumah berhasil disimpan.');
    }

    /**
     * Toggle ketersediaan kavling (Tersedia <-> Tidak Tersedia) oleh admin
     * proyek. Hanya berlaku selama kavling belum dibooking/terjual —
     * begitu masuk pipeline penjualan, status_jual dikendalikan otomatis
     * oleh BookingController, bukan lewat sini.
     */
    public function updateStatusJual(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        abort_unless(
            in_array($kavling->status_jual, [StatusJual::Available, StatusJual::Hold]),
            422,
            'Ketersediaan hanya bisa diubah selama kavling belum dibooking/terjual.'
        );

        $validated = $request->validate([
            'status_jual' => 'required|in:available,hold',
        ]);

        $kavling->update($validated);

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} sekarang: {$kavling->status_jual->label()}");
    }

    /**
     * Update status pembangunan (khusus staff lapangan)
     */
    public function updateStatusBangun(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('update status bangun'), 403);

        $validated = $request->validate([
            'status_bangun_stage_id' => 'required|exists:status_bangun_stages,id',
            'catatan'                => 'nullable|string',
        ]);

        $kavling->update($validated);

        return back()->with('success', "Status bangun kavling {$kavling->nomor_lengkap} diperbarui ke: {$kavling->statusBangunStage->nama}");
    }
}
