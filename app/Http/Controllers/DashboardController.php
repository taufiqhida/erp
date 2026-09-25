<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\CancellationRequest;
use App\Models\JadwalTagihan;
use App\Models\PembayaranKonsumen;
use App\Models\PencairanKprTahap;
use App\Models\StatusBangunStage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    /**
     * Label pipeline & cara bayar dipakai bareng backend/frontend supaya
     * urutan funnel & warna konsisten — sumber tunggal di sini.
     */
    private const PIPELINE_STAGES = ['booking', 'pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad', 'akad', 'bast'];
    private const CARA_BAYAR_KEYS = ['cash', 'cash_bertahap', 'kpr_subsidi', 'kpr_komersil'];

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);
        // Proyek aktif (Halaman Utama Pilih Proyek) — kosong berarti mode
        // "Semua Proyek". RBAC (project.users) tetap ditegakkan terlepas
        // dari proyek aktif, sama seperti pola scoping di Konsumen/Keuangan.
        $projectId = session('current_project_id');

        $scopeKavling = fn($query) => $query
            ->when(!$isGlobal, fn($q) => $q->whereHas('project.users', fn($q2) => $q2->where('users.id', $user->id)))
            ->when($projectId, fn($q) => $q->where('project_id', $projectId));

        // Dipakai untuk model yang punya relasi kavling() langsung
        // (KavlingKonsumen, CancellationRequest).
        $scopeViaKavling = fn($query) => $query
            ->when(!$isGlobal, fn($q) => $q->whereHas('kavling.project.users', fn($q2) => $q2->where('users.id', $user->id)))
            ->when($projectId, fn($q) => $q->whereHas('kavling', fn($q2) => $q2->where('project_id', $projectId)));

        $scopeProject = fn($query) => $query
            ->when(!$isGlobal, fn($q) => $q->whereHas('users', fn($q2) => $q2->where('users.id', $user->id)))
            ->when($projectId, fn($q) => $q->where('id', $projectId));

        // ── Stat cards (existing) ────────────────────────────────────────
        $stats = [
            'total_projects'        => $scopeProject(Project::active())->count(),
            'total_kavlings'        => $scopeKavling(Kavling::query())->count(),
            'kavling_available'     => $scopeKavling(Kavling::query())->where('status_jual', 'available')->count(),
            'kavling_sold'          => $scopeKavling(Kavling::query())->where('status_jual', 'sold')->count(),
            'pending_cancellations' => $scopeViaKavling(CancellationRequest::query())->pending()->count(),
        ];

        // ── Info kavling — breakdown status pembangunan ─────────────────
        $statusBangunCounts = $scopeKavling(Kavling::query())
            ->selectRaw('status_bangun_stage_id, count(*) as total')
            ->groupBy('status_bangun_stage_id')
            ->pluck('total', 'status_bangun_stage_id');

        $kavlingBangunBreakdown = StatusBangunStage::ordered()->get()->map(fn($s) => [
            'key'   => $s->id,
            'label' => $s->nama,
            'color' => $s->warna,
            'count' => (int) ($statusBangunCounts[$s->id] ?? 0),
        ]);

        // ── Transaksi aktif (dasar bersama funnel, cara bayar, & finansial) ──
        $activeTransaksi = $scopeViaKavling(KavlingKonsumen::query())
            ->where('status', '!=', 'cancelled')
            ->with([
                'konsumen:id,nama', 'kavling.project:id,nama', 'skemaDpPreset', 'pembayarans',
                'jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans',
                'rincianBiayaAkad.pembayaran', 'pencairanKprTahaps',
            ])
            ->get();

        // ── Pipeline funnel ───────────────────────────────────────────────
        $pipelineCounts = $activeTransaksi->countBy('status_penjualan');
        $pipelineFunnel = collect(self::PIPELINE_STAGES)->map(fn($stage) => [
            'key'   => $stage,
            'label' => (new KavlingKonsumen(['status_penjualan' => $stage]))->status_penjualan_label,
            'count' => (int) ($pipelineCounts[$stage] ?? 0),
        ]);

        // ── Cara bayar breakdown ──────────────────────────────────────────
        $caraBayarCounts = $activeTransaksi->countBy('cara_bayar');
        $caraBayarLabels = [
            'cash' => 'Cash', 'cash_bertahap' => 'Cash Bertahap',
            'kpr_subsidi' => 'KPR Subsidi', 'kpr_komersil' => 'KPR Komersil',
        ];
        $caraBayarBreakdown = collect(self::CARA_BAYAR_KEYS)->map(fn($key) => [
            'key'   => $key,
            'label' => $caraBayarLabels[$key],
            'count' => (int) ($caraBayarCounts[$key] ?? 0),
        ]);

        // ── Ringkasan finansial: pendapatan & sisa piutang, plus rincian
        // per komponen (dipakai tombol "expand" di tiap kartu) — satu pass
        // atas $activeTransaksi, reuse kartuPiutangBreakdown() yang sama
        // biar tidak query dua kali per transaksi.
        // Kategori 1 (Rekening Resmi) & Kategori 2 (Rekening Titipan) — lihat
        // KavlingKonsumen::kategoriPendapatan() buat aturan lengkapnya.
        // Total headline sekarang mencakup Booking Fee & Titipan Biaya Akad
        // juga (sebelumnya cuma harga_deal), supaya benar-benar merefleksikan
        // seluruh uang yang terkait transaksi, bukan cuma harga rumah.
        $totalPendapatan = 0.0;
        $nilaiTransaksiBreakdown = [
            'resmi'   => ['harga_dasar' => 0.0, 'booking_fee' => 0.0, 'diskon' => 0.0],
            'titipan' => ['biaya_tanah' => 0.0, 'biaya_tambahan_lain' => 0.0, 'titipan_biaya_akad' => 0.0, 'booking_fee' => 0.0],
        ];
        $piutangTotals = ['piutang_konsumen' => 0.0, 'terbayar_konsumen' => 0.0, 'piutang_bank' => 0.0, 'terbayar_bank' => 0.0];
        $piutangKonsumenByNama = [];
        $piutangBankByNama = [];

        foreach ($activeTransaksi as $kk) {
            $kategori = $kk->kategoriPendapatan();
            $totalPendapatan += $kategori['resmi_total'] + $kategori['titipan_total'];
            foreach ($kategori['resmi_rincian'] as $key => $val) {
                $nilaiTransaksiBreakdown['resmi'][$key] += $val;
            }
            foreach ($kategori['titipan_rincian'] as $key => $val) {
                $nilaiTransaksiBreakdown['titipan'][$key] += $val;
            }

            $breakdown = $kk->kartuPiutangBreakdown();
            $piutangTotals['piutang_konsumen']  += $breakdown['total_piutang_konsumen'];
            $piutangTotals['terbayar_konsumen'] += $breakdown['total_terbayar_konsumen'];
            $piutangTotals['piutang_bank']      += $breakdown['total_piutang_bank'];
            $piutangTotals['terbayar_bank']     += $breakdown['total_terbayar_bank'];

            foreach ($breakdown['kartu_piutang_static'] as $item) {
                $piutangKonsumenByNama[$item['nama']] ??= ['nominal' => 0.0, 'terbayar' => 0.0];
                $piutangKonsumenByNama[$item['nama']]['nominal']  += $item['nominal'];
                $piutangKonsumenByNama[$item['nama']]['terbayar'] += $item['jumlah_dibayar'] ?? 0;
            }

            $isKpr = in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil']);
            if ($isKpr) {
                foreach (['sbum' => 'SBUM', 'dajam' => 'Dana Jaminan'] as $kategori => $label) {
                    $items = $kk->rincianBiayaAkad->where('kategori', $kategori);
                    $piutangBankByNama[$label] ??= ['nominal' => 0.0, 'terbayar' => 0.0];
                    $piutangBankByNama[$label]['nominal']  += (float) $items->sum('nominal');
                    $piutangBankByNama[$label]['terbayar'] += (float) $items->filter(fn($i) => $i->pembayaran)->sum(fn($i) => $i->pembayaran->jumlah);
                }
                if ($breakdown['pencairan_kpr']) {
                    $piutangBankByNama['Pencairan KPR'] ??= ['nominal' => 0.0, 'terbayar' => 0.0];
                    $piutangBankByNama['Pencairan KPR']['nominal']  += $breakdown['pencairan_kpr']['pencairan_nominal'];
                    $piutangBankByNama['Pencairan KPR']['terbayar'] += $breakdown['pencairan_kpr']['pencairan_tercatat'];
                }
            }
        }

        $toBreakdownList = fn($byNama) => collect($byNama)->map(fn($v, $nama) => [
            'nama' => $nama, 'nominal' => $v['nominal'], 'terbayar' => $v['terbayar'],
        ])->values();

        $financials = [
            'total_pendapatan'       => $totalPendapatan,
            'nilai_transaksi_rincian' => $nilaiTransaksiBreakdown,
            'piutang_konsumen'       => $piutangTotals['piutang_konsumen'],
            'terbayar_konsumen'      => $piutangTotals['terbayar_konsumen'],
            'sisa_piutang_konsumen'  => max(0, $piutangTotals['piutang_konsumen'] - $piutangTotals['terbayar_konsumen']),
            'piutang_konsumen_rincian' => $toBreakdownList($piutangKonsumenByNama),
            'piutang_bank'           => $piutangTotals['piutang_bank'],
            'terbayar_bank'          => $piutangTotals['terbayar_bank'],
            'sisa_piutang_bank'      => max(0, $piutangTotals['piutang_bank'] - $piutangTotals['terbayar_bank']),
            'piutang_bank_rincian'   => $toBreakdownList($piutangBankByNama),
        ];

        // ── Piutang jatuh tempo (cicilan lewat tanggal jatuh tempo, belum lunas) ──
        $overdueJadwal = JadwalTagihan::query()
            ->whereIn('status', ['belum_bayar', 'sebagian'])
            ->where('tanggal_jatuh_tempo', '<', now()->toDateString())
            ->whereHas('kavlingKonsumen', function ($q) use ($scopeViaKavling) {
                $scopeViaKavling($q->where('status', '!=', 'cancelled'));
            })
            ->with(['kavlingKonsumen.konsumen:id,nama', 'kavlingKonsumen.kavling.project:id,nama'])
            ->orderBy('tanggal_jatuh_tempo')
            ->get();

        $piutangJatuhTempo = [
            'total_count'   => $overdueJadwal->count(),
            'total_nominal' => $overdueJadwal->sum('jumlah'),
            'items'         => $overdueJadwal->take(10)->map(fn($j) => [
                'id'                  => $j->id,
                'konsumen_nama'       => $j->kavlingKonsumen->konsumen->nama,
                'kavling'             => $j->kavlingKonsumen->kavling->nomor_lengkap,
                'project'             => $j->kavlingKonsumen->kavling->project->nama,
                'jenis_label'         => $j->jenis_label,
                'jumlah'              => $j->jumlah,
                'tanggal_jatuh_tempo' => $j->tanggal_jatuh_tempo->format('d M Y'),
                'hari_terlambat'      => (int) abs(now()->startOfDay()->diffInDays($j->tanggal_jatuh_tempo)),
                'transaksi_id'        => $j->kavling_konsumen_id,
            ]),
        ];

        // ── BAST tertunda: sudah akad, bangunan siap serah terima, tapi
        // belum dikonfirmasi Selesai (BAST) ──────────────────────────────
        $finalStageId = StatusBangunStage::finalStage()?->id;

        $bastTertunda = $scopeViaKavling(KavlingKonsumen::query())
            ->where('status_penjualan', 'akad')
            ->where('status', '!=', 'cancelled')
            ->whereHas('kavling', fn($q) => $q->where('status_bangun_stage_id', $finalStageId)->where('status_bangun_persen', '>=', 100))
            ->with(['konsumen:id,nama', 'kavling.project:id,nama'])
            ->orderBy('tanggal_akad')
            ->get()
            ->map(fn($kk) => [
                'id'            => $kk->id,
                'konsumen_nama' => $kk->konsumen->nama,
                'kavling'       => $kk->kavling->nomor_lengkap,
                'project'       => $kk->kavling->project->nama,
                'tanggal_akad'  => $kk->tanggal_akad?->format('d M Y'),
            ]);

        $recentActivities = Activity::with('causer')
            ->latest()
            ->take(10)
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'description' => $a->description,
                'causer_name' => $a->causer?->name ?? 'System',
                'created_at'  => $a->created_at->diffForHumans(),
            ]);

        // Ringkasan Proyek Aktif hanya relevan di mode "Semua Proyek" — kalau
        // sudah scoped ke 1 proyek, dashboard-nya sendiri sudah representasi
        // proyek itu, jadi panel ini tidak perlu dikirim (Vue skip render).
        $projectsSummary = $projectId ? [] : Project::active()
            ->when(!$isGlobal, fn($q) => $q->whereHas('users', fn($q2) => $q2->where('users.id', $user->id)))
            ->withCount([
                'kavlings',
                'kavlings as kavlings_sold_count'      => fn($q) => $q->where('status_jual', 'sold'),
                'kavlings as kavlings_available_count' => fn($q) => $q->where('status_jual', 'available'),
            ])
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn($p) => [
                'id'                => $p->id,
                'nama'              => $p->nama,
                'kode'              => $p->kode,
                'kota'              => $p->kota,
                'kavlings_count'    => $p->kavlings_count,
                'kavlings_sold'     => $p->kavlings_sold_count,
                'kavlings_available'=> $p->kavlings_available_count,
                'progress'          => $p->kavlings_count > 0
                    ? round(($p->kavlings_sold_count / $p->kavlings_count) * 100, 1)
                    : 0,
            ]);

        $sp3kMonitoring = $scopeViaKavling(KavlingKonsumen::query())
            ->whereNotNull('tanggal_expired_sp3k')
            ->whereIn('status_penjualan', ['sp3k', 'rencana_akad'])
            ->where('status', 'active')
            ->with(['konsumen:id,nama', 'kavling.project:id,nama'])
            ->orderBy('tanggal_expired_sp3k')
            ->take(10)
            ->get()
            ->map(fn($kk) => [
                'id'                   => $kk->id,
                'konsumen_nama'        => $kk->konsumen->nama,
                'kavling'              => $kk->kavling->nomor_lengkap,
                'project'              => $kk->kavling->project->nama,
                'tanggal_expired_sp3k' => $kk->tanggal_expired_sp3k->format('d M Y'),
                'sp3k_expiry_status'   => $kk->sp3k_expiry_status,
            ]);

        // ═══════════════════════════════════════════════════════════════
        // Laporan Periode — section terpisah dari panel "kondisi sekarang"
        // di atas. Pipeline & Cara Bayar bersifat dual-mode (live kalau
        // tidak ada filter tanggal, periodik kalau ada — lihat Dashboard.vue
        // yang milih render mana). Section lain di bawah ini murni periodik,
        // default ke bulan berjalan kalau user belum pilih rentang.
        // ═══════════════════════════════════════════════════════════════
        $hasDateFilter = $request->filled('from') && $request->filled('to');
        $periodFrom = $hasDateFilter ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $periodTo   = $hasDateFilter ? Carbon::parse($request->to)->endOfDay() : now()->endOfMonth();
        $tahunTren  = (int) ($request->tahun ?: now()->year);

        // ── Volume dasar (Booking/Akad/BAST/Batal) — dihitung sekali, dipakai
        // bareng oleh Informasi Umum, Pipeline Penjualan periodik, & Cancellation
        // Rate. Booking & Akad dihitung GROSS (semua yang terjadi di rentang
        // ini, termasuk yang belakangan dibatalkan) — Batal ditampilkan sebagai
        // angka terpisah, bukan dikurangkan, supaya volume aktivitas asli tetap
        // kelihatan (lih. juga Cancellation Rate untuk versi %).
        $bookingCount = $scopeViaKavling(KavlingKonsumen::query())
            ->whereBetween('tanggal_booking', [$periodFrom, $periodTo])->count();
        $akadCount = $scopeViaKavling(KavlingKonsumen::query())
            ->whereBetween('tanggal_akad', [$periodFrom, $periodTo])->count();
        $bastCount = $scopeViaKavling(KavlingKonsumen::query())
            ->whereBetween('tanggal_bast', [$periodFrom, $periodTo])->count();
        $batalCount = $scopeViaKavling(CancellationRequest::query())
            ->where('type', 'cancellation')
            ->where('status', 'approved')
            ->whereBetween('reviewed_at', [$periodFrom, $periodTo])
            ->count();

        $informasiUmum = [
            'jumlah_booking' => $bookingCount,
            'jumlah_akad'    => $akadCount,
            'jumlah_batal'   => $batalCount,
        ];

        // ── Pipeline Penjualan periodik (dual-mode, lihat Dashboard.vue) —
        // disederhanakan jadi 3 milestone (Booking/Akad/BAST) karena cuma ini
        // yang punya tanggal event sendiri; tahap antara (pemberkasan/
        // proses_bank/sp3k/rencana_akad) tidak (belum) punya tanggal transisi.
        $pipelineFunnelPeriodic = [
            'booking' => $bookingCount,
            'akad'    => $akadCount,
            'bast'    => $bastCount,
            'conversion_booking_akad' => $bookingCount > 0 ? round($akadCount / $bookingCount * 100, 1) : 0,
            'conversion_booking_bast' => $bookingCount > 0 ? round($bastCount / $bookingCount * 100, 1) : 0,
        ];

        // ── Cara Bayar periodik — breakdown transaksi yang DIBOOKING dalam
        // rentang (bukan transaksi aktif sekarang seperti versi live).
        $caraBayarCountsPeriodic = $scopeViaKavling(KavlingKonsumen::query())
            ->whereBetween('tanggal_booking', [$periodFrom, $periodTo])
            ->get()->countBy('cara_bayar');
        $caraBayarBreakdownPeriodic = collect(self::CARA_BAYAR_KEYS)->map(fn($key) => [
            'key'   => $key,
            'label' => $caraBayarLabels[$key],
            'count' => (int) ($caraBayarCountsPeriodic[$key] ?? 0),
        ]);

        // ── Finansial Periode — uang yang BENAR-BENAR masuk selama rentang
        // (beda dari Ringkasan Finansial di atas yang nampilin saldo piutang
        // outstanding sekarang, terlepas kapan transaksinya terjadi).
        $totalPembayaranDiterima = PembayaranKonsumen::query()
            ->whereBetween('tanggal_bayar', [$periodFrom, $periodTo])
            ->whereHas('transaksi', fn($q) => $scopeViaKavling($q))
            ->sum('jumlah');

        $totalPencairanKprDiterima = PencairanKprTahap::query()
            ->whereBetween('tanggal_cair', [$periodFrom, $periodTo])
            ->whereHas('kavlingKonsumen', fn($q) => $scopeViaKavling($q))
            ->sum('nominal');

        // Jatuh tempo piutang KONSUMEN dalam rentang — cuma Booking Fee/DP,
        // karena cuma itu yang punya tanggal_jatuh_tempo tersimpan (Biaya
        // Tambahan & Biaya Tanah belum ada struktur tanggal jatuh temponya).
        $jatuhTempoPiutangKonsumen = JadwalTagihan::query()
            ->whereIn('status', ['belum_bayar', 'sebagian'])
            ->whereBetween('tanggal_jatuh_tempo', [$periodFrom, $periodTo])
            ->whereHas('kavlingKonsumen', fn($q) => $scopeViaKavling($q->where('status', '!=', 'cancelled')))
            ->sum('jumlah');

        // Jatuh tempo pencairan BANK: belum ada data — pencairan_kpr_tahap
        // cuma simpan tanggal_cair AKTUAL, tidak ada kolom tanggal estimasi.
        $jatuhTempoPencairanBank = null;

        $transaksiPeriode = $scopeViaKavling(KavlingKonsumen::query())
            ->whereBetween('tanggal_booking', [$periodFrom, $periodTo])
            ->where('status', '!=', 'cancelled')
            ->with('kavling.project:id,nama')
            ->get(['id', 'harga_deal', 'kavling_id']);

        $financialsPeriodic = [
            'total_pembayaran_diterima'     => (float) $totalPembayaranDiterima,
            'jatuh_tempo_piutang_konsumen'  => (float) $jatuhTempoPiutangKonsumen,
            'total_pencairan_kpr_diterima'  => (float) $totalPencairanKprDiterima,
            'jatuh_tempo_pencairan_bank'    => $jatuhTempoPencairanBank,
            'rata_rata_nilai_transaksi'     => (float) ($transaksiPeriode->avg('harga_deal') ?? 0),
            'jumlah_transaksi'              => $transaksiPeriode->count(),
            // Hanya relevan di mode "Semua Proyek", sama seperti projectsSummary di atas.
            'revenue_per_proyek' => $projectId ? [] : $transaksiPeriode
                ->groupBy(fn($kk) => $kk->kavling->project->nama)
                ->map(fn($group, $nama) => [
                    'project' => $nama,
                    'total'   => (float) $group->sum('harga_deal'),
                    'count'   => $group->count(),
                ])
                ->sortByDesc('total')
                ->values(),
        ];

        // ── Kecepatan Pipeline — rata-rata durasi (hari) antar milestone,
        // dihitung dari transaksi yang MENCAPAI milestone akhir dalam rentang
        // yang dipilih (mis. "akad yang terjadi bulan ini, rata-rata makan
        // waktu berapa hari dari booking-nya").
        $avgDurationDays = function (string $startCol, string $endCol, string $filterCol) use ($scopeViaKavling, $periodFrom, $periodTo) {
            $rows = $scopeViaKavling(KavlingKonsumen::query())
                ->whereNotNull($startCol)
                ->whereNotNull($endCol)
                ->whereBetween($filterCol, [$periodFrom, $periodTo])
                ->get([$startCol, $endCol]);

            if ($rows->isEmpty()) return null;

            return round($rows->avg(fn($r) => Carbon::parse($r->$startCol)->diffInDays(Carbon::parse($r->$endCol))), 1);
        };

        // Rangkaian penuh (KPR): Booking -> Pemberkasan -> Proses Bank -> SP3K
        // -> Rencana Akad -> Akad -> BAST. `tanggal_sp3k` dobel-fungsi sebagai
        // penanda "SP3K selesai / masuk Rencana Akad" (ditulis bareng
        // status_penjualan='rencana_akad' di updateSp3kDecision), jadi bisa
        // dipakai buat isolasi durasi SP3K & Rencana Akad tanpa kolom baru.
        $kecepatanPipeline = [
            'pemberkasan'     => $avgDurationDays('tanggal_booking', 'tanggal_pengajuan_bank', 'tanggal_pengajuan_bank'),
            'proses_bank'     => $avgDurationDays('tanggal_pengajuan_bank', 'tanggal_keputusan_bank', 'tanggal_keputusan_bank'),
            'sp3k'            => $avgDurationDays('tanggal_keputusan_bank', 'tanggal_sp3k', 'tanggal_sp3k'),
            'rencana_akad'    => $avgDurationDays('tanggal_sp3k', 'tanggal_akad', 'tanggal_akad'),
            'akad_ke_bast'    => $avgDurationDays('tanggal_akad', 'tanggal_bast', 'tanggal_bast'),
            'booking_ke_akad' => $avgDurationDays('tanggal_booking', 'tanggal_akad', 'tanggal_akad'),
            'booking_ke_bast' => $avgDurationDays('tanggal_booking', 'tanggal_bast', 'tanggal_bast'),
        ];

        // ── Performa Sales — ranking by jumlah booking dalam rentang, plus
        // conversion rate (dari booking itu, berapa % sudah akad/bast SEKARANG).
        $performaSales = $scopeViaKavling(KavlingKonsumen::query())
            ->whereBetween('tanggal_booking', [$periodFrom, $periodTo])
            ->whereNotNull('sales_agent_id')
            ->with('salesAgent:id,nama,tipe')
            ->get()
            ->groupBy('sales_agent_id')
            ->map(function ($group) {
                $agent = $group->first()->salesAgent;
                $totalBooking = $group->count();
                $totalAkadKeAtas = $group->whereIn('status_penjualan', ['akad', 'bast'])->count();
                return [
                    'sales_agent_id'  => $agent?->id,
                    'nama'            => $agent?->nama ?? '-',
                    'tipe_label'      => $agent?->tipe_label,
                    'jumlah_booking'  => $totalBooking,
                    'conversion_rate' => $totalBooking > 0 ? round($totalAkadKeAtas / $totalBooking * 100, 1) : 0,
                ];
            })
            ->sortByDesc('jumlah_booking')
            ->take(10)
            ->values();

        // ── Cancellation Rate — pembatalan (bukan tukar unit) yang DISETUJUI
        // dalam rentang, dibanding jumlah booking di rentang yang sama
        // (reuse $bookingCount/$batalCount yang sudah dihitung di atas).
        $cancellationRatePeriod = [
            'jumlah_dibatalkan' => $batalCount,
            'jumlah_booking'    => $bookingCount,
            'rate'              => $bookingCount > 0 ? round($batalCount / $bookingCount * 100, 1) : 0,
        ];

        // ── Grafik Tren — breakdown 12 bulan untuk tahun terpilih (kontrol
        // terpisah dari date-range di atas, lihat diskusi: trend butuh cukup
        // titik data, jadi selalu per-tahun-kalender, bukan rentang bebas).
        $bookingByMonth = $scopeViaKavling(KavlingKonsumen::query())
            ->whereYear('tanggal_booking', $tahunTren)
            ->selectRaw('MONTH(tanggal_booking) as bulan, count(*) as total')
            ->groupBy('bulan')->pluck('total', 'bulan');
        $akadByMonth = $scopeViaKavling(KavlingKonsumen::query())
            ->whereYear('tanggal_akad', $tahunTren)
            ->selectRaw('MONTH(tanggal_akad) as bulan, count(*) as total')
            ->groupBy('bulan')->pluck('total', 'bulan');
        $bastByMonth = $scopeViaKavling(KavlingKonsumen::query())
            ->whereYear('tanggal_bast', $tahunTren)
            ->selectRaw('MONTH(tanggal_bast) as bulan, count(*) as total')
            ->groupBy('bulan')->pluck('total', 'bulan');
        $revenueByMonth = PembayaranKonsumen::query()
            ->whereYear('tanggal_bayar', $tahunTren)
            ->whereHas('transaksi', fn($q) => $scopeViaKavling($q))
            ->selectRaw('MONTH(tanggal_bayar) as bulan, sum(jumlah) as total')
            ->groupBy('bulan')->pluck('total', 'bulan');

        $bulanLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $trenTahunan = [
            'tahun'          => $tahunTren,
            'tahun_options'  => range(now()->year, now()->year - 4),
            'bulan'          => collect(range(1, 12))->map(fn($b) => [
                'label'   => $bulanLabels[$b - 1],
                'booking' => (int) ($bookingByMonth[$b] ?? 0),
                'akad'    => (int) ($akadByMonth[$b] ?? 0),
                'bast'    => (int) ($bastByMonth[$b] ?? 0),
                'revenue' => (float) ($revenueByMonth[$b] ?? 0),
            ]),
        ];

        return Inertia::render('Dashboard', [
            'stats'                  => $stats,
            'kavlingBangunBreakdown' => $kavlingBangunBreakdown,
            'pipelineFunnel'         => $pipelineFunnel,
            'caraBayarBreakdown'     => $caraBayarBreakdown,
            'financials'             => $financials,
            'piutangJatuhTempo'      => $piutangJatuhTempo,
            'bastTertunda'           => $bastTertunda,
            'recentActivities'       => $recentActivities,
            'projectsSummary'        => $projectsSummary,
            'sp3kMonitoring'         => $sp3kMonitoring,
            'periodFilters' => [
                'from' => $periodFrom->toDateString(),
                'to'   => $periodTo->toDateString(),
                'has_date_filter' => $hasDateFilter,
            ],
            'informasiUmum'              => $informasiUmum,
            'pipelineFunnelPeriodic'     => $pipelineFunnelPeriodic,
            'caraBayarBreakdownPeriodic' => $caraBayarBreakdownPeriodic,
            'financialsPeriodic'         => $financialsPeriodic,
            'kecepatanPipeline'          => $kecepatanPipeline,
            'performaSales'              => $performaSales,
            'cancellationRatePeriod'     => $cancellationRatePeriod,
            'trenTahunan'                => $trenTahunan,
        ]);
    }
}
