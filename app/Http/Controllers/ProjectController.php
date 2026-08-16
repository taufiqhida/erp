<?php

namespace App\Http\Controllers;

use App\Enums\StatusBangun;
use App\Enums\StatusJual;
use App\Imports\KavlingImport;
use App\Models\Kavling;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $user = Auth::user();

        $projects = Project::query()
            ->when(!$user->hasAnyRole(['superadmin', 'manajer']), fn($q) =>
                $q->whereHas('users', fn($q2) => $q2->where('users.id', $user->id))
            )
            ->when($request->search, fn($q) =>
                $q->where('nama', 'like', "%{$request->search}%")
                  ->orWhere('kode', 'like', "%{$request->search}%")
                  ->orWhere('kota', 'like', "%{$request->search}%")
            )
            ->when($request->has('is_active'), fn($q) =>
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN))
            )
            ->withCount([
                'kavlings',
                'kavlings as kavlings_sold_count'      => fn($q) => $q->where('status_jual', 'sold'),
                'kavlings as kavlings_available_count' => fn($q) => $q->where('status_jual', 'available'),
            ])
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn($p) => [
                'id'                 => $p->id,
                'nama'               => $p->nama,
                'kode'               => $p->kode,
                'deskripsi'          => $p->deskripsi,
                'lokasi'             => $p->lokasi,
                'kota'               => $p->kota,
                'luas_tanah_total'   => $p->luas_tanah_total,
                'is_active'          => $p->is_active,
                'kavlings_count'     => $p->kavlings_count,
                'kavlings_sold'      => $p->kavlings_sold_count,
                'kavlings_available' => $p->kavlings_available_count,
                'progress'           => $p->progress_persentase,
                'creator'            => $p->creator?->name,
            ]);

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters'  => $request->only(['search', 'is_active']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Project::class);

        return Inertia::render('Projects/Form', [
            'project' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validate([
            'nama'             => 'required|string|max:100',
            'kode'             => 'required|string|max:20|unique:projects,kode',
            'deskripsi'        => 'nullable|string',
            'lokasi'           => 'nullable|string',
            'kota'             => 'nullable|string|max:50',
            'luas_tanah_total' => 'nullable|numeric|min:0',
            'is_active'        => 'boolean',
        ]);

        $validated['created_by'] = Auth::id();

        // Handle siteplan upload
        if ($request->hasFile('siteplan_image')) {
            $request->validate(['siteplan_image' => 'image|mimes:png,jpg,jpeg,svg|max:10240']);
            $validated['siteplan_image'] = $request->file('siteplan_image')->store('siteplan', 'public');
        }

        $project = Project::create($validated);

        // Auto-assign creator sebagai anggota project
        $project->users()->attach(Auth::id());

        return redirect()->route('projects.show', $project)
            ->with('success', "Proyek {$project->nama} berhasil dibuat.");
    }

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        $project->loadCount([
            'kavlings',
            'kavlings as kavlings_sold_count'      => fn($q) => $q->where('status_jual', 'sold'),
            'kavlings as kavlings_available_count' => fn($q) => $q->where('status_jual', 'available'),
            'kavlings as kavlings_hold_count'      => fn($q) => $q->where('status_jual', 'hold'),
            'kavlings as kavlings_booked_count'    => fn($q) => $q->where('status_jual', 'booked'),
        ]);

        $kavlings = $project->kavlings()
            ->with(['activeTransaction.konsumen'])
            ->orderBy('blok')
            ->orderBy('nomor_kavling')
            ->get()
            ->map(fn($k) => [
                'id'                => $k->id,
                'nomor_kavling'     => $k->nomor_kavling,
                'blok'              => $k->blok,
                'nomor_lengkap'     => $k->nomor_lengkap,
                'svg_id'            => $k->svg_id,
                'luas_tanah'        => $k->luas_tanah,
                'luas_bangunan'     => $k->luas_bangunan,
                'harga'             => $k->harga,
                'status_jual'       => $k->status_jual->value,
                'status_jual_label' => $k->status_jual->label(),
                'status_jual_color' => $k->status_jual->color(),
                'status_bangun'     => $k->status_bangun->value,
                'status_bangun_label' => $k->status_bangun->label(),
                'status_bangun_color' => $k->status_bangun->color(),
                'progress_bangun'   => $k->progress_bangun,
                'status_unit'       => $k->status_unit,
                'keterangan'        => $k->keterangan,
                'koordinat_x'       => $k->koordinat_x,
                'koordinat_y'       => $k->koordinat_y,
                'konsumen_nama'     => $k->activeTransaction?->konsumen?->nama,
                'foto_rumah'        => $k->foto_rumah ? Storage::disk('public')->url($k->foto_rumah) : null,
                'denah_rumah'       => $k->denah_rumah ? Storage::disk('public')->url($k->denah_rumah) : null,
                'tipe_unit'         => $k->tipe_unit,
                'kamar_tidur'       => $k->kamar_tidur,
                'kamar_mandi'       => $k->kamar_mandi,
                'spek_atap'         => $k->spek_atap,
                'spek_dinding'      => $k->spek_dinding,
                'spek_lantai'       => $k->spek_lantai,
                'spek_pondasi'      => $k->spek_pondasi,
                'catatan'           => $k->catatan,
            ]);

        $statusBangunSummary = \App\Enums\StatusBangun::cases();

        $konsumens = Konsumen::orderBy('nama')
            ->get(['id', 'nama', 'no_hp']);

        return Inertia::render('Projects/Show', [
            'project'   => [
                'id'                       => $project->id,
                'nama'                     => $project->nama,
                'kode'                     => $project->kode,
                'deskripsi'                => $project->deskripsi,
                'lokasi'                   => $project->lokasi,
                'kota'                     => $project->kota,
                'luas_tanah_total'         => $project->luas_tanah_total,
                'is_active'                => $project->is_active,
                'siteplan_image'           => $project->siteplan_image
                    ? Storage::disk('public')->url($project->siteplan_image) : null,
                'kavlings_count'           => $project->kavlings_count,
                'kavlings_sold'            => $project->kavlings_sold_count,
                'kavlings_available'       => $project->kavlings_available_count,
                'kavlings_hold'            => $project->kavlings_hold_count,
                'kavlings_booked'          => $project->kavlings_booked_count,
                'progress'                 => $project->progress_persentase,
            ],
            'kavlings'  => $kavlings,
            'konsumens' => $konsumens,
        ]);
    }

    public function edit(Project $project): Response
    {
        $this->authorize('update', $project);

        return Inertia::render('Projects/Form', [
            'project' => [
                'id'               => $project->id,
                'nama'             => $project->nama,
                'kode'             => $project->kode,
                'deskripsi'        => $project->deskripsi,
                'lokasi'           => $project->lokasi,
                'kota'             => $project->kota,
                'luas_tanah_total' => $project->luas_tanah_total,
                'is_active'        => $project->is_active,
            ],
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'nama'             => 'required|string|max:100',
            'kode'             => "required|string|max:20|unique:projects,kode,{$project->id}",
            'deskripsi'        => 'nullable|string',
            'lokasi'           => 'nullable|string',
            'kota'             => 'nullable|string|max:50',
            'luas_tanah_total' => 'nullable|numeric|min:0',
            'is_active'        => 'boolean',
        ]);

        // Handle siteplan upload
        if ($request->hasFile('siteplan_image')) {
            $request->validate(['siteplan_image' => 'image|mimes:png,jpg,jpeg,svg|max:10240']);
            if ($project->siteplan_image) {
                Storage::disk('public')->delete($project->siteplan_image);
            }
            $validated['siteplan_image'] = $request->file('siteplan_image')->store('siteplan', 'public');
        }

        $project->update($validated);

        return redirect()->route('projects.show', $project)
            ->with('success', "Proyek {$project->nama} berhasil diperbarui.");
    }

    /**
     * Upload siteplan (endpoint terpisah dari update)
     */
    public function uploadSiteplan(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $request->validate([
            'siteplan_image' => 'required|image|mimes:png,jpg,jpeg,svg|max:10240',
        ]);

        if ($project->siteplan_image) {
            Storage::disk('public')->delete($project->siteplan_image);
        }

        $path = $request->file('siteplan_image')->store('siteplan', 'public');
        $project->update(['siteplan_image' => $path]);

        return back()->with('success', 'Siteplan berhasil diupload.');
    }

    /**
     * Simpan koordinat titik unit di siteplan (batch update)
     */
    public function updateKavlingKoordinat(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $request->validate([
            'kavlings'            => 'required|array',
            'kavlings.*.id'       => 'required|integer|exists:kavlings,id',
            'kavlings.*.koordinat_x' => 'nullable|numeric|min:0|max:100',
            'kavlings.*.koordinat_y' => 'nullable|numeric|min:0|max:100',
        ]);

        foreach ($request->kavlings as $data) {
            $project->kavlings()
                ->where('id', $data['id'])
                ->update([
                    'koordinat_x' => $data['koordinat_x'],
                    'koordinat_y' => $data['koordinat_y'],
                ]);
        }

        return back()->with('success', 'Posisi siteplan berhasil disimpan.');
    }

    /**
     * Import kavling dari file Excel
     */
    public function importKavling(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $import = new KavlingImport($project);
        Excel::import($import, $request->file('file'));

        $msg = "Import selesai: {$import->imported} kavling berhasil ditambahkan";
        if ($import->skipped > 0) {
            $msg .= ", {$import->skipped} dilewati";
        }

        if (!empty($import->errors)) {
            return back()->with('warning', $msg)->with('importErrors', $import->errors);
        }

        return back()->with('success', $msg . '.');
    }

    /**
     * Import kavling dari baris CSV yang sudah di-parse & di-mapping kolomnya
     * di client (lihat Components/CsvImportModal.vue). Reuse aturan bisnis
     * yang sama dengan KavlingImport (cek duplikasi nomor_kavling per proyek).
     */
    public function importKavlingMapped(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'rows'                    => 'required|array|min:1|max:1000',
            'rows.*.nomor_kavling'    => 'required|string|max:20',
            'rows.*.blok'             => 'nullable|string|max:10',
            'rows.*.luas_tanah'       => 'nullable|numeric|min:0',
            'rows.*.luas_bangunan'    => 'nullable|numeric|min:0',
            'rows.*.harga'            => 'nullable|numeric|min:0',
            'rows.*.status_unit'      => 'nullable|in:available,not_for_sale',
            'rows.*.keterangan'       => 'nullable|string|max:255',
        ]);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($project, $validated, &$imported, &$skipped, &$errors) {
            // Cek duplikasi terhadap kavling existing SEKALI di awal, plus lacak
            // nomor yang baru dibuat dalam batch ini supaya tidak dobel dalam 1 file.
            $existing = $project->kavlings()->pluck('nomor_kavling')->flip();

            foreach ($validated['rows'] as $i => $row) {
                $rowNum = $i + 1;
                $noUnit = trim($row['nomor_kavling']);

                if (isset($existing[$noUnit])) {
                    $errors[] = "Baris {$rowNum}: No Unit '{$noUnit}' sudah ada, dilewati.";
                    $skipped++;
                    continue;
                }

                $statusUnit = $row['status_unit'] ?? 'available';

                Kavling::create([
                    'project_id'    => $project->id,
                    'nomor_kavling' => $noUnit,
                    'blok'          => $row['blok'] ?: null,
                    'luas_tanah'    => $row['luas_tanah'] ?: null,
                    'luas_bangunan' => $row['luas_bangunan'] ?: null,
                    'harga'         => $row['harga'] ?: null,
                    'keterangan'    => $row['keterangan'] ?: null,
                    'status_unit'   => $statusUnit,
                    'status_jual'   => $statusUnit === 'not_for_sale' ? StatusJual::Hold : StatusJual::Available,
                    'status_bangun' => StatusBangun::NotStarted,
                ]);

                $existing[$noUnit] = true;
                $imported++;
            }
        });

        $msg = "Import selesai: {$imported} kavling berhasil ditambahkan";
        if ($skipped > 0) {
            $msg .= ", {$skipped} dilewati";
        }

        if (!empty($errors)) {
            return back()->with('warning', $msg)->with('importErrors', $errors);
        }

        return back()->with('success', $msg . '.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()->route('projects.index')
            ->with('success', "Proyek {$project->nama} berhasil dihapus.");
    }
}
