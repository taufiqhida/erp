<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\Kavling;
use App\Models\Kontraktor;
use App\Models\Project;
use App\Models\Spk;
use App\Models\StatusBangunStage;
use App\Support\SpkRingkas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SPK Unit — Surat Perintah Kerja pembangunan per unit (bukan SPK sarana/prasarana). Satu SPK mencakup banyak unit dan satu kontraktor,
 * dengan satu Nilai SPK untuk seluruh SPK (borongan). Satu unit hanya boleh berada di SATU SPK: Buat SPK tidak bisa memilih unit yang sudah
 * punya SPK (dicek di server juga), dan revisi dilakukan lewat Edit SPK (ubah data, tambah unit bebas, keluarkan unit).
 *
 * SPK murni record monitoring (siapa mengerjakan unit apa, sampai kapan, berapa nilainya); dokumen fisiknya disiapkan di luar sistem.
 */
class SpkController extends Controller
{
    use AuthorizesProjectAccess;

    private const STATUS = ['berjalan', 'lewat_deadline', 'selesai', 'semua'];
    private const URUTAN = ['nomor', 'kontraktor', 'unit', 'terbit', 'deadline', 'nilai'];

    /** Nilai SPK sensitif: hanya yang boleh menerbitkan SPK atau punya akses keuangan. */
    private function bolehLihatNilai(): bool
    {
        $user = Auth::user();

        return $user->can('terbitkan spk') || $user->can('view keuangan');
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('view kavlings'), 403);

        // Seperti Proses Bangun: membuka halaman ini menjadikan proyek ini konteks aktif.
        session(['current_project_id' => $project->id]);

        $semua = SpkRingkas::daftar($project);
        $kartu = SpkRingkas::kartu($semua);
        $lihatNilai = $this->bolehLihatNilai();

        $status = in_array($request->status, self::STATUS, true) ? $request->status : 'berjalan';
        // Urutan awal: yang paling mendesak (deadline terdekat) di atas untuk SPK berjalan; selainnya yang terbaru diterbitkan.
        $urutAwal = in_array($status, ['berjalan', 'lewat_deadline'], true) ? ['deadline', 'asc'] : ['terbit', 'desc'];
        $urutValid = in_array($request->urut, self::URUTAN, true) && ($request->urut !== 'nilai' || $lihatNilai);
        $urut = $urutValid ? $request->urut : $urutAwal[0];
        $arah = $urutValid ? ($request->arah === 'asc' ? 'asc' : 'desc') : $urutAwal[1];
        $cari = trim((string) $request->search);

        $baris = $semua
            ->filter(fn ($r) => match ($status) {
                'berjalan'       => $r['status'] === SpkRingkas::STATUS_BERJALAN,
                'lewat_deadline' => $r['lewat_deadline'],
                'selesai'        => $r['status'] === SpkRingkas::STATUS_SELESAI,
                default          => true,
            })
            ->when($request->kontraktor_id, fn ($c, $id) => $c->where('kontraktor_id', (int) $id))
            ->when($cari !== '', fn ($c) => $c->filter(fn ($r) => mb_stripos($r['nomor_spk'], $cari) !== false))
            ->all();

        $kunci = fn (array $r) => match ($urut) {
            'nomor'      => mb_strtolower($r['nomor_spk']),
            'kontraktor' => mb_strtolower($r['kontraktor_nama']),
            'unit'       => $r['jumlah_unit'],
            'terbit'     => $r['terbit_raw'],
            'nilai'      => $r['nilai_spk'],
            default      => $r['deadline_raw'],
        };
        $mult = $arah === 'asc' ? 1 : -1;
        usort($baris, function ($a, $b) use ($kunci, $mult) {
            $x = $kunci($a);
            $y = $kunci($b);
            if ($x === null || $y === null) {
                if ($x !== $y) return $x === null ? 1 : -1;          // kosong (mis. nilai belum diisi) selalu di bawah
            } elseif (($c = $x <=> $y) !== 0) {
                return $c * $mult;
            }

            return $b['id'] <=> $a['id'];
        });

        $perPage = $this->perPage($request, 20);
        $page = max(1, (int) $request->query('page', 1));
        $rows = (new LengthAwarePaginator(
            array_map(fn ($r) => $lihatNilai ? $r : array_diff_key($r, ['nilai_spk' => 1]), array_slice($baris, ($page - 1) * $perPage, $perPage)),
            count($baris), $perPage, $page, ['path' => $request->url()]
        ))->withQueryString();

