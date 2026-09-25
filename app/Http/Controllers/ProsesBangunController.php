<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\Kavling;
use App\Models\Kontraktor;
use App\Models\Project;
use App\Models\Spk;
use App\Models\StatusBangunStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Proses Bangun" — terpisah dari Stok Kavling (lihat ProjectController::show,
 * yang sekarang murni display data unit tanpa edit status bangun). Di sini progress
 * bangun diupdate, dan SPK (Surat Perintah Kerja) diterbitkan/dilihat riwayatnya.
 */
class ProsesBangunController extends Controller
{
    use AuthorizesProjectAccess;

    public function index(Request $request, Project $project): Response
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('view kavlings'), 403);

        $kavlings = $project->kavlings()
            ->when($request->kluster, fn($q) => $q->where('kluster', $request->kluster))
            ->when($request->blok, fn($q) => $q->where('blok', $request->blok))
            ->when($request->tipe_unit_preset_id, fn($q) => $q->where('tipe_unit_preset_id', $request->tipe_unit_preset_id))
            ->when($request->status_bangun_stage_id, fn($q) => $q->where('status_bangun_stage_id', $request->status_bangun_stage_id))
            ->with([
                'tipeUnitPreset:id,nama',
                'statusBangunStage',
                'spks' => fn($q) => $q->with('kontraktor:id,nama')->orderByDesc('tanggal_terbit')->orderByDesc('spks.id'),
            ])
            ->orderByUnit()
            ->get()
            ->map(fn($k) => $this->formatRow($k));

        $spkRiwayat = $project->spks()
            ->with('kontraktor:id,nama')
            ->withCount('kavlings')
            ->orderByDesc('tanggal_terbit')
            ->orderByDesc('id')
            ->get()
            ->map(fn($spk) => [
                'id'               => $spk->id,
                'nomor_spk'        => $spk->nomor_spk,
                'kontraktor_nama'  => $spk->kontraktor->nama,
                'tanggal_terbit'   => $spk->tanggal_terbit->format('d M Y'),
                'tanggal_deadline' => $spk->tanggal_deadline->format('d M Y'),
                'deadline_status'  => $spk->deadline_status,
                'jumlah_unit'      => $spk->kavlings_count,
            ]);

        return Inertia::render('ProsesBangun/Index', [
            'project'  => ['id' => $project->id, 'nama' => $project->nama],
            'kavlings' => $kavlings,
            'spkRiwayat' => $spkRiwayat,
            'filters'  => $request->only(['kluster', 'blok', 'tipe_unit_preset_id', 'status_bangun_stage_id']),
            'klusterOptions' => $project->kavlings()->whereNotNull('kluster')->distinct()->orderBy('kluster')->pluck('kluster'),
            'blokOptions'    => $project->kavlings()->whereNotNull('blok')->distinct()->orderBy('blok')->pluck('blok'),
            'tipeUnitOptions' => $project->tipeUnitPresets()->active()->orderBy('nama')->get(['id', 'nama']),
            'statusBangunStages' => StatusBangunStage::ordered()->get(['id', 'nama', 'warna', 'bobot', 'urutan']),
            'canManage' => Auth::user()->can('update status bangun'),
        ]);
    }

    public function createSpk(Project $project): Response
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('update status bangun'), 403);

        $kavlings = $project->kavlings()
            ->with('tipeUnitPreset:id,nama')
            ->orderByUnit()
            ->get(['id', 'kluster', 'blok', 'nomor_kavling', 'tipe_unit_preset_id', 'status_bangun_stage_id'])
            ->map(fn($k) => [
                'id'                     => $k->id,
                'nomor_lengkap'          => $k->nomor_lengkap,
                'kluster'                => $k->kluster,
                'blok'                   => $k->blok,
                'tipe_unit_preset_id'    => $k->tipe_unit_preset_id,
                'tipe_unit_nama'         => $k->tipeUnitPreset?->nama,
                'status_bangun_stage_id' => $k->status_bangun_stage_id,
            ]);

        return Inertia::render('ProsesBangun/BuatSpk', [
            'project'      => ['id' => $project->id, 'nama' => $project->nama],
            'kavlings'     => $kavlings,
            'kontraktors'  => Kontraktor::where('is_active', true)->orderBy('nama')->get(['id', 'nama']),
            'klusterOptions' => $project->kavlings()->whereNotNull('kluster')->distinct()->orderBy('kluster')->pluck('kluster'),
            'blokOptions'    => $project->kavlings()->whereNotNull('blok')->distinct()->orderBy('blok')->pluck('blok'),
            'tipeUnitOptions' => $project->tipeUnitPresets()->active()->orderBy('nama')->get(['id', 'nama']),
            'statusBangunStages' => StatusBangunStage::ordered()->get(['id', 'nama', 'warna']),
        ]);
    }

    /**
     * SPK murni record monitoring (siapa ngerjain unit apa, sampai kapan) —
     * tidak ada generate dokumen sama sekali, dokumen fisiknya disiapkan
     * manual di luar sistem karena isinya terlalu variatif (RAB, spek
     * kerja, dll) untuk di-generate dari placeholder sederhana.
     */
    public function storeSpk(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('update status bangun'), 403);

        $validated = $request->validate([
            'nomor_spk'        => 'required|string|max:100',
            'kontraktor_id'    => 'required|exists:kontraktors,id',
            'tanggal_terbit'   => 'required|date',
            'tanggal_deadline' => 'required|date|after_or_equal:tanggal_terbit',
            'catatan'          => 'nullable|string',
            'kavling_ids'      => 'required|array|min:1',
            'kavling_ids.*'    => "exists:kavlings,id,project_id,{$project->id}",
        ]);

        DB::transaction(function () use ($validated, $project) {
            $spk = Spk::create([
                'project_id'       => $project->id,
                'kontraktor_id'    => $validated['kontraktor_id'],
                'nomor_spk'        => $validated['nomor_spk'],
                'tanggal_terbit'   => $validated['tanggal_terbit'],
                'tanggal_deadline' => $validated['tanggal_deadline'],
                'catatan'          => $validated['catatan'] ?? null,
                'created_by'       => Auth::id(),
            ]);
            $spk->kavlings()->attach($validated['kavling_ids']);
        });

        return redirect()->route('proses-bangun.index', $project->id)
            ->with('success', 'SPK berhasil diterbitkan.');
    }

    private function formatRow(Kavling $k): array
    {
        $spkAktif = $k->spk_aktif;

        return [
            'id'                     => $k->id,
            'nomor_lengkap'          => $k->nomor_lengkap,
            'kluster'                => $k->kluster,
            'blok'                   => $k->blok,
            'tipe_unit_nama'         => $k->tipeUnitPreset?->nama,
            'status_bangun_stage_id' => $k->status_bangun_stage_id,
            'status_bangun_label'    => $k->statusBangunStage?->nama,
            'status_bangun_color'    => $k->statusBangunStage?->warna,
            'status_bangun_persen'   => (float) $k->status_bangun_persen,
            'progress_bangun'        => $k->progress_bangun,
            'kontraktor_nama'        => $spkAktif?->kontraktor?->nama,
            'spk_nomor'              => $spkAktif?->nomor_spk,
            'spk_deadline'           => $spkAktif?->tanggal_deadline?->format('d M Y'),
            'spk_deadline_raw'       => $spkAktif?->tanggal_deadline?->format('Y-m-d'),
            'bangun_selesai'         => $k->bangun_selesai,
            'spk_deadline_status'    => $spkAktif?->deadline_status,
        ];
    }
}
