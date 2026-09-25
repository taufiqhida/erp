<?php

namespace App\Http\Controllers;

use App\Enums\StatusJual;
use App\Imports\KavlingImport;
use App\Models\Kavling;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
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
                'foto_sampul'        => $p->foto_sampul
                    ? route('media.show', ['path' => $p->foto_sampul]) : null,
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

        // Handle foto sampul upload — foto cover yang tampil di kartu Beranda
        // (alih-alih siteplan, yang isinya peta teknis bukan foto proyek).
        if ($request->hasFile('foto_sampul')) {
            $request->validate(['foto_sampul' => 'image|mimes:png,jpg,jpeg,webp|max:10240']);
            $validated['foto_sampul'] = $request->file('foto_sampul')->store('sampul', 'public');
        }

        $project = Project::create($validated);

        // Auto-assign creator sebagai anggota project
        $project->users()->attach(Auth::id());

        return redirect()->route('projects.show', $project)
            ->with('success', "Proyek {$project->nama} berhasil dibuat.");
    }

    public function show(Request $request, Project $project): Response
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

        // Set lengkap TANPA paginasi — dipakai siteplan, yang perlu semua
        // titik sekaligus untuk digambar di peta (tidak boleh terpotong
        // halaman). Filter Tabel TIDAK berlaku di sini (lihat komputed
        // kavlingsWithKoordinat di frontend).
        $kavlings = $project->kavlings()
            ->with(['activeTransaction.konsumen', 'tipeUnitPreset', 'statusBangunStage'])
            ->orderByUnit()
            ->get()
            ->map(fn($k) => $this->formatKavlingRow($k));

        // Set Tabel — dipaginasi & difilter server-side (mirip
        // KavlingController::index(), sekarang jadi satu-satunya tabel unit
        // sejak halaman "Semua Unit" terpisah dihapus).
        $kavlingsPage = $project->kavlings()
            ->when($request->kluster, fn($q) => $q->where('kluster', $request->kluster))
            ->when($request->blok, fn($q) => $q->where('blok', $request->blok))
            ->when($request->tipe_unit_preset_id, fn($q) => $q->where('tipe_unit_preset_id', $request->tipe_unit_preset_id))
            ->when($request->status_jual, fn($q) => $q->where('status_jual', $request->status_jual))
            ->when($request->status_bangun_stage_id, fn($q) => $q->where('status_bangun_stage_id', $request->status_bangun_stage_id))
            ->with(['activeTransaction.konsumen', 'tipeUnitPreset', 'statusBangunStage'])
            ->orderByUnit()
            ->paginate(20)
            ->withQueryString()
            ->through(fn($k) => $this->formatKavlingRow($k));

        $tipeUnits = $project->tipeUnitPresets()->active()->orderBy('nama')->get(['id', 'nama'])
            ->map(fn($t) => ['id' => $t->id, 'nama' => $t->nama]);

        $statusBangunStages = StatusBangunStage::ordered()->get(['id', 'nama', 'bobot', 'urutan', 'warna']);

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
            'kavlings'     => $kavlings,
            'kavlingsPage' => $kavlingsPage,
            'filters'      => $request->only(['kluster', 'blok', 'tipe_unit_preset_id', 'status_jual', 'status_bangun_stage_id']),
            'tipeUnits'    => $tipeUnits,
            'statusBangunStages' => $statusBangunStages,
            'konsumens'    => $konsumens,
        ]);
    }

    /**
     * Spek fisik (luas, kamar, material, foto fasad/denah) sepenuhnya
     * berasal dari tipeUnitPreset — Kavling sendiri tidak punya kolom spek.
     */
    private function formatKavlingRow(Kavling $k): array
    {
        $tipe = $k->tipeUnitPreset;

        return [
            'id'                => $k->id,
            'tipe_unit_preset_id' => $k->tipe_unit_preset_id,
            'tipe_unit_nama'    => $tipe?->nama,
            'kluster'           => $k->kluster,
            'nomor_kavling'     => $k->nomor_kavling,
            'blok'              => $k->blok,
            'nomor_lengkap'     => $k->nomor_lengkap,
            'svg_id'            => $k->svg_id,
            'luas_tanah'        => $tipe?->luas_tanah,
            'luas_bangunan'     => $tipe?->luas_bangunan,
            'harga'             => $k->harga,
            'status_jual'       => $k->status_jual->value,
            'status_jual_label' => $k->status_jual->label(),
            'status_bangun_stage_id' => $k->status_bangun_stage_id,
            'status_bangun_label' => $k->statusBangunStage?->nama,
            'status_bangun_color' => $k->statusBangunStage?->warna,
            'progress_bangun'   => $k->progress_bangun,
            'status_unit'       => $k->status_unit,
            'keterangan'        => $k->keterangan,
            'perlu_biaya_tambahan' => $k->perlu_biaya_tambahan,
            'koordinat_x'       => $k->koordinat_x,
            'koordinat_y'       => $k->koordinat_y,
            'konsumen_nama'     => $k->activeTransaction?->konsumen?->nama,
            'foto_rumah'        => $tipe?->foto_rumah ? route('media.show', ['path' => $tipe->foto_rumah]) : null,
            'denah_rumah'       => $tipe?->denah_rumah ? route('media.show', ['path' => $tipe->denah_rumah]) : null,
            'kamar_tidur'       => $tipe?->kamar_tidur,
            'kamar_mandi'       => $tipe?->kamar_mandi,
            'spek_atap'         => $tipe?->spek_atap,
            'spek_dinding'      => $tipe?->spek_dinding,
            'spek_lantai'       => $tipe?->spek_lantai,
            'spek_pondasi'      => $tipe?->spek_pondasi,
            'catatan'           => $k->catatan,
            'id_rumah'          => $k->id_rumah,
            'hgb_no'            => $k->hgb_no,
        ];
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
                'siteplan_image'   => $project->siteplan_image
                    ? route('media.show', ['path' => $project->siteplan_image]) : null,
                'foto_sampul'      => $project->foto_sampul
                    ? route('media.show', ['path' => $project->foto_sampul]) : null,
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

        // Handle foto sampul upload
        if ($request->hasFile('foto_sampul')) {
            $request->validate(['foto_sampul' => 'image|mimes:png,jpg,jpeg,webp|max:10240']);
            if ($project->foto_sampul) {
                Storage::disk('public')->delete($project->foto_sampul);
            }
            $validated['foto_sampul'] = $request->file('foto_sampul')->store('sampul', 'public');
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

        $headers = ['nomor_kavling', 'kluster', 'blok', 'tipe_unit', 'harga', 'status', 'status_bangun', 'keterangan', 'id_rumah', 'hgb_no', 'persen_tahap'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:K1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('7C3AED');
        $sheet->freezePane('A2');

        $defaultStageName = StatusBangunStage::defaultStage()?->nama ?? 'Belum Mulai';
        $example = ['1', '', 'A1', '36/72', 250000000, 'available', $defaultStageName, 'Contoh baris — boleh dihapus', 'DMK0120062025T002A309', 'HGB-00123', 0];
        $sheet->fromArray($example, null, 'A2');
        $sheet->getStyle('A2:K2')->getFont()->setItalic(true)->getColor()->setRGB('999999');

        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Dropdown supaya status/status_bangun tidak salah ketik — nilainya
        // harus persis sama dengan yang diterima KavlingImport. status_bangun
        // sumbernya nama tahap live dari master Kelola Status Bangun, BUKAN
        // enum hardcode lagi.
        $statusListFormula = '"available,not_for_sale"';
        $statusBangunListFormula = '"' . StatusBangunStage::ordered()->pluck('nama')->implode(',') . '"';

        for ($row = 2; $row <= 500; $row++) {
            $statusValidation = $sheet->getCell("F{$row}")->getDataValidation();
            $statusValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $statusValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $statusValidation->setAllowBlank(true);
            $statusValidation->setShowDropDown(true);
            $statusValidation->setShowErrorMessage(true);
            $statusValidation->setErrorTitle('Status tidak dikenal');
            $statusValidation->setError('Pilih salah satu dari daftar (available / not_for_sale).');
            $statusValidation->setFormula1($statusListFormula);

            $bangunValidation = $sheet->getCell("G{$row}")->getDataValidation();
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
            ['nomor_kavling', 'Ya', '1', 'Nomor unit di dalam bloknya (tanpa blok). Identitas unit = kluster + blok + nomor, jadi nomor yang sama boleh dipakai di blok/kluster berbeda. Kalau kombinasinya sudah ada atau duplikat dalam file, baris dilewati.'],
            ['kluster', 'Tidak', 'Melati', 'Kosongkan jika proyek/unit tidak punya kluster.'],
            ['blok', 'Ya', 'A1', 'Blok unit. Unit tampil sebagai "A1-1" (atau "Melati · A1-1" kalau ada kluster).'],
            ['tipe_unit', 'Ya', '36/72', 'Nama Tipe Unit — dicocokkan dengan Tipe Unit yang sudah ada di proyek ini (menu "Kelola Tipe Unit"). Kalau namanya belum ada, Tipe baru otomatis dibuat (spek kosong, lengkapi belakangan).'],
            ['harga', 'Tidak', '250000000', 'Angka saja, tanpa "Rp" atau titik ribuan.'],
            ['status', 'Tidak (default: available)', 'available / not_for_sale', 'available = tersedia dijual, not_for_sale = ditahan/belum dijual dulu.'],
            ['status_bangun', "Tidak (default: {$defaultStageName})", StatusBangunStage::ordered()->pluck('nama')->implode(' / '), 'Isi kalau proyek sudah berjalan & sebagian unit progressnya bukan dari nol — harus persis sama dengan nama tahap di menu "Kelola Status Bangun". Kosongkan untuk unit yang belum mulai dibangun.'],
            ['keterangan', 'Tidak', 'teks bebas', ''],
            ['id_rumah', 'Tidak', 'DMK0120062025T002A309', 'ID Rumah Tapera/SIKUMBANG — harus unik, kosongkan kalau belum ada.'],
            ['hgb_no', 'Tidak', 'HGB-00123', 'Nomor HGB/sertifikat unit (dipakai di dokumen surat) — harus unik, kosongkan kalau belum ada.'],
            ['persen_tahap', 'Tidak (default: 0)', '0 – 100', 'Persen penyelesaian di dalam tahap yang diisi pada kolom status_bangun (bukan persen total). Contoh: tahap Struktur 50 → unit sudah separuh jalan di tahap Struktur. Kosong = 0. Progress total dihitung otomatis dari bobot tahap.'],
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
            'rows.*.blok'             => 'required|string|max:10',
            'rows.*.tipe_unit'        => 'required|string|max:150',
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
            // Identitas unit = kluster + blok + nomor (kluster boleh kosong); lacak
            // juga yang baru dibuat dalam batch ini supaya tidak dobel dalam 1 file.
            $seen = [];
            $defaultStageId = StatusBangunStage::defaultStage()->id;

            foreach ($validated['rows'] as $i => $row) {
                $rowNum = $i + 1;
                $noUnit = trim($row['nomor_kavling']);

                $klusterRow = trim((string) ($row['kluster'] ?? ''));
                $blokRow = trim($row['blok']);
                $batchKey = mb_strtolower("{$klusterRow}|{$blokRow}|{$noUnit}");

                if (isset($seen[$batchKey]) || Kavling::identitasExists($project->id, $klusterRow !== '' ? $klusterRow : null, $blokRow, $noUnit)) {
                    $errors[] = "Baris {$rowNum}: Unit '{$blokRow}-{$noUnit}' sudah ada, dilewati.";
                    $skipped++;
                    continue;
                }

                $statusUnit = $row['status_unit'] ?? 'available';
                $tipeNama = trim($row['tipe_unit']);

                $tipePreset = TipeUnitPreset::firstOrCreate(
                    ['project_id' => $project->id, 'nama' => $tipeNama],
                    ['luas_tanah' => $row['luas_tanah'] ?: null, 'luas_bangunan' => $row['luas_bangunan'] ?: null]
                );
                if ($tipePreset->wasRecentlyCreated) {
                    $errors[] = "Baris {$rowNum}: Tipe Unit '{$tipeNama}' belum ada di proyek ini, dibuat otomatis — lengkapi spek lengkapnya di halaman Kelola Tipe Unit.";
                }

                Kavling::create([
                    'project_id'    => $project->id,
                    'kluster'       => $row['kluster'] ?: null,
                    'nomor_kavling' => $noUnit,
                    'blok'          => $row['blok'] ?: null,
                    'tipe_unit_preset_id' => $tipePreset->id,
                    'harga'         => $row['harga'] ?: null,
                    'keterangan'    => $row['keterangan'] ?: null,
                    'status_unit'   => $statusUnit,
                    'status_jual'   => $statusUnit === 'not_for_sale' ? StatusJual::Hold : StatusJual::Available,
                    'status_bangun_stage_id' => $defaultStageId,
                ]);

                $seen[$batchKey] = true;
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
