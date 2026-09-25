<?php

namespace App\Http\Controllers;

use App\Enums\StatusJual;
use App\Models\StatusBangunStage;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Http\Controllers\Concerns\ChecksTransactionLock;
use App\Models\BiayaTambahanPreset;
use App\Models\CancellationRequest;
use App\Models\DajamSbumPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\KavlingKonsumenDajamSbum;
use App\Models\Konsumen;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\SumberLead;
use App\Models\SuratTemplate;
use App\Models\Project;
use App\Imports\KonsumenImport;
use App\Support\KonsumenImportSpec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class KonsumenController extends Controller
{
    use AuthorizesProjectAccess, ChecksTransactionLock;

    private function caraBayarLabel(?string $caraBayar): string
    {
        return match($caraBayar) {
            'cash'          => 'Cash',
            'cash_bertahap' => 'Cash Bertahap',
            'kpr_subsidi'   => 'KPR Subsidi',
            'kpr_komersil'  => 'KPR Komersil',
            default         => $caraBayar ?? '-',
        };
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Konsumen::class);

        $user = Auth::user();
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);
        $viewMode = $request->view === 'konsumen' ? 'konsumen' : 'unit';

        // Proyek aktif (Halaman Utama Pilih Proyek) — bukan lagi filter
        // dropdown di halaman ini, murni ikut context global. Kosong berarti
        // mode "Semua Proyek", data lintas-proyek (tetap dibatasi RBAC).
        $activeProjectId = session('current_project_id');

        // Filter unit (kluster/blok/tipe/status jual/status bangun) — format
        // sama dengan tab Proyek/Penjualan, diterapkan ke atribut Kavling.
        $unitFilter = function ($q) use ($request, $activeProjectId) {
            if ($activeProjectId) $q->where('project_id', $activeProjectId);
            if ($request->kluster) $q->where('kluster', $request->kluster);
            if ($request->blok) $q->where('blok', $request->blok);
            if ($request->tipe_unit_preset_id) $q->where('tipe_unit_preset_id', $request->tipe_unit_preset_id);
            if ($request->status_jual) $q->where('status_jual', $request->status_jual);
            if ($request->status_bangun_stage_id) $q->where('status_bangun_stage_id', $request->status_bangun_stage_id);
        };
        $hasUnitFilter = $activeProjectId || collect(['kluster', 'blok', 'tipe_unit_preset_id', 'status_jual', 'status_bangun_stage_id'])
            ->contains(fn($key) => $request->filled($key));

        // RBAC: non-global hanya boleh lihat kavling di proyek yang di-assign.
        $projectScope = fn($q) => $q->whereHas('project.users', fn($q2) => $q2->where('users.id', $user->id));

        // Opsi dropdown filter — diskop ke proyek yang bisa diakses user (dan
        // ke proyek aktif kalau ada).
        $optionsQuery = Kavling::query()
            ->when(!$isGlobal, $projectScope)
            ->when($activeProjectId, fn($q) => $q->where('project_id', $activeProjectId));
        $filterOptions = [
            'kluster'       => (clone $optionsQuery)->whereNotNull('kluster')->where('kluster', '!=', '')->distinct()->orderBy('kluster')->pluck('kluster'),
            'blok'          => (clone $optionsQuery)->whereNotNull('blok')->where('blok', '!=', '')->distinct()->orderBy('blok')->pluck('blok'),
            'tipe_unit'     => \App\Models\TipeUnitPreset::query()
                ->when($activeProjectId, fn($q) => $q->where('project_id', $activeProjectId))
                ->when(!$isGlobal && !$activeProjectId, fn($q) => $q->whereHas('project.users', fn($q2) => $q2->where('users.id', $user->id)))
                ->orderBy('nama')->get(['id', 'nama']),
            'status_jual'   => collect(StatusJual::cases())->mapWithKeys(fn($s) => [$s->value => $s->label()]),
            'status_bangun' => StatusBangunStage::ordered()->get(['id', 'nama', 'warna']),
            'status_penjualan' => collect([
                'booking' => 'Booking', 'pemberkasan' => 'Pemberkasan', 'proses_bank' => 'Proses Bank / SLIK',
                'sp3k' => 'SP3K', 'rencana_akad' => 'Rencana Akad', 'akad' => 'Akad', 'bast' => 'BAST', 'batal' => 'Batal',
            ])->map(fn($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ];

        // Sort daftar unit dilakukan di server (daftar dipaginasi, jadi sort di browser
        // cuma mengurutkan 1 halaman). Kolom sort dibatasi whitelist, bukan input bebas.
        $sort = in_array($request->sort, ['unit', 'booking'], true) ? $request->sort : 'booking';
        $dir = $request->dir === 'asc' ? 'asc' : 'desc';
        if (!$request->sort) $dir = 'desc';

        if ($viewMode === 'unit') {
            $rows = KavlingKonsumen::query()
                ->join('kavlings', 'kavlings.id', '=', 'kavling_konsumen.kavling_id')
                ->select('kavling_konsumen.*')
                ->with(['konsumen', 'kavling.project', 'kavling.tipeUnitPreset', 'kavling.statusBangunStage', 'dokumens'])
                ->whereHas('kavling', function ($q) use ($unitFilter, $isGlobal, $projectScope) {
                    $unitFilter($q);
                    if (!$isGlobal) $projectScope($q);
                })
                ->when($request->status_penjualan, fn($q) => $q->where('status_penjualan', $request->status_penjualan))
                ->when($request->search, fn($q) =>
                    $q->whereHas('konsumen', fn($q2) => $q2->where(fn($q3) => $q3
                        ->where('nama', 'like', "%{$request->search}%")
                        ->orWhere('no_hp', 'like', "%{$request->search}%")
                        ->orWhere('nik', 'like', "%{$request->search}%")
                    ))
                )
                ->when(
                    $sort === 'unit',
                    fn($q) => Kavling::applyUnitOrder($q, $dir),
                    fn($q) => $q->orderBy('kavling_konsumen.tanggal_booking', $dir)
                )
                ->orderByDesc('kavling_konsumen.id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn($trx) => [
                    'id'                => $trx->id,
                    'tanggal_booking'   => $trx->tanggal_booking?->format('d M Y'),
                    'konsumen_id'       => $trx->konsumen_id,
                    'konsumen_nama'     => $trx->konsumen->nama,
                    'konsumen_no_hp'    => $trx->konsumen->no_hp,
                    'kavling_nomor'     => $trx->kavling->nomor_lengkap,
                    'project_nama'      => $trx->kavling->project->nama,
                    'kluster'           => $trx->kavling->kluster,
                    'blok'              => $trx->kavling->blok,
                    'tipe_unit'         => $trx->kavling->tipeUnitPreset?->nama,
                    'status_jual'       => $trx->kavling->status_jual->value,
                    'status_jual_label' => $trx->kavling->status_jual_label,
                    'status_bangun_stage_id' => $trx->kavling->status_bangun_stage_id,
                    'status_bangun_label' => $trx->kavling->status_bangun_label,
                    'harga_deal'        => $trx->harga_deal,
                    'cara_bayar_label'  => $this->caraBayarLabel($trx->cara_bayar),
                    'bank_rekanan_kpr'  => $trx->bank_rekanan_kpr,
                    'status_penjualan'       => $trx->status_penjualan,
                    'status_penjualan_label' => $trx->status_penjualan_label,
                    'pipeline_progress'      => $trx->pipeline_progress_info,
                    'progress_berkas'   => $trx->progress_berkas,
                    'id_rumah'          => $trx->kavling->id_rumah,
                ]);
        } else {
            $rows = Konsumen::query()
                // Non-global role hanya boleh lihat konsumen yang: (a) belum punya
                // transaksi sama sekali (belum terikat proyek manapun), atau (b)
                // transaksinya ada di proyek yang di-assign ke user via project_user.
                ->when(!$isGlobal, fn($q) =>
                    $q->where(fn($q2) =>
                        $q2->doesntHave('kavlingKonsumens')
                           ->orWhereHas('kavlingKonsumens.kavling', $projectScope)
                    )
                )
                ->when($request->search, fn($q) =>
                    $q->where(fn($q2) => $q2
                        ->where('nama', 'like', "%{$request->search}%")
                        ->orWhere('no_hp', 'like', "%{$request->search}%")
                        ->orWhere('nik', 'like', "%{$request->search}%")
                    )
                )
                ->when($hasUnitFilter, fn($q) => $q->whereHas('kavlingKonsumens.kavling', $unitFilter))
                ->when($request->status_penjualan, fn($q) => $q->whereHas('kavlingKonsumens', fn($q2) => $q2->where('status_penjualan', $request->status_penjualan)))
                ->with(['kavlingKonsumens' => fn($q) =>
                    $q->when($hasUnitFilter, fn($q2) => $q2->whereHas('kavling', $unitFilter))
                      ->when($request->status_penjualan, fn($q2) => $q2->where('status_penjualan', $request->status_penjualan))
                      ->with('kavling.project')
                ])
                ->withCount('kavlingKonsumens as transaksi_count')
                ->orderBy('nama')
                ->paginate(20)
                ->withQueryString()
                ->through(fn($k) => [
                    'id'              => $k->id,
                    'nama'            => $k->nama,
                    'no_hp'           => $k->no_hp,
                    'nik'             => $k->nik,
                    'email'           => $k->email,
                    'pekerjaan'       => $k->pekerjaan,
                    'transaksi_count' => $k->transaksi_count,
                    'units'           => $k->kavlingKonsumens->map(fn($trx) => [
                        'id'                     => $trx->id,
                        'kavling_nomor'          => $trx->kavling->nomor_lengkap,
                        'project_nama'           => $trx->kavling->project->nama,
                        'status_jual'            => $trx->kavling->status_jual->value,
                        'status_jual_label'      => $trx->kavling->status_jual_label,
                        'cara_bayar_label'       => $this->caraBayarLabel($trx->cara_bayar),
                        'status_penjualan'       => $trx->status_penjualan,
                        'status_penjualan_label' => $trx->status_penjualan_label,
                        'pipeline_progress'      => $trx->pipeline_progress_info,
                    ]),
                ]);
        }

        return Inertia::render('Konsumens/Index', [
            'mode'          => $viewMode,
            'rows'          => $rows,
            'filterOptions' => $filterOptions,
            'filters'       => $request->only(['search', 'kluster', 'blok', 'tipe_unit_preset_id', 'status_jual', 'status_bangun_stage_id', 'status_penjualan', 'view', 'sort', 'dir']),
        ]);
    }

    public function show(Request $request, Konsumen $konsumen): Response
    {
        $this->authorize('view', $konsumen);

        $transaksis = $konsumen->kavlingKonsumens()
            ->with([
                'kavling.project:id,nama', 'kavling.tipeUnitPreset', 'kavling.statusBangunStage', 'dokumens', 'pembayarans', 'biayaTambahans.pembayarans',
                'promoPreset:id,nama', 'programAllInPreset:id,nama', 'skemaDpPreset',
                'jadwalTagihans' => fn($q) => $q->orderBy('jenis')->orderBy('nomor_cicilan'),
                'jadwalTagihans.pembayaran',
                'rincianBiayaAkad' => fn($q) => $q->orderBy('kategori')->orderBy('nama'),
                'rincianBiayaAkad.pembayaran',
                'pencairanKprTahaps' => fn($q) => $q->orderBy('tanggal_cair'),
            ])
            ->orderByDesc('created_at')
            ->get();

        $pendingRequestsByTrx = CancellationRequest::whereIn('kavling_konsumen_id', $transaksis->pluck('id'))
            ->pending()
            ->get()
            ->keyBy('kavling_konsumen_id');

        $transaksis = $transaksis
            ->map(function ($trx) use ($pendingRequestsByTrx) {
                $isKpr = in_array($trx->cara_bayar, ['kpr_subsidi', 'kpr_komersil']);
                $isKprSubsidi = $trx->cara_bayar === 'kpr_subsidi';
                $dpTotal = (float) $trx->jadwalTagihans->where('jenis', 'dp')->sum('jumlah');
                $breakdown = $trx->kartuPiutangBreakdown();

                $pendingRequest = $pendingRequestsByTrx->get($trx->id);

                return [
                    'id'               => $trx->id,
                    'kavling_id'       => $trx->kavling_id,
                    'project_id'       => $trx->kavling->project_id,
                    'status'           => $trx->status,
                    'has_pending_request'  => (bool) $pendingRequest,
                    'pending_request_type' => $pendingRequest?->type->value,
                    'kavling_nomor'    => $trx->kavling->nomor_lengkap,
                    'project_nama'     => $trx->kavling->project->nama,
                    'luas_tanah'       => $trx->kavling->tipeUnitPreset?->luas_tanah,
                    'luas_bangunan'    => $trx->kavling->tipeUnitPreset?->luas_bangunan,
                    'tipe_unit'        => $trx->kavling->tipeUnitPreset?->nama,
                    'status_bangun_stage_id' => $trx->kavling->status_bangun_stage_id,
                    'status_bangun_label' => $trx->kavling->status_bangun_label,
                    'tanggal_booking'  => $trx->tanggal_booking?->format('d M Y'),
                    'tanggal_akad'     => $trx->tanggal_akad?->format('d M Y'),
                    'cara_bayar'       => $trx->cara_bayar,
                    'cara_bayar_label' => $this->caraBayarLabel($trx->cara_bayar),
                    'bank_rekanan_kpr' => $trx->bank_rekanan_kpr,
                    'is_kpr'           => $isKpr,
                    'is_kpr_subsidi'   => $isKprSubsidi,
                    'pencairan_kpr'    => $breakdown['pencairan_kpr'],
                    'total_piutang_bank'  => $breakdown['total_piutang_bank'],
                    'total_terbayar_bank' => $breakdown['total_terbayar_bank'],
                    'pencairan_kpr_tahaps' => $trx->pencairanKprTahaps->map(fn($t) => [
                        'id'           => $t->id,
                        'nominal'      => $t->nominal,
                        'tanggal_cair' => $t->tanggal_cair->format('d M Y'),
                        'keterangan'   => $t->keterangan,
                    ]),
                    'harga_dasar'      => $trx->harga_dasar,
                    'harga_deal'       => $trx->harga_deal,
                    'booking_fee'      => $trx->booking_fee,
                    'dp_nominal'       => $dpTotal,
                    'skema_dp_preset'  => $trx->skemaDpPreset ? [
                        'nama'                         => $trx->skemaDpPreset->nama,
                        'booking_fee_aktif'            => $trx->skemaDpPreset->booking_fee_aktif,
                        'booking_fee_tipe'             => $trx->skemaDpPreset->booking_fee_tipe,
                        'booking_fee_nilai'            => $trx->skemaDpPreset->booking_fee_nilai,
                        'booking_fee_tenor'            => $trx->skemaDpPreset->booking_fee_tenor,
                        'booking_fee_masuk_harga_jual' => $trx->skemaDpPreset->booking_fee_masuk_harga_jual,
                        'dp_aktif'                     => $trx->skemaDpPreset->dp_aktif,
                        'dp_tipe'                      => $trx->skemaDpPreset->dp_tipe,
                        'dp_nilai'                     => $trx->skemaDpPreset->dp_nilai,
                        'dp_tenor'                     => $trx->skemaDpPreset->dp_tenor,
                        'dp_masuk_harga_jual'          => $trx->skemaDpPreset->dp_masuk_harga_jual,
                    ] : null,
                    // ── Rincian Pemesanan: breakdown kalkulasi harga persis
                    // seperti di form booking (lihat BookingController::store).
                    // Field mentah (luas/mode/harga_per_m2, id & lock per item
                    // biaya tambahan) ikut disertakan supaya form "Edit Rincian
                    // Pesanan" bisa tahu item mana yang masih boleh diubah (lihat
                    // BookingController::updateRincianPesanan — cuma item yang
                    // belum ada cicilan tercatat yang boleh diedit/dihapus).
                    'biaya_kelebihan_tanah_aktif'        => $trx->biaya_kelebihan_tanah_aktif,
                    'biaya_kelebihan_tanah_luas'         => $trx->biaya_kelebihan_tanah_luas,
                    'biaya_kelebihan_tanah_mode'         => $trx->biaya_kelebihan_tanah_mode,
                    'biaya_kelebihan_tanah_harga_per_m2' => $trx->biaya_kelebihan_tanah_harga_per_m2,
                    'biaya_kelebihan_tanah_nominal'      => $trx->biaya_kelebihan_tanah_nominal,
                    'biaya_kelebihan_tanah_locked'       => $trx->pembayarans->where('jenis', 'biaya_tanah')->isNotEmpty(),
                    'biaya_tambahan' => $trx->biayaTambahans->map(fn($bt) => [
                        'id'        => $bt->id,
                        'preset_id' => $bt->biaya_tambahan_preset_id,
                        'nama'      => $bt->nama,
                        'nominal'   => $bt->nominal,
                        'locked'    => $bt->pembayarans->isNotEmpty(),
                    ]),
                    'promo_preset_id' => $trx->promo_preset_id,
                    'diskon_mode'    => $trx->diskon_mode,
                    'diskon_nilai'   => $trx->diskon_nilai,
                    'diskon_nominal' => $trx->diskon_nominal,
                    'promo_nama'     => $trx->promoPreset?->nama,
                    'program_all_in_preset_id'  => $trx->program_all_in_preset_id,
                    'program_all_in_nama'        => $trx->programAllInPreset?->nama,
                    'titipan_biaya_akad_nominal' => $trx->titipan_biaya_akad_nominal,
                    'titipan_biaya_akad_locked'  => $trx->pembayarans->where('jenis', 'titipan_biaya_akad')->isNotEmpty(),
                    'status_penjualan' => $trx->status_penjualan,
                    'is_locked'        => $trx->is_locked,
                    'progress_berkas'  => $trx->progress_berkas,
                    'id_rumah'         => $trx->kavling->id_rumah,
                    'dokumens'         => $trx->dokumens->map(fn($d) => [
                        'id'           => $d->id,
                        'nama_dokumen' => $d->nama_dokumen,
                        'sifat'        => $d->sifat,
                        'sifat_label'  => $d->sifat_label,
                        'status'       => $d->status,
                        'status_label' => $d->status_label,
                        'status_icon'  => $d->status_icon,
                    ]),
                    'pembayarans' => $trx->pembayarans->map(fn($p) => [
                        'id'            => $p->id,
                        'jenis'         => $p->jenis,
                        'jenis_label'   => $p->jenis_label,
                        'jumlah'        => $p->jumlah,
                        'tanggal_bayar' => $p->tanggal_bayar?->format('d M Y'),
                        'keterangan'    => $p->keterangan,
                    ]),
                    'jadwal_tagihan' => $trx->jadwalTagihans->map(fn($j) => [
                        'id'                      => $j->id,
                        'jenis'                   => $j->jenis,
                        'jenis_label'             => $j->jenis_label,
                        'nomor_cicilan'           => $j->nomor_cicilan,
                        'jumlah'                  => $j->jumlah,
                        'tanggal_jatuh_tempo'     => $j->tanggal_jatuh_tempo->format('d M Y'),
                        'tanggal_jatuh_tempo_raw' => $j->tanggal_jatuh_tempo->format('Y-m-d'),
                        'status'                  => $j->status,
                        'is_terlambat'            => $j->is_terlambat,
                        'jumlah_dibayar'          => $j->pembayaran?->jumlah,
                        'tanggal_bayar'           => $j->pembayaran?->tanggal_bayar?->format('d M Y'),
                    ]),
                    'kartu_piutang_static' => $breakdown['kartu_piutang_static'],
                    'rincian_biaya_akad' => $trx->rincianBiayaAkad->map(fn($r) => [
                        'id'            => $r->id,
                        'nama'          => $r->nama,
                        'kategori'      => $r->kategori,
                        'nominal'       => $r->nominal,
                        'status'        => $r->status,
                        'jumlah_dibayar' => $r->pembayaran?->jumlah,
                        'tanggal_bayar' => $r->pembayaran?->tanggal_bayar?->format('d M Y'),
                    ]),
                ];
            });

        return Inertia::render('Konsumens/Show', [
            'konsumen'  => [
                'id'                      => $konsumen->id,
                'nama'                    => $konsumen->nama,
                'no_hp'                   => $konsumen->no_hp,
                'nik'                     => $konsumen->nik,
                'npwp'                    => $konsumen->npwp,
                'email'                   => $konsumen->email,
                'alamat'                  => $konsumen->alamat,
                'pekerjaan'               => $konsumen->pekerjaan,
                'pekerjaan_label'         => Konsumen::jenisPekerjaanLabel()[$konsumen->pekerjaan] ?? null,
                'status_pernikahan'       => $konsumen->status_pernikahan,
                'status_pernikahan_label' => Konsumen::statusPernikahanLabel()[$konsumen->status_pernikahan] ?? null,
                'sumber_lead_nama'        => $konsumen->sumberLead?->nama,
                'referral_keterangan'     => $konsumen->sumberLead?->is_referral ? $konsumen->referral_keterangan : null,
                'catatan'   => $konsumen->catatan,
            ],
            'transaksis' => $transaksis,
            'openTransaksiId' => $request->integer('transaksi') ?: null,
            'dajamSbumPresets' => DajamSbumPreset::where('is_active', true)
                ->orderBy('kategori')->orderBy('nama')
                ->get(['id', 'nama', 'kategori']),
            'statusBangunStages' => StatusBangunStage::ordered()->get(['id', 'nama', 'warna']),
            'biayaTambahanPresets' => BiayaTambahanPreset::where('is_active', true)->ordered()->get(['id', 'nama']),
            'promoPresets' => PromoPreset::where('is_active', true)->orderBy('nama')->get(['id', 'nama']),
            'programAllInPresets' => ProgramAllInPreset::where('is_active', true)->ordered()->get(['id', 'nama', 'nominal']),
            'suratTemplates' => SuratTemplate::orderBy('nama')->get(['id', 'nama']),
        ]);
    }

    /* ---------------------------------------------------------------
     | Rincian Biaya Akad (Dana Jaminan / SBUM / Biaya Akad per transaksi)
     --------------------------------------------------------------- */

    public function storeRincianBiayaAkad(Request $request, KavlingKonsumen $transaksi): RedirectResponse
    {
        $this->authorizeProjectAccess($transaksi->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);
        $this->assertTransactionEditable($transaksi, 'Tambah rincian biaya akad');

        $validated = $request->validate([
            'dajam_sbum_preset_id' => 'required|exists:dajam_sbum_presets,id',
            'nominal'              => 'required|numeric|min:0',
        ]);

        $preset = DajamSbumPreset::findOrFail($validated['dajam_sbum_preset_id']);

        $transaksi->rincianBiayaAkad()->create([
            'dajam_sbum_preset_id' => $preset->id,
            'nama'                 => $preset->nama,
            'kategori'             => $preset->kategori,
            'nominal'              => $validated['nominal'],
        ]);

        return back()->with('success', 'Item biaya akad berhasil ditambahkan.');
    }

    public function updateRincianBiayaAkad(Request $request, KavlingKonsumenDajamSbum $rincian): RedirectResponse
    {
        $this->authorizeProjectAccess($rincian->kavlingKonsumen->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);
        $this->assertTransactionEditable($rincian->kavlingKonsumen, 'Update nominal biaya akad');

        $validated = $request->validate([
            'nominal' => 'required|numeric|min:0',
        ]);

        $rincian->update($validated);

        return back()->with('success', 'Nominal biaya akad berhasil diperbarui.');
    }

    public function destroyRincianBiayaAkad(KavlingKonsumenDajamSbum $rincian): RedirectResponse
    {
        $this->authorizeProjectAccess($rincian->kavlingKonsumen->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);
        $this->assertTransactionEditable($rincian->kavlingKonsumen, 'Hapus rincian biaya akad');

        $rincian->delete();

        return back()->with('success', 'Item biaya akad berhasil dihapus.');
    }

    public function edit(Konsumen $konsumen): Response
    {
        $this->authorize('update', $konsumen);

        return Inertia::render('Konsumens/Form', [
            'konsumen' => [
                'id'                => $konsumen->id,
                'nama'              => $konsumen->nama,
                'nik'               => $konsumen->nik,
                'npwp'              => $konsumen->npwp,
                'no_hp'             => $konsumen->no_hp,
                'email'             => $konsumen->email,
                'alamat'            => $konsumen->alamat,
                'pekerjaan'         => $konsumen->pekerjaan,
                'status_pernikahan' => $konsumen->status_pernikahan,
                'sumber_lead_id'    => $konsumen->sumber_lead_id,
                'referral_keterangan' => $konsumen->referral_keterangan,
                'catatan'           => $konsumen->catatan,
            ],
            'jenisPekerjaanOptions'   => Konsumen::jenisPekerjaanLabel(),
            'statusPernikahanOptions' => Konsumen::statusPernikahanLabel(),
            'sumberLeadOptions'       => SumberLead::where('is_active', true)->ordered()->get(['id', 'nama', 'is_referral']),
        ]);
    }

    public function update(Request $request, Konsumen $konsumen): RedirectResponse
    {
        $this->authorize('update', $konsumen);

        $validated = $request->validate([
            'nama'              => 'required|string|max:100',
            'nik'               => "nullable|string|max:20|unique:konsumens,nik,{$konsumen->id}",
            'npwp'              => Konsumen::npwpRules(),
            'no_hp'             => 'nullable|string|max:20',
            'email'             => 'nullable|email|max:100',
            'alamat'            => 'nullable|string',
            'pekerjaan'         => 'nullable|in:' . implode(',', array_keys(Konsumen::jenisPekerjaanLabel())),
            'status_pernikahan' => 'nullable|in:' . implode(',', array_keys(Konsumen::statusPernikahanLabel())),
            'sumber_lead_id'    => 'nullable|exists:sumber_leads,id',
            'referral_keterangan' => [
                'nullable', 'string', 'max:150',
                Rule::requiredIf(fn () => SumberLead::where('id', $request->input('sumber_lead_id'))->where('is_referral', true)->exists()),
            ],
            'catatan'           => 'nullable|string',
        ]);

        // Keterangan referral hanya disimpan kalau Sumber Lead-nya referral.
        if (!SumberLead::where('id', $validated['sumber_lead_id'] ?? null)->where('is_referral', true)->exists()) {
            $validated['referral_keterangan'] = null;
        }

        $konsumen->update($validated);

        return redirect()->route('konsumens.index')
            ->with('success', "Konsumen {$konsumen->nama} berhasil diperbarui.");
    }

    public function destroy(Konsumen $konsumen): RedirectResponse
    {
        $this->authorize('delete', $konsumen);

        $konsumen->delete();

        return redirect()->route('konsumens.index')
            ->with('success', 'Konsumen berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Import Konsumen (konsumen proyek berjalan, langsung di-assign ke
     | kavling & tahap pipeline terakhirnya) — lihat App\Support\
     | KonsumenImportSpec untuk definisi kolom & App\Imports\KonsumenImport
     | untuk logika baris. Template & importer HARUS pakai spec yang sama
     | supaya urutan kolom sinkron (import dibaca berdasar posisi kolom).
     --------------------------------------------------------------- */

    public function downloadImportTemplate(Project $project)
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('book kavling'), 403);

        $PURPLE = '7C3AED';
        $NAVY = '1E3A5F';
        $AMBER = 'B45309';

        $ss = new Spreadsheet();
        $ss->removeSheetByIndex(0);

        // ── Sheet Daftar (sumber dropdown) — dibuat lebih dulu supaya range-nya siap dipakai sheet lain.
        $lists = KonsumenImportSpec::lists();
        $daftar = $ss->createSheet();
        $daftar->setTitle('Daftar');
        $listRef = [];
        $col = 1;
        foreach ($lists as $name => $items) {
            $daftar->setCellValue([$col, 1], $name);
            foreach ($items as $i => $val) $daftar->setCellValue([$col, $i + 2], $val);
            $L = Coordinate::stringFromColumnIndex($col);
            $listRef[$name] = count($items) ? "=Daftar!\${$L}\$2:\${$L}$" . (count($items) + 1) : null;
            $daftar->getColumnDimensionByColumn($col)->setWidth(24);
            $col++;
        }
        $lastListCol = Coordinate::stringFromColumnIndex(count($lists));
        $daftar->getStyle("A1:{$lastListCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $daftar->getStyle("A1:{$lastListCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('475569');

        // ── Sheet per cara bayar ──
        $reqLabel = ['w' => 'Wajib', 'o' => 'Opsional', 'k' => 'Kondisional'];
        $kamus = []; // key gabungan (dinamis diringkas) => [label, sheets, req, rule]
        $colCounts = [];
        foreach (KonsumenImportSpec::CARA_BAYAR_SHEETS as $caraBayar => $title) {
            $isKpr = KonsumenImportSpec::isKpr($caraBayar);
            $cols = KonsumenImportSpec::columns($caraBayar);
            $colCounts[$title] = count($cols);

            $sheet = $ss->createSheet();
            $sheet->setTitle($title);
            foreach ($cols as $i => [$key, $label, $req]) {
                $mark = $req === 'w' ? ' *' : ($req === 'k' ? ' †' : '');
                $sheet->setCellValue([$i + 1, 1], $label . $mark);
                $sheet->getColumnDimensionByColumn($i + 1)->setWidth(20);

                $kamusKey = preg_match('/^(bt|sbum|dajam|dok):/', $key, $m) ? $m[1] : $key;
                if (!isset($kamus[$kamusKey])) {
                    $klabel = [
                        'bt' => '★ Biaya Tambahan: <nama preset> — Target / Terbayar / Tgl Terakhir',
                        'sbum' => '★ SBUM: <nama preset> — Nominal / Tgl Cair',
                        'dajam' => '★ Dajam: <nama preset> — Nominal / Tgl Cair',
                        'dok' => '★ Dok: <nama dokumen>',
                    ][$kamusKey] ?? $label;
                    $kamus[$kamusKey] = [$klabel, [], $req, $cols[$i][3] ?? ''];
                }
                $kamus[$kamusKey][1][$title] = true;
            }
            $lastCol = Coordinate::stringFromColumnIndex(count($cols));
            $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($isKpr ? $NAVY : $PURPLE);
            $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(1)->setRowHeight(48);
            $sheet->freezePane('B2');

            foreach ($cols as $i => [$key]) {
                $listName = KonsumenImportSpec::dropdownListFor($key, $caraBayar);
                if (!$listName || empty($listRef[$listName])) continue;
                $L = Coordinate::stringFromColumnIndex($i + 1);
                for ($r = 2; $r <= 300; $r++) {
                    $v = $sheet->getCell("{$L}{$r}")->getDataValidation();
                    $v->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP);
                    $v->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true);
                    $v->setErrorTitle('Pilihan tidak dikenal')->setError('Pilih salah satu dari daftar.');
                    $v->setFormula1($listRef[$listName]);
                }
            }
        }

        // ── Sheet Kamus Kolom ──
        $k = $ss->createSheet();
        $k->setTitle('Kamus Kolom');
        $k->fromArray(['Kolom', 'Ada di sheet', 'Wajib?', 'Aturan / keterangan'], null, 'A1');
        $k->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $k->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($PURPLE);
        $row = 2;
        foreach ($kamus as $key => [$label, $sheets, $req, $rule]) {
            $all = count($sheets) === count(KonsumenImportSpec::CARA_BAYAR_SHEETS);
            $k->setCellValue("A{$row}", $label);
            $k->setCellValue("B{$row}", $all ? 'Semua sheet' : implode(', ', array_keys($sheets)));
            $k->setCellValue("C{$row}", $reqLabel[$req]);
            $k->setCellValue("D{$row}", $rule);
            if (str_starts_with($label, '★')) $k->getStyle("A{$row}:D{$row}")->getFont()->getColor()->setRGB($AMBER);
            $row++;
        }
        $k->getStyle("A2:D{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        foreach (['A' => 38, 'B' => 22, 'C' => 13, 'D' => 95] as $c => $w) $k->getColumnDimension($c)->setWidth($w);
        $k->freezePane('A2');

        // ── Sheet Petunjuk ──
        $h = $ss->createSheet();
        $h->setTitle('Petunjuk');
        $h->setCellValue('A1', "Import Konsumen — {$project->nama}");
        $h->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $rows = [
            ['Tujuan', 'Memasukkan konsumen proyek berjalan, langsung di-assign ke kavling & tahap pipeline terakhirnya, lengkap dengan rekap pembayaran.'],
            ['Cara baca header', '* = wajib   † = kondisional (wajib/berlaku tergantung isi kolom lain)   ★ = KRUSIAL (lihat sheet Kamus Kolom): jumlah kolomnya mengikuti jumlah preset di master data saat file ini dibuat. Tanpa tanda = opsional.'],
            ['1 sheet per cara bayar', 'KPR Subsidi, KPR Komersil, Cash, Cash Bertahap — jangan pindah baris antar sheet atau ubah urutan kolom.'],
            ['Saldo Awal (Import)', 'Kolom "Terbayar" adalah REKAP total sampai kapan data ini dikumpulkan, bukan cicilan satu-satu. Sistem mencatat 1 (atau beberapa untuk komponen bercicilan) baris "Saldo Awal (Import)" di Riwayat Pembayaran.'],
            ['Nominal + Tanggal (Dajam/SBUM/Biaya Tambahan)', 'Nominal kosong = item tidak berlaku (tidak dibuat). Nominal terisi tanpa tanggal = berlaku, belum bayar/cair. Keduanya terisi = sudah lunas/cair.'],
            ['Field profil konsumen opsional', 'NIK, No. HP, Email, Pekerjaan, Status Pernikahan, Sumber Lead, Sales/Agent boleh dikosongkan kalau memang tidak tercatat di data lama — JANGAN mengarang.'],
            ['NIK', 'Kalau ada, jadi kunci pencocokan konsumen (1 konsumen 2 unit → isi NIK sama di tiap baris). Kosong → selalu dianggap konsumen baru.'],
            ['Harga Dasar & Harga Deal', 'Harga Dasar kosong = pakai Harga di Stok Kavling (wajib diisi manual kalau harga unit itu juga kosong). Harga Deal TIDAK diinput — dihitung otomatis dari Harga Dasar + Biaya Tanah + Biaya Tambahan Lain − Diskon.'],
            ['Aturan skip', 'Nilai dropdown tak dikenal, kolom wajib kosong, atau unit tidak tersedia → baris DILEWATI dengan pesan error (bukan ditebak). Baris lain tetap masuk. Ringkasan error muncul setelah upload.'],
            ['Kavling', 'Kluster+Blok+Nomor harus sudah ada di Stok Kavling & berstatus Tersedia. Setelah import, status unit otomatis: sebelum Akad → Dipesan, Akad/BAST → Terjual.'],
            ['Selesai otomatis', 'Kalau semua piutang konsumen & bank pada baris itu sudah lunas (rekap Terbayar mencukupi), transaksi otomatis ditandai Selesai & terkunci — sama seperti tombol "Tandai Selesai" manual.'],
        ];
        $h->fromArray(['Bagian', 'Keterangan'], null, 'A3');
        $h->getStyle('A3:B3')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $h->getStyle('A3:B3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($PURPLE);
        $h->fromArray($rows, null, 'A4');
        $r = 4 + count($rows) + 1;

        $h->setCellValue("A{$r}", 'Strategi migrasi — kerjakan bertahap per kelompok, termudah dulu');
        $h->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        $kelompok = [
            ['1. Akad & sudah cair', 'Rekap final saja. Dokumen dianggap lengkap (Sudah Ada semua). Field profil boleh kosong kalau tidak ketemu.'],
            ['2. Akad, KPR belum cair', 'Sama seperti kelompok 1, TAPI crosscheck manual nominal tiap Dajam, SBUM, dan Pencairan KPR — tiga hal ini independen.'],
            ['3. Proses Bank / SP3K', 'Dokumen wajib dianggap lengkap. Isi Plafon KPR, Tanggal Pengajuan Bank, Status/Tanggal SP3K.'],
            ['4. Booking / Pemberkasan', 'PALING BERAT: status tiap dokumen harus dicek sungguhan per konsumen. Kerjakan paling akhir.'],
        ];
        $h->fromArray($kelompok, null, "A{$r}");
        $h->getStyle("A{$r}:B" . ($r + count($kelompok) - 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $r += count($kelompok) + 1;

        $h->setCellValue("A{$r}", 'Jumlah kolom per sheet (saat file ini dibuat)');
        $h->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        foreach ($colCounts as $t => $cnt) { $h->setCellValue("A{$r}", $t); $h->setCellValue("B{$r}", "{$cnt} kolom"); $r++; }

        $h->getStyle("A4:B{$r}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $h->getColumnDimension('A')->setWidth(34);
        $h->getColumnDimension('B')->setWidth(105);

        $order = array_merge(['Petunjuk', 'Kamus Kolom'], array_values(KonsumenImportSpec::CARA_BAYAR_SHEETS), ['Daftar']);
        foreach ($order as $idx => $name) {
            $sheet = $ss->getSheetByName($name);
            $cur = $ss->getIndex($sheet);
            if ($cur !== $idx) { $ss->removeSheetByIndex($cur); $ss->addSheet($sheet, $idx); }
        }
        $ss->setActiveSheetIndex(0);

        $writer = new Xlsx($ss);
        $filename = 'template-import-konsumen-' . \Illuminate\Support\Str::slug($project->nama) . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function importKonsumen(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('book kavling'), 403);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $import = new KonsumenImport($project);
        Excel::import($import, $request->file('file'));
        $result = $import->result;

        $msg = "Import selesai: {$result->imported} konsumen berhasil ditambahkan";
        if ($result->skipped > 0) $msg .= ", {$result->skipped} baris dilewati";

        if (!empty($result->errors)) {
            return back()->with('warning', $msg)->with('importErrors', array_slice($result->errors, 0, 50));
        }

        return back()->with('success', $msg . '.');
    }
}
