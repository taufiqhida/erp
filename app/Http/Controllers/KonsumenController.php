<?php

namespace App\Http\Controllers;

use App\Enums\StatusBangun;
use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Http\Controllers\Concerns\ChecksTransactionLock;
use App\Models\CancellationRequest;
use App\Models\DajamSbumPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\KavlingKonsumenDajamSbum;
use App\Models\Konsumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

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
            if ($request->tipe_unit) $q->where('tipe_unit', $request->tipe_unit);
            if ($request->status_jual) $q->where('status_jual', $request->status_jual);
            if ($request->status_bangun) $q->where('status_bangun', $request->status_bangun);
        };
        $hasUnitFilter = $activeProjectId || collect(['kluster', 'blok', 'tipe_unit', 'status_jual', 'status_bangun'])
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
            'tipe_unit'     => (clone $optionsQuery)->whereNotNull('tipe_unit')->where('tipe_unit', '!=', '')->distinct()->orderBy('tipe_unit')->pluck('tipe_unit'),
            'status_jual'   => collect(StatusJual::cases())->mapWithKeys(fn($s) => [$s->value => $s->label()]),
            'status_bangun' => collect(StatusBangun::cases())->mapWithKeys(fn($s) => [$s->value => $s->label()]),
        ];

        if ($viewMode === 'unit') {
            $rows = KavlingKonsumen::query()
                ->with(['konsumen', 'kavling.project', 'dokumens'])
                ->whereHas('kavling', function ($q) use ($unitFilter, $isGlobal, $projectScope) {
                    $unitFilter($q);
                    if (!$isGlobal) $projectScope($q);
                })
                ->when($request->search, fn($q) =>
                    $q->whereHas('konsumen', fn($q2) => $q2->where(fn($q3) => $q3
                        ->where('nama', 'like', "%{$request->search}%")
                        ->orWhere('no_hp', 'like', "%{$request->search}%")
                        ->orWhere('nik', 'like', "%{$request->search}%")
                    ))
                )
                ->orderByDesc('created_at')
                ->paginate(20)
                ->withQueryString()
                ->through(fn($trx) => [
                    'id'                => $trx->id,
                    'konsumen_id'       => $trx->konsumen_id,
                    'konsumen_nama'     => $trx->konsumen->nama,
                    'konsumen_no_hp'    => $trx->konsumen->no_hp,
                    'kavling_nomor'     => $trx->kavling->nomor_lengkap,
                    'project_nama'      => $trx->kavling->project->nama,
                    'kluster'           => $trx->kavling->kluster,
                    'blok'              => $trx->kavling->blok,
                    'tipe_unit'         => $trx->kavling->tipe_unit,
                    'status_jual'       => $trx->kavling->status_jual->value,
                    'status_jual_label' => $trx->kavling->status_jual_label,
                    'status_bangun'       => $trx->kavling->status_bangun->value,
                    'status_bangun_label' => $trx->kavling->status_bangun_label,
                    'harga_deal'        => $trx->harga_deal,
                    'cara_bayar_label'  => $this->caraBayarLabel($trx->cara_bayar),
                    'bank_rekanan_kpr'  => $trx->bank_rekanan_kpr,
                    'status_penjualan'       => $trx->status_penjualan,
                    'status_penjualan_label' => $trx->status_penjualan_label,
                    'progress_berkas'   => $trx->progress_berkas,
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
                ->with(['kavlingKonsumens' => fn($q) =>
                    $q->when($hasUnitFilter, fn($q2) => $q2->whereHas('kavling', $unitFilter))
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
                    ]),
                ]);
        }

        return Inertia::render('Konsumens/Index', [
            'mode'          => $viewMode,
            'rows'          => $rows,
            'filterOptions' => $filterOptions,
            'filters'       => $request->only(['search', 'kluster', 'blok', 'tipe_unit', 'status_jual', 'status_bangun', 'view']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Konsumen::class);

        return Inertia::render('Konsumens/Form', [
            'konsumen' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Konsumen::class);

        $validated = $request->validate([
            'nama'      => 'required|string|max:100',
            'nik'       => 'nullable|string|max:20|unique:konsumens,nik',
            'no_hp'     => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:100',
            'alamat'    => 'nullable|string',
            'pekerjaan' => 'nullable|string|max:100',
            'catatan'   => 'nullable|string',
            'drive_folder_link' => 'nullable|url|max:255',
        ]);

        $konsumen = Konsumen::create($validated);

        return redirect()->route('konsumens.index')
            ->with('success', "Konsumen {$konsumen->nama} berhasil ditambahkan.");
    }

    public function show(Request $request, Konsumen $konsumen): Response
    {
        $this->authorize('view', $konsumen);

        $transaksis = $konsumen->kavlingKonsumens()
            ->with([
                'kavling.project:id,nama', 'dokumens', 'pembayarans', 'biayaTambahans.pembayaran',
                'promoPreset:id,nama', 'skemaDpPreset', 'biayaKelebihanTanahPembayaran',
                'jadwalTagihans' => fn($q) => $q->orderBy('jenis')->orderBy('nomor_cicilan'),
                'jadwalTagihans.pembayaran',
                'rincianBiayaAkad' => fn($q) => $q->orderBy('kategori')->orderBy('nama'),
                'rincianBiayaAkad.pembayaran',
                'pencairanKprTahaps' => fn($q) => $q->orderBy('tanggal_cair'),
                'tambahanUmPembayaran',
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
                    'luas_tanah'       => $trx->kavling->luas_tanah,
                    'luas_bangunan'    => $trx->kavling->luas_bangunan,
                    'tipe_unit'        => $trx->kavling->tipe_unit,
                    'status_bangun'       => $trx->kavling->status_bangun->value,
                    'status_bangun_label' => $trx->kavling->status_bangun_label,
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
                    'biaya_kelebihan_tanah_aktif'   => $trx->biaya_kelebihan_tanah_aktif,
                    'biaya_kelebihan_tanah_nominal' => $trx->biaya_kelebihan_tanah_nominal,
                    'biaya_tambahan' => $trx->biayaTambahans->map(fn($bt) => [
                        'nama'    => $bt->nama,
                        'nominal' => $bt->nominal,
                    ]),
                    'diskon_mode'    => $trx->diskon_mode,
                    'diskon_nilai'   => $trx->diskon_nilai,
                    'diskon_nominal' => $trx->diskon_nominal,
                    'promo_nama'     => $trx->promoPreset?->nama,
                    'status_penjualan' => $trx->status_penjualan,
                    'is_locked'        => $trx->is_locked,
                    'progress_berkas'  => $trx->progress_berkas,
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
                'id'        => $konsumen->id,
                'nama'      => $konsumen->nama,
                'no_hp'     => $konsumen->no_hp,
                'nik'       => $konsumen->nik,
                'email'     => $konsumen->email,
                'alamat'    => $konsumen->alamat,
                'pekerjaan' => $konsumen->pekerjaan,
                'catatan'   => $konsumen->catatan,
                'drive_folder_link' => $konsumen->drive_folder_link,
            ],
            'transaksis' => $transaksis,
            'openTransaksiId' => $request->integer('transaksi') ?: null,
            'dajamSbumPresets' => DajamSbumPreset::where('is_active', true)
                ->orderBy('kategori')->orderBy('nama')
                ->get(['id', 'nama', 'kategori']),
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
                'id'        => $konsumen->id,
                'nama'      => $konsumen->nama,
                'nik'       => $konsumen->nik,
                'no_hp'     => $konsumen->no_hp,
                'email'     => $konsumen->email,
                'alamat'    => $konsumen->alamat,
                'pekerjaan' => $konsumen->pekerjaan,
                'catatan'   => $konsumen->catatan,
                'drive_folder_link' => $konsumen->drive_folder_link,
            ],
        ]);
    }

    public function update(Request $request, Konsumen $konsumen): RedirectResponse
    {
        $this->authorize('update', $konsumen);

        $validated = $request->validate([
            'nama'              => 'required|string|max:100',
            'nik'               => "nullable|string|max:20|unique:konsumens,nik,{$konsumen->id}",
            'no_hp'             => 'nullable|string|max:20',
            'email'             => 'nullable|email|max:100',
            'alamat'            => 'nullable|string',
            'pekerjaan'         => 'nullable|string|max:100',
            'catatan'           => 'nullable|string',
            'drive_folder_link' => 'nullable|url|max:255',
        ]);

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
}
