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
use Illuminate\Validation\ValidationException;
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

        // Membuka Proses Bangun juga mengaktifkan proyek ini sebagai konteks (seperti membuka detail proyek), supaya
        // pengguna yang tidak punya akses ke Stok Kavling (mis. Pelaksana Lapangan) tetap punya proyek aktif di menu.
        session(['current_project_id' => $project->id]);

        // Urutan & paginasi di SERVER (bukan browser): 'progress' dan 'deadline' dihitung di SQL dari
        // tabel tahap & SPK, jadi tetap benar lintas halaman. Aturan 'deadline': yang paling mendekati
        // (termasuk yang sudah lewat) di atas; unit tanpa SPK dan unit yang sudah selesai dibangun
        // selalu di paling bawah.
        $urut = in_array($request->urut, ['unit', 'progress', 'deadline'], true) ? $request->urut : 'unit';
        $arah = $request->arah === 'desc' ? 'desc' : 'asc';
        $finalId = (int) StatusBangunStage::finalStage()?->id;

        $progressExpr = "(COALESCE((SELECT SUM(s2.bobot) FROM status_bangun_stages s2 WHERE s2.urutan < (SELECT s1.urutan FROM status_bangun_stages s1 WHERE s1.id = kavlings.status_bangun_stage_id)), 0)"
            . " + COALESCE((SELECT s3.bobot FROM status_bangun_stages s3 WHERE s3.id = kavlings.status_bangun_stage_id), 0) * LEAST(100, GREATEST(0, kavlings.status_bangun_persen)) / 100)";
        $deadlineExpr = "(SELECT sp.tanggal_deadline FROM spk_kavling sk JOIN spks sp ON sp.id = sk.spk_id WHERE sk.kavling_id = kavlings.id ORDER BY sp.tanggal_terbit DESC, sp.id DESC LIMIT 1)";
        $selesaiExpr = "(kavlings.status_bangun_stage_id = {$finalId} AND kavlings.status_bangun_persen >= 100)";

        $query = $project->kavlings()
            ->when($request->kluster, fn($q) => $q->where('kluster', $request->kluster))
            ->when($request->blok, fn($q) => $q->where('blok', $request->blok))
            ->when($request->tipe_unit_preset_id, fn($q) => $q->where('tipe_unit_preset_id', $request->tipe_unit_preset_id))
            ->when($request->status_bangun_stage_id, fn($q) => $q->where('status_bangun_stage_id', $request->status_bangun_stage_id))
            ->when($request->kontraktor_id, fn($q) => $q->whereHas('spks', fn($sq) => $sq->where('kontraktor_id', $request->kontraktor_id)))
            ->with([
                'tipeUnitPreset:id,nama',
                'statusBangunStage',
                'spks' => fn($q) => $q->with('kontraktor:id,nama')->orderByDesc('tanggal_terbit')->orderByDesc('spks.id'),
            ]);

        if ($urut === 'progress') {
            $query->orderByRaw("{$progressExpr} {$arah}");
        } elseif ($urut === 'deadline') {
            $query->orderByRaw("({$deadlineExpr} IS NULL OR {$selesaiExpr}) ASC")
                ->orderByRaw("{$deadlineExpr} {$arah}");
        }
        Kavling::applyUnitOrder($query, $urut === 'unit' ? $arah : 'asc');

        $kavlings = $query
            ->paginate($this->perPage($request, 50))
            ->withQueryString()
            ->through(fn($k) => $this->formatRow($k));

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
            'filters'  => $request->only(['kluster', 'blok', 'tipe_unit_preset_id', 'status_bangun_stage_id', 'kontraktor_id', 'urut', 'arah']),
            'klusterOptions' => $project->kavlings()->whereNotNull('kluster')->distinct()->orderBy('kluster')->pluck('kluster'),
            'blokOptions'    => $project->kavlings()->whereNotNull('blok')->distinct()->orderBy('blok')->pluck('blok'),
            'tipeUnitOptions' => $project->tipeUnitPresets()->active()->orderBy('nama')->get(['id', 'nama']),
            'statusBangunStages' => StatusBangunStage::ordered()->get(['id', 'nama', 'warna', 'bobot', 'urutan', 'is_default']),
            // Daftar semua unit proyek untuk modal Update Proses (dimuat hanya saat modal dibuka).
            'unitMassal' => Inertia::optional(fn () => Auth::user()->can('update status bangun') ? $this->unitMassal($project) : []),
            // Kontraktor yang PERNAH dapat SPK di proyek ini saja — bukan seluruh master
            // Kontraktor global — supaya opsi filter tidak penuh nama yang tidak relevan.
            'kontraktorOptions' => Kontraktor::whereHas('spks', fn($q) => $q->where('project_id', $project->id))
                ->orderBy('nama')->get(['id', 'nama']),
            'canManage' => Auth::user()->can('update status bangun'),
            'canCreateSpk' => Auth::user()->can('terbitkan spk'),
        ]);
    }

    public function createSpk(Project $project): Response
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('terbitkan spk'), 403);

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
        abort_unless(Auth::user()->can('terbitkan spk'), 403);

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

    /**
     * Ringkas semua unit proyek untuk pemilih unit di modal Update Proses (satu muatan, bukan per halaman):
     * tahap & persen sekarang, progress total, apakah sudah selesai, dan id SPK yang mencakupnya.
     */
    private function unitMassal(Project $project): array
    {
        $finalId = StatusBangunStage::finalStage()?->id;

        $query = $project->kavlings()->with('spks:id');
        Kavling::applyUnitOrder($query, 'asc');

        return $query->get()->map(fn (Kavling $k) => [
            'id'       => $k->id,
            'label'    => $k->nomor_lengkap,
            'kluster'  => $k->kluster,
            'blok'     => $k->blok,
            'stage_id' => $k->status_bangun_stage_id,
            'persen'   => (float) $k->status_bangun_persen,
            'progress' => $k->progress_bangun,
            'selesai'  => $finalId && (int) $k->status_bangun_stage_id === (int) $finalId && (float) $k->status_bangun_persen >= 100,
            'spk_ids'  => $k->spks->pluck('id')->all(),
        ])->all();
    }

    /**
     * Update Proses massal: set tahap + persen yang sama untuk banyak unit sekaligus (satu SPK / satu blok /
     * unit yang dipilih satu per satu). Satu transaksi: semua berubah atau tidak ada. Unit yang progresnya
     * tidak serentak cukup tidak dipilih, lalu diubah sendiri lewat tabel. Catatan unit TIDAK disentuh.
     */
    public function updateMassal(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('update status bangun'), 403);

        $validated = $request->validate([
            'kavling_ids'            => 'required|array|min:1|max:200',
            'kavling_ids.*'          => 'integer|distinct',
            'status_bangun_stage_id' => 'required|exists:status_bangun_stages,id',
            'persen'                 => 'nullable|numeric|min:0|max:100',
            'lewati_selesai'         => 'nullable|boolean',
        ], [
            'kavling_ids.required' => 'Pilih minimal satu unit.',
            'kavling_ids.min'      => 'Pilih minimal satu unit.',
            'kavling_ids.max'      => 'Maksimal 200 unit sekali update.',
        ]);

        $stage = StatusBangunStage::findOrFail($validated['status_bangun_stage_id']);
        $default = StatusBangunStage::defaultStage();
        $persen = $default && $stage->id === $default->id ? 0.0 : ($validated['persen'] ?? null);
        if ($persen === null) {
            throw ValidationException::withMessages(['persen' => 'Isi persen penyelesaian untuk tahap ini.']);
        }
        $persen = (float) $persen;

        $ids = array_values(array_unique(array_map('intval', $validated['kavling_ids'])));
        $units = $project->kavlings()->whereIn('id', $ids)->get();
        abort_if($units->count() !== count($ids), 422, 'Ada unit yang bukan bagian dari proyek ini.');

        $lewatiSelesai = $request->boolean('lewati_selesai', true);
        $finalId = StatusBangunStage::finalStage()?->id;
        $diubah = $dilewati = $sama = 0;

        DB::transaction(function () use ($units, $stage, $persen, $lewatiSelesai, $finalId, &$diubah, &$dilewati, &$sama) {
            foreach ($units as $unit) {
                $selesai = $finalId && (int) $unit->status_bangun_stage_id === (int) $finalId && (float) $unit->status_bangun_persen >= 100;
                if ($lewatiSelesai && $selesai) {
                    $dilewati++;
                    continue;
                }
                if ((int) $unit->status_bangun_stage_id === $stage->id && abs((float) $unit->status_bangun_persen - $persen) < 0.005) {
                    $sama++;
                    continue;
                }
                $unit->update(['status_bangun_stage_id' => $stage->id, 'status_bangun_persen' => $persen]);
                $diubah++;
            }
        });

        activity('proses_bangun')
            ->causedBy($request->user())
            ->withProperties(['project_id' => $project->id, 'unit_ids' => $ids, 'tahap' => $stage->nama, 'persen' => $persen, 'diubah' => $diubah, 'dilewati' => $dilewati, 'sama' => $sama])
            ->log("Update massal proses bangun: {$diubah} unit ke {$stage->nama} {$persen}%");

        $pesan = "Update proses: {$diubah} unit diubah ke {$stage->nama} " . rtrim(rtrim(number_format($persen, 2, ',', ''), '0'), ',') . '%';
        if ($dilewati) $pesan .= ", {$dilewati} unit selesai dilewati";
        if ($sama) $pesan .= ", {$sama} unit sudah sama";

        return back()->with('success', $pesan . '.');
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
            'catatan'                => $k->catatan,
        ];
    }
}
