<?php

namespace App\Http\Controllers;

use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\DokumenKonsumen;
use App\Models\DokumenTemplate;
use App\Models\Kavling;
use App\Models\Konsumen;
use App\Models\KavlingKonsumen;
use App\Models\Project;
use App\Models\UnitSwapHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    use AuthorizesProjectAccess;

    /**
     * Halaman Penjualan: Pilih Proyek
     */
    public function projectList(): Response
    {
        $user = Auth::user();
        $isSuperAdminOrManajer = $user->hasAnyRole(['superadmin', 'manajer']);

        $projects = Project::query()
            ->when(!$isSuperAdminOrManajer, fn($q) =>
                $q->whereHas('users', fn($q2) => $q2->where('users.id', $user->id))
            )
            ->where('is_active', true)
            ->withCount([
                'kavlings',
                'kavlings as kavlings_available_count' => fn($q) => $q->where('status_jual', 'available'),
                'kavlings as kavlings_booked_count'    => fn($q) => $q->where('status_jual', 'booked'),
                'kavlings as kavlings_sold_count'      => fn($q) => $q->where('status_jual', 'sold'),
            ])
            ->orderBy('nama')
            ->get()
            ->map(fn($p) => [
                'id'                   => $p->id,
                'nama'                 => $p->nama,
                'kode'                 => $p->kode,
                'kota'                 => $p->kota,
                'lokasi'               => $p->lokasi,
                'kavlings_count'       => $p->kavlings_count,
                'kavlings_available'   => $p->kavlings_available_count,
                'kavlings_booked'      => $p->kavlings_booked_count,
                'kavlings_sold'        => $p->kavlings_sold_count,
                'progress'             => $p->progress_persentase,
                'siteplan_image'       => $p->siteplan_image
                    ? \Storage::disk('public')->url($p->siteplan_image) : null,
            ]);

        return Inertia::render('Penjualan/Index', [
            'projects' => $projects,
        ]);
    }

    /**
     * Halaman detail penjualan satu proyek (siteplan + tabel)
     */
    public function projectDetail(Request $request, Project $project): Response
    {
        $this->authorizeProjectAccess($project);

        $project->loadCount([
            'kavlings',
            'kavlings as kavlings_available_count' => fn($q) => $q->where('status_jual', 'available'),
            'kavlings as kavlings_booked_count'    => fn($q) => $q->where('status_jual', 'booked'),
            'kavlings as kavlings_sold_count'      => fn($q) => $q->where('status_jual', 'sold'),
        ]);

        $kavlings = $project->kavlings()
            ->with(['activeTransaction.konsumen'])
            ->orderBy('blok')
            ->orderBy('nomor_kavling')
            ->get()
            ->map(fn($k) => [
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
                'status_unit'         => $k->status_unit,
                'keterangan'          => $k->keterangan,
                'koordinat_x'         => $k->koordinat_x,
                'koordinat_y'         => $k->koordinat_y,
                'konsumen_nama'       => $k->activeTransaction?->konsumen?->nama,
                'konsumen_id'         => $k->activeTransaction?->konsumen_id,
                'transaksi_id'        => $k->activeTransaction?->id,
                'status_penjualan'    => $k->activeTransaction?->status_penjualan,
            ]);

        $pembiayaan = $project->pembiayaan;

        return Inertia::render('Penjualan/Project', [
            'project'  => [
                'id'                   => $project->id,
                'nama'                 => $project->nama,
                'kode'                 => $project->kode,
                'kota'                 => $project->kota,
                'kavlings_count'       => $project->kavlings_count,
                'kavlings_available'   => $project->kavlings_available_count,
                'kavlings_booked'      => $project->kavlings_booked_count,
                'kavlings_sold'        => $project->kavlings_sold_count,
                'siteplan_image'       => $project->siteplan_image
                    ? \Storage::disk('public')->url($project->siteplan_image) : null,
            ],
            'kavlings' => $kavlings,
            'konsumens' => Konsumen::orderBy('nama')->get(['id', 'nama', 'no_hp', 'nik']),
            'pembiayaan' => $pembiayaan ? [
                'kpr_subsidi_config'  => $pembiayaan->kpr_subsidi_config ?? \App\Models\PembiayaanProyek::defaultKprSubsidi(),
                'kpr_komersil_config' => $pembiayaan->kpr_komersil_config ?? [],
            ] : null,
        ]);
    }

    /**
     * Proses booking kavling
     */
    public function store(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('book kavling'), 403);

        $validated = $request->validate([
            // Konsumen
            'konsumen_id'         => 'nullable|exists:konsumens,id',
            'konsumen_nama'       => 'nullable|required_without:konsumen_id|string|max:100',
            'konsumen_no_hp'      => 'nullable|string|max:20',
            // Booking detail
            'booking_fee'         => 'required|numeric|min:0',
            'cara_bayar'          => 'required|in:cash,cash_bertahap,kpr_subsidi,kpr_komersil',
            'cicilan_kali'        => 'nullable|integer|min:1|max:360',
            'skema_dp'            => 'nullable|string|max:100',
            'plafon_kpr'          => 'nullable|numeric|min:0',
            'harga_deal'          => 'nullable|numeric|min:0',
            'catatan'             => 'nullable|string',
        ]);

        DB::transaction(function () use ($kavling, $validated) {
            // Lock baris kavling supaya dua request booking bersamaan tidak
            // lolos cek status yang sama sebelum salah satunya commit.
            $locked = Kavling::whereKey($kavling->id)->lockForUpdate()->firstOrFail();

            abort_if(
                !in_array($locked->status_jual, [StatusJual::Available, StatusJual::Hold]),
                422,
                'Kavling tidak tersedia untuk dibooking.'
            );

            // Buat atau pakai konsumen existing
            if (!empty($validated['konsumen_id'])) {
                $konsumen = Konsumen::findOrFail($validated['konsumen_id']);
            } else {
                $konsumen = Konsumen::create([
                    'nama'  => $validated['konsumen_nama'],
                    'no_hp' => $validated['konsumen_no_hp'] ?? null,
                ]);
            }

            // Buat transaksi
            $transaksi = KavlingKonsumen::create([
                'kavling_id'       => $kavling->id,
                'konsumen_id'      => $konsumen->id,
                'status'           => 'active',
                'harga_deal'       => $validated['harga_deal'] ?? $kavling->harga,
                'booking_fee'      => $validated['booking_fee'],
                'cara_bayar'       => $validated['cara_bayar'],
                'cicilan_kali'     => $validated['cicilan_kali'] ?? null,
                'skema_dp'         => $validated['skema_dp'] ?? null,
                'plafon_kpr'       => $validated['plafon_kpr'] ?? null,
                'status_penjualan' => 'booking',
                'catatan'          => $validated['catatan'] ?? null,
                'created_by'       => Auth::id(),
            ]);

            // Rekam booking fee sebagai pembayaran pertama
            if ($validated['booking_fee'] > 0) {
                $transaksi->pembayarans()->create([
                    'jenis'        => 'booking_fee',
                    'jumlah'       => $validated['booking_fee'],
                    'tanggal_bayar' => now()->toDateString(),
                    'keterangan'   => 'Booking Fee / UTJ',
                    'created_by'   => Auth::id(),
                ]);
            }

            // Auto-generate checklist dokumen dari template
            $templates = DokumenTemplate::where('cara_bayar', $validated['cara_bayar'])
                ->orderBy('urutan')
                ->get();

            foreach ($templates as $template) {
                DokumenKonsumen::create([
                    'kavling_konsumen_id' => $transaksi->id,
                    'nama_dokumen'        => $template->nama_dokumen,
                    'sifat'               => $template->sifat,
                    'status'              => 'belum_ada',
                ]);
            }

            // Update status kavling
            $locked->update(['status_jual' => StatusJual::Booked]);
        });

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} berhasil dibooking.");
    }

    /**
     * Update status penjualan untuk transisi "sederhana" (tanpa keputusan/gate
     * khusus): booking → pemberkasan → proses_bank, dan rencana_akad → akad.
     * Transisi ke 'sp3k' dan 'rencana_akad' TIDAK lewat sini — itu hanya bisa
     * terjadi otomatis lewat endpoint Keputusan Bank / Keputusan SP3K masing-
     * masing, supaya gate dokumen & checklist selalu ditegakkan.
     * Stage "bast" juga tidak diset di sini — itu lewat fitur BAST tersendiri.
     */
    public function updateStatus(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);

        $validated = $request->validate([
            'status_penjualan'     => 'required|in:booking,pemberkasan,proses_bank,akad,batal',
            'tanggal_rencana_akad' => 'nullable|date',
            'catatan'              => 'nullable|string',
        ]);

        if ($validated['status_penjualan'] === 'proses_bank') {
            abort_unless(
                $kk->dokumen_wajib_lengkap,
                422,
                'Semua dokumen wajib harus berstatus "Sudah Ada" sebelum lanjut ke Proses Bank.'
            );
            $validated['tanggal_pengajuan_bank'] = now();
            $validated['status_bank'] = 'diajukan';
        }

        $kk->update($validated);

        // Jika akad, update status kavling ke sold
        if ($validated['status_penjualan'] === 'akad') {
            $kk->kavling->update(['status_jual' => StatusJual::Sold]);
            $kk->update(['status' => 'completed', 'tanggal_akad' => now()]);
        }

        // Jika batal, update status kavling kembali ke available
        if ($validated['status_penjualan'] === 'batal') {
            $kk->kavling->update(['status_jual' => StatusJual::Available]);
            $kk->update(['status' => 'cancelled']);
        }

        return back()->with('success', 'Status penjualan berhasil diperbarui.');
    }

    /**
     * Simpan keputusan Proses Bank/SLIK. Kalau disetujui, otomatis lanjut
     * ke tahap SP3K. Kalau ditolak, transaksi tetap di Proses Bank sampai
     * staff KPR memilih langkah berikutnya (kembali ke Pemberkasan / batalkan).
     */
    public function updateBankDecision(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);
        abort_unless($kk->status_penjualan === 'proses_bank', 422, 'Transaksi tidak sedang di tahap Proses Bank.');

        $validated = $request->validate([
            'status_bank'           => 'required|in:diajukan,disetujui,ditolak',
            'tanggal_keputusan_bank' => 'nullable|date',
            'catatan_bank'          => 'nullable|string',
        ]);

        $kk->update($validated);

        if ($validated['status_bank'] === 'disetujui') {
            $kk->update(['status_penjualan' => 'sp3k']);
        }

        return back()->with('success', 'Keputusan Proses Bank berhasil disimpan.');
    }

    /**
     * Simpan keputusan SP3K + checklist (rekening KPR & biaya akad). Lanjut
     * ke Rencana Akad hanya kalau status disetujui/turun_plafon DAN kedua
     * checklist sudah dicentang.
     */
    public function updateSp3kDecision(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);
        abort_unless($kk->status_penjualan === 'sp3k', 422, 'Transaksi tidak sedang di tahap SP3K.');

        $validated = $request->validate([
            'tanggal_sp3k'           => 'nullable|date',
            'tanggal_expired_sp3k'   => 'nullable|date',
            'status_sp3k'            => 'required|in:approved,turun_plafon,ditolak',
            'tanggal_disetujui_sp3k' => 'nullable|date',
            'catatan_sp3k'           => 'nullable|string',
            'plafon_baru'            => 'required_if:status_sp3k,turun_plafon|nullable|numeric|min:0',
            'rekening_kpr_dibuka'    => 'boolean',
            'biaya_akad_lunas'       => 'boolean',
        ]);

        $update = [
            'tanggal_sp3k'           => $validated['tanggal_sp3k'] ?? $kk->tanggal_sp3k,
            'tanggal_expired_sp3k'   => $validated['tanggal_expired_sp3k'] ?? $kk->tanggal_expired_sp3k,
            'status_sp3k'            => $validated['status_sp3k'],
            'tanggal_disetujui_sp3k' => $validated['tanggal_disetujui_sp3k'] ?? null,
            'catatan_sp3k'           => $validated['catatan_sp3k'] ?? null,
            'rekening_kpr_dibuka'    => $validated['rekening_kpr_dibuka'] ?? false,
            'biaya_akad_lunas'       => $validated['biaya_akad_lunas'] ?? false,
        ];

        if ($validated['status_sp3k'] === 'turun_plafon') {
            $update['plafon_kpr'] = $validated['plafon_baru'];
        }

        $checklistComplete = $update['rekening_kpr_dibuka'] && $update['biaya_akad_lunas'];
        $canAdvance = in_array($validated['status_sp3k'], ['approved', 'turun_plafon']) && $checklistComplete;

        if ($canAdvance) {
            $update['status_penjualan'] = 'rencana_akad';
        }

        $kk->update($update);

        return back()->with('success', $canAdvance
            ? 'Keputusan SP3K disimpan, transaksi lanjut ke Rencana Akad.'
            : 'Keputusan SP3K disimpan.');
    }

    /**
     * Kembalikan transaksi ke tahap Pemberkasan (dipakai saat Proses Bank
     * atau SP3K ditolak dan perlu revisi berkas).
     */
    public function revertToPemberkasan(KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);
        abort_unless(in_array($kk->status_penjualan, ['proses_bank', 'sp3k']), 422, 'Transaksi tidak bisa dikembalikan dari tahap ini.');

        $kk->update([
            'status_penjualan' => 'pemberkasan',
            'status_bank'      => null,
            'status_sp3k'      => null,
        ]);

        return back()->with('success', 'Transaksi dikembalikan ke tahap Pemberkasan untuk revisi berkas.');
    }

    /**
     * Tukar Unit / Pindah Kavling: pindahkan transaksi ke kavling lain yang
     * masih available (di proyek yang sama), tanpa kehilangan histori
     * pembayaran/dokumen (semua FK ke kavling_konsumen_id, bukan kavling_id).
     */
    public function swapUnit(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('swap kavling'), 403);

        $validated = $request->validate([
            'kavling_baru_id' => 'required|exists:kavlings,id',
            'alasan'          => 'nullable|string|max:500',
        ]);

        abort_if($kk->status !== 'active', 422, 'Hanya transaksi aktif yang bisa tukar unit.');
        abort_if((int) $validated['kavling_baru_id'] === (int) $kk->kavling_id, 422, 'Kavling baru harus berbeda dengan kavling saat ini.');

        DB::transaction(function () use ($kk, $validated) {
            $kavlingLama = Kavling::whereKey($kk->kavling_id)->lockForUpdate()->firstOrFail();
            $kavlingBaru = Kavling::whereKey($validated['kavling_baru_id'])->lockForUpdate()->firstOrFail();

            abort_if(
                $kavlingBaru->project_id !== $kavlingLama->project_id,
                422,
                'Tukar unit hanya bisa dilakukan ke kavling dalam proyek yang sama.'
            );
            abort_if(
                $kavlingBaru->status_jual !== StatusJual::Available,
                422,
                'Kavling tujuan tidak tersedia.'
            );

            $statusLama = $kavlingLama->status_jual;

            UnitSwapHistory::create([
                'kavling_konsumen_id' => $kk->id,
                'kavling_lama_id'     => $kavlingLama->id,
                'kavling_baru_id'     => $kavlingBaru->id,
                'user_id'             => Auth::id(),
                'alasan'              => $validated['alasan'] ?? null,
            ]);

            $kavlingLama->update(['status_jual' => StatusJual::Available]);
            $kavlingBaru->update(['status_jual' => $statusLama]);
            $kk->update(['kavling_id' => $kavlingBaru->id]);
        });

        return back()->with('success', 'Unit berhasil ditukar.');
    }
}