        return Inertia::render('Spk/Index', [
            'project'           => ['id' => $project->id, 'nama' => $project->nama],
            'rows'              => $rows,
            'kartu'             => $kartu,
            'filters'           => ['status' => $status, 'urut' => $urut, 'arah' => $arah, 'search' => $cari, 'kontraktor_id' => $request->kontraktor_id],
            'kontraktorOptions' => Kontraktor::whereHas('spks', fn ($q) => $q->where('project_id', $project->id))->orderBy('nama')->get(['id', 'nama']),
            'canManage'         => Auth::user()->can('terbitkan spk'),
            'lihatNilai'        => $lihatNilai,
        ]);
    }

    public function create(Project $project): Response
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('terbitkan spk'), 403);

        return Inertia::render('Spk/Form', $this->dataForm($project, null));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('terbitkan spk'), 403);

        $validated = $this->validasi($request, $project, null);
        $this->pastikanUnitBebas($project, $validated['kavling_ids'], null);

        $spk = DB::transaction(function () use ($validated, $project) {
            $spk = Spk::create([
                'project_id'       => $project->id,
                'kontraktor_id'    => $validated['kontraktor_id'],
                'nomor_spk'        => $validated['nomor_spk'],
                'tanggal_terbit'   => $validated['tanggal_terbit'],
                'tanggal_deadline' => $validated['tanggal_deadline'],
                'nilai_spk'        => $validated['nilai_spk'],
                'catatan'          => $validated['catatan'] ?? null,
                'created_by'       => Auth::id(),
            ]);
            $spk->kavlings()->attach($validated['kavling_ids']);

            return $spk;
        });

        activity('spk')->causedBy($request->user())->performedOn($spk)
            ->withProperties(['nomor_spk' => $spk->nomor_spk, 'nilai_spk' => (float) $spk->nilai_spk, 'jumlah_unit' => count($validated['kavling_ids'])])
            ->log('SPK diterbitkan');

        return redirect()->route('spk.index', $project->id)->with('success', 'SPK berhasil diterbitkan.');
    }

    public function edit(Project $project, Spk $spk): Response
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('terbitkan spk'), 403);
        abort_unless($spk->project_id === $project->id, 404);

        return Inertia::render('Spk/Form', $this->dataForm($project, $spk));
    }

    public function update(Request $request, Project $project, Spk $spk): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('terbitkan spk'), 403);
        abort_unless($spk->project_id === $project->id, 404);

        $validated = $this->validasi($request, $project, $spk);
        $this->pastikanUnitBebas($project, $validated['kavling_ids'], $spk->id);

        $lama = $spk->only(['nomor_spk', 'kontraktor_id', 'tanggal_terbit', 'tanggal_deadline', 'nilai_spk', 'catatan']);
        $nomorUnit = fn (array $ids) => Kavling::whereIn('id', $ids)->get()->map->nomor_lengkap->sort()->values()->all();
        $idLama = $spk->kavlings()->pluck('kavlings.id')->all();

        DB::transaction(function () use ($spk, $validated) {
            $spk->update([
                'kontraktor_id'    => $validated['kontraktor_id'],
                'nomor_spk'        => $validated['nomor_spk'],
                'tanggal_terbit'   => $validated['tanggal_terbit'],
                'tanggal_deadline' => $validated['tanggal_deadline'],
                'nilai_spk'        => $validated['nilai_spk'],
                'catatan'          => $validated['catatan'] ?? null,
            ]);
            $spk->kavlings()->sync($validated['kavling_ids']);
        });

        $baru = $spk->fresh()->only(['nomor_spk', 'kontraktor_id', 'tanggal_terbit', 'tanggal_deadline', 'nilai_spk', 'catatan']);
        activity('spk')->causedBy($request->user())->performedOn($spk)
            ->withProperties([
                'lama'             => $lama,
                'baru'             => $baru,
                'unit_ditambah'    => $nomorUnit(array_values(array_diff($validated['kavling_ids'], $idLama))),
                'unit_dikeluarkan' => $nomorUnit(array_values(array_diff($idLama, $validated['kavling_ids']))),
            ])
            ->log('SPK diubah');

        return redirect()->route('spk.index', $project->id)->with('success', 'SPK berhasil diperbarui.');
    }

    private function validasi(Request $request, Project $project, ?Spk $spk): array
    {
        $validated = $request->validate([
            'nomor_spk'        => ['required', 'string', 'max:100', Rule::unique('spks', 'nomor_spk')->where('project_id', $project->id)->ignore($spk?->id)],
            'kontraktor_id'    => 'required|exists:kontraktors,id',
            'tanggal_terbit'   => 'required|date',
            'tanggal_deadline' => 'required|date|after_or_equal:tanggal_terbit',
            'nilai_spk'        => 'required|numeric|min:0',
            'catatan'          => 'nullable|string',
            'kavling_ids'      => 'required|array|min:1',
            'kavling_ids.*'    => "exists:kavlings,id,project_id,{$project->id}",
        ], [
            'nomor_spk.unique'    => 'Nomor SPK ini sudah dipakai di proyek ini.',
            'nilai_spk.required'  => 'Nilai SPK wajib diisi.',
            'kavling_ids.required' => 'Pilih minimal satu unit.',
            'kavling_ids.min'     => 'Pilih minimal satu unit.',
        ]);
        $validated['kavling_ids'] = array_values(array_unique(array_map('intval', $validated['kavling_ids'])));

        return $validated;
    }

    /**
     * Satu unit hanya boleh di satu SPK: tolak unit yang sudah dipakai SPK lain (pada Edit, SPK itu sendiri dikecualikan).
     */
    private function pastikanUnitBebas(Project $project, array $kavlingIds, ?int $kecualiSpkId): void
    {
        $terpakai = Kavling::whereIn('id', $kavlingIds)
            ->whereHas('spks', fn ($q) => $kecualiSpkId ? $q->where('spks.id', '!=', $kecualiSpkId) : $q)
            ->with(['spks' => fn ($q) => $kecualiSpkId ? $q->where('spks.id', '!=', $kecualiSpkId) : $q])
            ->get();

        if ($terpakai->isNotEmpty()) {
            $daftar = $terpakai->map(fn ($k) => $k->nomor_lengkap . ' (' . $k->spks->pluck('nomor_spk')->join(', ') . ')')->join(', ');

            throw ValidationException::withMessages([
                'kavling_ids' => "Unit sudah punya SPK: {$daftar}. Keluarkan dulu dari SPK itu lewat Edit SPK.",
            ]);
        }
    }

    /** Data untuk form Buat/Edit: semua unit proyek dengan penanda SPK yang memakainya. */
    private function dataForm(Project $project, ?Spk $spk): array
    {
        $kavlings = $project->kavlings()
            ->with(['tipeUnitPreset:id,nama', 'spks:id,nomor_spk'])
            ->orderByUnit()
            ->get(['id', 'kluster', 'blok', 'nomor_kavling', 'tipe_unit_preset_id', 'status_bangun_stage_id'])
            ->map(function ($k) use ($spk) {
                $lain = $k->spks->first(fn ($s) => !$spk || $s->id !== $spk->id);

                return [
                    'id'                     => $k->id,
                    'nomor_lengkap'          => $k->nomor_lengkap,
                    'kluster'                => $k->kluster,
                    'blok'                   => $k->blok,
                    'tipe_unit_preset_id'    => $k->tipe_unit_preset_id,
                    'tipe_unit_nama'         => $k->tipeUnitPreset?->nama,
                    'status_bangun_stage_id' => $k->status_bangun_stage_id,
                    // Unit yang dipakai SPK LAIN tidak bisa dipilih; unit milik SPK yang sedang diedit tetap bisa.
                    'spk_lain_nomor'         => $lain?->nomor_spk,
                ];
            });

        return [
            'project'            => ['id' => $project->id, 'nama' => $project->nama],
            'spk'                => $spk ? [
                'id'               => $spk->id,
                'nomor_spk'        => $spk->nomor_spk,
                'kontraktor_id'    => $spk->kontraktor_id,
                'tanggal_terbit'   => $spk->tanggal_terbit->format('Y-m-d'),
                'tanggal_deadline' => $spk->tanggal_deadline->format('Y-m-d'),
                'nilai_spk'        => $spk->nilai_spk !== null ? (float) $spk->nilai_spk : null,
                'catatan'          => $spk->catatan,
                'kavling_ids'      => $spk->kavlings()->pluck('kavlings.id')->all(),
            ] : null,
            'kavlings'           => $kavlings,
            'kontraktors'        => Kontraktor::where('is_active', true)->orWhere('id', $spk?->kontraktor_id)->orderBy('nama')->get(['id', 'nama']),
            'klusterOptions'     => $project->kavlings()->whereNotNull('kluster')->distinct()->orderBy('kluster')->pluck('kluster'),
            'blokOptions'        => $project->kavlings()->whereNotNull('blok')->distinct()->orderBy('blok')->pluck('blok'),
            'tipeUnitOptions'    => $project->tipeUnitPresets()->active()->orderBy('nama')->get(['id', 'nama']),
            'statusBangunStages' => StatusBangunStage::ordered()->get(['id', 'nama', 'warna']),
        ];
    }
}
