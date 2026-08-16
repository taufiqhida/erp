<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\KavlingKonsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\SbumRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class KeuanganController extends Controller
{
    use AuthorizesProjectAccess;

    /**
     * Halaman Keuangan – 3 tab
     */
    public function index(Request $request): Response
    {
        abort_unless(Auth::user()->can('view keuangan'), 403);

        $user = Auth::user();
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);
        $projectId = $request->project_id;

        // ── Tab 1: Pembayaran Konsumen ────────────────────────────────
        $pembayaranQuery = KavlingKonsumen::query()
            ->with(['konsumen:id,nama', 'kavling.project:id,nama'])
            ->whereHas('kavling', function ($q) use ($projectId, $isGlobal, $user) {
                if ($projectId) $q->where('project_id', $projectId);
                if (!$isGlobal) $q->whereHas('project.users', fn($q2) => $q2->where('users.id', $user->id));
            })
            ->where('status', 'active')
            ->withSum('pembayarans', 'jumlah')
            ->orderByDesc('created_at');

        $pembayaran = $pembayaranQuery->get()->map(fn($kk) => [
            'id'             => $kk->id,
            'konsumen_nama'  => $kk->konsumen->nama,
            'konsumen_id'    => $kk->konsumen_id,
            'kavling'        => $kk->kavling->nomor_lengkap,
            'project'        => $kk->kavling->project->nama,
            'cara_bayar'     => $kk->cara_bayar,
            'harga_deal'     => $kk->harga_deal,
            'booking_fee'    => $kk->booking_fee,
            'total_terbayar' => (float) $kk->pembayarans_sum_jumlah,
            'status_penjualan' => $kk->status_penjualan,
            'status_penjualan_label' => $kk->status_penjualan_label,
        ]);

        // ── Tab 2: Pencairan KPR & Dajam ─────────────────────────────
        $kprQuery = KavlingKonsumen::query()
            ->with(['konsumen:id,nama', 'kavling.project:id,nama'])
            ->whereIn('cara_bayar', ['kpr_subsidi', 'kpr_komersil'])
            ->whereHas('kavling', function ($q) use ($projectId, $isGlobal, $user) {
                if ($projectId) $q->where('project_id', $projectId);
                if (!$isGlobal) $q->whereHas('project.users', fn($q2) => $q2->where('users.id', $user->id));
            })
            ->where('status', 'active');

        $kprData = $kprQuery->get();

        $kprMonitoring = $kprData->map(fn($kk) => [
            'id'              => $kk->id,
            'konsumen_nama'   => $kk->konsumen->nama,
            'kavling'         => $kk->kavling->nomor_lengkap,
            'project'         => $kk->kavling->project->nama,
            'cara_bayar'      => $kk->cara_bayar,
            'plafon_kpr'      => $kk->plafon_kpr,
            'realisasi_cair'  => $kk->realisasi_cair,
            'dajam_ditahan'   => $kk->dajam_ditahan,
            'status_penjualan' => $kk->status_penjualan_label,
        ]);

        $kprSummary = [
            'total_plafon'      => $kprData->sum('plafon_kpr'),
            'total_realisasi'   => $kprData->sum('realisasi_cair'),
            'total_dajam'       => $kprData->sum('dajam_ditahan'),
            'jumlah_unit'       => $kprData->count(),
        ];

        // ── Tab 3: SBUM ───────────────────────────────────────────────
        $sbumQuery = SbumRecord::query()
            ->with(['transaksi.konsumen:id,nama', 'transaksi.kavling.project:id,nama'])
            ->whereHas('transaksi.kavling', function ($q) use ($projectId, $isGlobal, $user) {
                if ($projectId) $q->where('project_id', $projectId);
                if (!$isGlobal) $q->whereHas('project.users', fn($q2) => $q2->where('users.id', $user->id));
            });

        $sbumData = $sbumQuery->get()->map(fn($s) => [
            'id'                => $s->id,
            'konsumen_nama'     => $s->transaksi->konsumen->nama,
            'kavling'           => $s->transaksi->kavling->nomor_lengkap,
            'project'           => $s->transaksi->kavling->project->nama,
            'jumlah_sbum'       => $s->jumlah_sbum,
            'status'            => $s->status,
            'status_label'      => $s->status_label,
            'tanggal_pengajuan' => $s->tanggal_pengajuan?->format('d M Y'),
            'tanggal_cair'      => $s->tanggal_cair?->format('d M Y'),
        ]);

        $sbumSummary = [
            'total_diajukan' => $sbumQuery->count(),
            'total_cair'     => SbumRecord::where('status', 'cair')->count(),
            'total_nilai'    => $sbumData->sum('jumlah_sbum'),
        ];

        // Projects untuk filter dropdown
        $projects = Project::query()
            ->when(!$isGlobal, fn($q) => $q->whereHas('users', fn($q2) => $q2->where('users.id', $user->id)))
            ->orderBy('nama')
            ->get(['id', 'nama', 'kode']);

        return Inertia::render('Keuangan/Index', [
            'pembayaran'    => $pembayaran,
            'kprMonitoring' => $kprMonitoring,
            'kprSummary'    => $kprSummary,
            'sbumData'      => $sbumData,
            'sbumSummary'   => $sbumSummary,
            'projects'      => $projects,
            'filters'       => ['project_id' => $projectId],
        ]);
    }

    /**
     * Input pembayaran baru
     */
    public function storePembayaran(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);

        $validated = $request->validate([
            'jenis'        => 'required|in:booking_fee,dp,angsuran,pelunasan',
            'jumlah'       => 'required|numeric|min:1',
            'tanggal_bayar' => 'required|date',
            'keterangan'   => 'nullable|string|max:500',
        ]);

        $kk->pembayarans()->create([
            ...$validated,
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'Pembayaran berhasil dicatat.');
    }

    /**
     * Update data pencairan KPR & Dajam
     */
    public function updateKpr(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);

        $validated = $request->validate([
            'realisasi_cair' => 'nullable|numeric|min:0',
            'dajam_ditahan'  => 'nullable|numeric|min:0',
        ]);

        $kk->update($validated);

        return back()->with('success', 'Data pencairan KPR diperbarui.');
    }

    /**
     * Update SBUM record
     */
    public function updateSbum(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage sbum'), 403);

        $validated = $request->validate([
            'jumlah_sbum'       => 'nullable|numeric|min:0',
            'status'            => 'required|in:belum,proses,cair,ditolak',
            'tanggal_pengajuan' => 'nullable|date',
            'tanggal_cair'      => 'nullable|date',
            'catatan'           => 'nullable|string',
        ]);

        $kk->sbumRecord()->updateOrCreate(
            ['kavling_konsumen_id' => $kk->id],
            $validated
        );

        return back()->with('success', 'Data SBUM diperbarui.');
    }

    /**
     * Halaman preview kuitansi (print-friendly)
     */
    public function kuitansi(PembayaranKonsumen $pembayaran): Response
    {
        abort_unless(Auth::user()->can('view keuangan'), 403);

        $pembayaran->load(['transaksi.konsumen', 'transaksi.kavling.project', 'creator']);
        $this->authorizeProjectAccess($pembayaran->transaksi->kavling->project);
        $developer = \App\Models\DeveloperProfile::getSingleton();

        return Inertia::render('Keuangan/Kuitansi', [
            'pembayaran' => [
                'id'            => $pembayaran->id,
                'jenis'         => $pembayaran->jenis,
                'jenis_label'   => $pembayaran->jenis_label,
                'jumlah'        => $pembayaran->jumlah,
                'tanggal_bayar' => $pembayaran->tanggal_bayar->format('d M Y'),
                'keterangan'    => $pembayaran->keterangan,
                'created_by'    => $pembayaran->creator?->name,
            ],
            'transaksi' => [
                'harga_deal'  => $pembayaran->transaksi->harga_deal,
                'cara_bayar'  => $pembayaran->transaksi->cara_bayar,
            ],
            'konsumen' => [
                'nama'  => $pembayaran->transaksi->konsumen->nama,
                'no_hp' => $pembayaran->transaksi->konsumen->no_hp,
                'nik'   => $pembayaran->transaksi->konsumen->nik,
                'alamat' => $pembayaran->transaksi->konsumen->alamat,
            ],
            'kavling' => [
                'nomor_lengkap' => $pembayaran->transaksi->kavling->nomor_lengkap,
                'project_nama'  => $pembayaran->transaksi->kavling->project->nama,
            ],
            'developer' => [
                'nama'        => $developer->nama_developer,
                'alamat'      => $developer->alamat,
                'telepon'     => $developer->telepon,
                'logo_path'   => $developer->logo_path
                    ? \Storage::disk('public')->url($developer->logo_path) : null,
                'kop_surat'   => $developer->kop_surat_path
                    ? \Storage::disk('public')->url($developer->kop_surat_path) : null,
            ],
        ]);
    }
}
