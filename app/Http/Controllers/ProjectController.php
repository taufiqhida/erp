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
                $q->where(fn($q2) => $q2
                    ->where('nama', 'like', "%{$request->search}%")
                    ->orWhere('kode', 'like', "%{$request->search}%")
                    ->orWhere('kota', 'like', "%{$request->search}%")
                )
            )
            ->when($request->has('is_active'), fn($q) =>
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN))
            )
            ->withCount([
                'kavlings',
                'kavlings as kavlings_sold_count'      => fn($q) => $q->where('status_jual', 'sold'),
                'kavlings as kavlings_available_count' => fn($q) => $q->where('status_jual', 'available'),
                'kavlings as kavlings_booked_count'    => fn($q) => $q->where('status_jual', 'booked'),
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
                'siteplan_image'     => $p->siteplan_image
                    ? route('media.show', ['path' => $p->siteplan_image]) : null,
                'kavlings_count'     => $p->kavlings_count,
                'kavlings_sold'      => $p->kavlings_sold_count,
                'kavlings_available' => $p->kavlings_available_count,
                'kavlings_booked'    => $p->kavlings_booked_count,
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

        // Membuka detail proyek = mengaktifkan proyek ini sebagai context
        // global (dipakai Konsumen/Keuangan/Pembatalan & switcher header) —
        // tidak ada endpoint "set" terpisah, cukup piggyback di sini.
        session(['current_project_id' => $project->id]);

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
                'kluster'           => $k->kluster,
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
                'foto_rumah'        => $k->foto_rumah ? route('media.show', ['path' => $k->foto_rumah]) : null,
                'denah_rumah'       => $k->denah_rumah ? route('media.show', ['path' => $k->denah_rumah]) : null,
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
                    ? route('media.show', ['path' => $project->siteplan_image]) : null,
                'siteplan_marker_size'     => $project->siteplan_marker_size,
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
     * Simpan ukuran marker siteplan (global per-proyek, bukan per-unit).
     * Nilai ini juga dipakai (read-only) oleh halaman Penjualan supaya
     * tampilan siteplan konsisten antara admin proyek dan sales.
     */
    public function updateSiteplanMarkerSize(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'siteplan_marker_size' => 'required|integer|min:12|max:56',
        ]);

        $project->update($validated);

        return back();
    }

    /**
     * Download template Excel untuk bulk import kavling — kolom & urutan
     * di sini HARUS persis sama dengan yang dibaca KavlingImport, supaya
     * template yang diunduh selalu sinkron dengan validasi backend.
     */
    public function downloadKavlingTemplate(Project $project)
    {
        $this->authorize('update', $project);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // ── Sheet 1: Kavling (diisi user) ──
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kavling');

        $headers = ['nomor_kavling', 'kluster', 'blok', 'tipe_unit', 'luas_tanah', 'luas_bangunan', 'harga', 'status', 'status_bangun', 'keterangan'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:J1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('7C3AED');
        $sheet->freezePane('A2');

        $example = ['A-01', 'Kluster Melati', 'A', '36/72', 72, 36, 250000000, 'available', 'not_started', 'Contoh baris — boleh dihapus'];
        $sheet->fromArray($example, null, 'A2');
        $sheet->getStyle('A2:J2')->getFont()->setItalic(true)->getColor()->setRGB('999999');

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Dropdown supaya status/status_bangun tidak salah ketik — nilainya
        // harus persis sama dengan yang diterima KavlingImport.
        $statusListFormula = '"available,not_for_sale"';
        $statusBangunListFormula = '"' . implode(',', StatusBangun::values()) . '"';

        for ($row = 2; $row <= 500; $row++) {
            $statusValidation = $sheet->getCell("H{$row}")->getDataValidation();
            $statusValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $statusValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $statusValidation->setAllowBlank(true);
            $statusValidation->setShowDropDown(true);
            $statusValidation->setShowErrorMessage(true);
            $statusValidation->setErrorTitle('Status tidak dikenal');
            $statusValidation->setError('Pilih salah satu dari daftar (available / not_for_sale).');
            $statusValidation->setFormula1($statusListFormula);

            $bangunValidation = $sheet->getCell("I{$row}")->getDataValidation();
            $bangunValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $bangunValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $bangunValidation->setAllowBlank(true);
            $bangunValidation->setShowDropDown(true);
            $bangunValidation->setShowErrorMessage(true);
            $bangunValidation->setErrorTitle('Status Bangun tidak dikenal');
            $bangunValidation->setError('Pilih salah satu dari daftar, atau kosongkan.');
            $bangunValidation->setFormula1($statusBangunListFormula);
        }

        // ── Sheet 2: Petunjuk ──
        $help = $spreadsheet->createSheet();
        $help->setTitle('Petunjuk');

        $help->fromArray(['Kolom', 'Wajib?', 'Format / Contoh', 'Keterangan'], null, 'A1');
        $help->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $help->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('7C3AED');

        $helpRows = [
            ['nomor_kavling', 'Ya', 'A-01', 'Harus unik per proyek — kalau sudah ada atau duplikat dalam file, baris dilewati.'],
            ['kluster', 'Tidak', 'Kluster Melati', 'Kosongkan jika proyek tidak punya kluster.'],
            ['blok', 'Tidak', 'A', ''],
            ['tipe_unit', 'Tidak', '36/72', ''],
            ['luas_tanah', 'Tidak', '72', 'Angka saja, satuan m².'],
            ['luas_bangunan', 'Tidak', '36', 'Angka saja, satuan m².'],
            ['harga', 'Tidak', '250000000', 'Angka saja, tanpa "Rp" atau titik ribuan.'],
            ['status', 'Tidak (default: available)', 'available / not_for_sale', 'available = tersedia dijual, not_for_sale = ditahan/belum dijual dulu.'],
            ['status_bangun', 'Tidak (default: not_started)', implode(' / ', StatusBangun::values()), 'Isi kalau proyek sudah berjalan & sebagian unit progressnya bukan dari nol. Kosongkan untuk unit yang belum mulai dibangun.'],
            ['keterangan', 'Tidak', 'teks bebas', ''],
        ];
        $help->fromArray($helpRows, null, 'A2');
        $help->getStyle('A1:D' . (count($helpRows) + 1))->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
        foreach (range('A', 'D') as $col) {
            $help->getColumnDimension($col)->setAutoSize(true);
        }
        $help->getColumnDimension('D')->setWidth(60);

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'template-import-kavling.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
            'rows.*.kluster'          => 'nullable|string|max:50',
            'rows.*.blok'             => 'nullable|string|max:10',
            'rows.*.tipe_unit'        => 'nullable|string|max:150',
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
                    'kluster'       => $row['kluster'] ?: null,
                    'nomor_kavling' => $noUnit,
                    'blok'          => $row['blok'] ?: null,
                    'tipe_unit'     => $row['tipe_unit'] ?: null,
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

        return redirect()->route('beranda')
            ->with('success', "Proyek {$project->nama} berhasil dihapus.");
    }

    /**
     * Keluar dari context 1 proyek spesifik ke mode "Semua Proyek" —
     * dipakai dari kartu "Semua Proyek" di Halaman Utama Pilih Proyek.
     */
    public function clearActiveProject(): RedirectResponse
    {
        session()->forget('current_project_id');

        return redirect()->route('dashboard');
    }
}
