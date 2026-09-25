<?php

namespace App\Http\Controllers;

use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\Kavling;
use App\Models\Project;
use App\Models\StatusBangunStage;
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
            'nomor_kavling'  => ['required', 'string', 'max:20', function ($attribute, $value, $fail) use ($request, $project) {
                if (Kavling::identitasExists($project->id, $request->input('kluster'), $request->input('blok'), (string) $value)) {
                    $fail('Unit dengan kluster, blok, dan nomor yang sama sudah ada di proyek ini.');
                }
            }],
            'tipe_unit_preset_id' => "required|exists:tipe_unit_presets,id,project_id,{$project->id}",
            'kluster'        => 'nullable|string|max:50',
            'blok'           => 'required|string|max:10',
            'harga'          => 'nullable|numeric|min:0',
            'status_jual'    => 'required|in:' . implode(',', StatusJual::values()),
            'status_bangun_stage_id' => 'required|exists:status_bangun_stages,id',
            'keterangan'     => 'nullable|string|max:255',
            'perlu_biaya_tambahan' => 'boolean',
            'catatan'        => 'nullable|string',
            'id_rumah'       => ['nullable', 'string', 'max:50', Rule::unique('kavlings', 'id_rumah')],
            'hgb_no'         => ['nullable', 'string', 'max:50', Rule::unique('kavlings', 'hgb_no')],
        ]);

        $validated['id_rumah'] = $validated['id_rumah'] ?: null;
        $validated['hgb_no'] = $validated['hgb_no'] ?: null;

        $kavling = $project->kavlings()->create($validated);

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} berhasil ditambahkan.");
    }

    public function update(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $validated = $request->validate([
            'nomor_kavling'  => ['required', 'string', 'max:20', function ($attribute, $value, $fail) use ($request, $kavling) {
                if (Kavling::identitasExists($kavling->project_id, $request->input('kluster'), $request->input('blok'), (string) $value, $kavling->id)) {
                    $fail('Unit dengan kluster, blok, dan nomor yang sama sudah ada di proyek ini.');
                }
            }],
            'tipe_unit_preset_id' => "required|exists:tipe_unit_presets,id,project_id,{$kavling->project_id}",
            'kluster'        => 'nullable|string|max:50',
            'blok'           => 'required|string|max:10',
            'harga'          => 'nullable|numeric|min:0',
            'keterangan'     => 'nullable|string|max:255',
            'perlu_biaya_tambahan' => 'boolean',
            'catatan'        => 'nullable|string',
            'id_rumah'       => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'id_rumah')->ignore($kavling->id),
            ],
            'hgb_no'         => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'hgb_no')->ignore($kavling->id),
            ],
        ]);

        $validated['id_rumah'] = $validated['id_rumah'] ?: null;
        $validated['hgb_no'] = $validated['hgb_no'] ?: null;

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
     * Simpan Nomor HGB — sama polanya dengan updateIdRumah(), diedit
     * langsung dari kolom tabel list Kavling.
     */
    public function updateHgbNo(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $validated = $request->validate([
            'hgb_no' => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'hgb_no')->ignore($kavling->id),
            ],
        ]);

        $kavling->update(['hgb_no' => $validated['hgb_no'] ?: null]);

        return back()->with('success', 'Nomor HGB berhasil disimpan.');
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
            'persen'                 => 'nullable|numeric|min:0|max:100',
            'catatan'                => 'nullable|string',
        ]);

        // Pindah tahap tanpa persen eksplisit → mulai dari 0% di tahap baru.
        $stageBerubah = (int) $validated['status_bangun_stage_id'] !== (int) $kavling->status_bangun_stage_id;
        $persen = $validated['persen'] ?? ($stageBerubah ? 0 : $kavling->status_bangun_persen);
        $default = StatusBangunStage::defaultStage();
        if ($default && (int) $validated['status_bangun_stage_id'] === $default->id) $persen = 0;

        $kavling->update([
            'status_bangun_stage_id' => $validated['status_bangun_stage_id'],
            'status_bangun_persen'   => $persen,
        ] + (isset($validated['catatan']) ? ['catatan' => $validated['catatan']] : []));

        return back()->with('success', "Status bangun kavling {$kavling->nomor_lengkap} diperbarui: {$kavling->statusBangunStage->nama} {$persen}% (progress {$kavling->progress_bangun}%)");
    }
}
