<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Http\Controllers\Concerns\ChecksTransactionLock;
use App\Models\JadwalTagihan;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\KavlingKonsumenBiayaTambahan;
use App\Models\KavlingKonsumenDajamSbum;
use App\Models\PembayaranKonsumen;
use App\Models\PencairanKprTahap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class KeuanganController extends Controller
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

    private function statusPenjualanLabelMap(): array
    {
        return [
            'booking'      => 'Booking',
            'pemberkasan'  => 'Pemberkasan',
            'proses_bank'  => 'Proses Bank/SLIK',
            'sp3k'         => 'SP3K',
            'rencana_akad' => 'Rencana Akad',
            'akad'         => 'Akad',
            'bast'         => 'BAST / Selesai',
            'batal'        => 'Batal',
        ];
    }

    /**
     * Status pembayaran sekarang 3 tingkat, bukan cuma lunas/belum — nominal
     * yang dicatat dibandingkan ke nominal yang harus dibayar: kurang dari
     * itu berarti baru sebagian, pas/lebih berarti lunas. Ini dipakai di
     * semua endpoint catat-pembayaran item tunggal (bukan Kartu Piutang
     * grup yang statusnya dihitung dari agregat cicilan di model).
     */
    private function resolveItemStatus(float $jumlahDibayar, float $nominalHarusDibayar): string
    {
        return $jumlahDibayar >= $nominalHarusDibayar ? 'lunas' : 'sebagian';
    }

    /**
     * Halaman Keuangan — monitoring & pencatatan pembayaran konsumen +
     * pencairan KPR/bank dalam satu tabel (Tab Pembayaran Konsumen, Pencairan
     * KPR, & Klaim SBUM lama digabung — SBUM sekarang dilacak per-item lewat
     * Rincian Biaya Akad, bukan SbumRecord terpisah lagi).
     */
    public function index(Request $request): Response
    {
        abort_unless(Auth::user()->can('view keuangan'), 403);

        $user = Auth::user();
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);
        // Proyek aktif (Halaman Utama Pilih Proyek) — bukan lagi filter
        // dropdown di halaman ini. Kosong berarti mode "Semua Proyek".
        $projectId = session('current_project_id');
        $projectScope = fn($q) => $q->whereHas('project.users', fn($q2) => $q2->where('users.id', $user->id));

        $optionsQuery = Kavling::query()
            ->when(!$isGlobal, $projectScope)
            ->when($projectId, fn($q) => $q->where('project_id', $projectId));
        $filterOptions = [
            'kluster'          => (clone $optionsQuery)->whereNotNull('kluster')->where('kluster', '!=', '')->distinct()->orderBy('kluster')->pluck('kluster'),
            'blok'             => (clone $optionsQuery)->whereNotNull('blok')->where('blok', '!=', '')->distinct()->orderBy('blok')->pluck('blok'),
            'status_penjualan' => $this->statusPenjualanLabelMap(),
            'cara_bayar'       => [
                'cash' => 'Cash', 'cash_bertahap' => 'Cash Bertahap',
                'kpr_subsidi' => 'KPR Subsidi', 'kpr_komersil' => 'KPR Komersil',
            ],
            'bank' => KavlingKonsumen::whereNotNull('bank_rekanan_kpr')->where('bank_rekanan_kpr', '!=', '')
                ->distinct()->orderBy('bank_rekanan_kpr')->pluck('bank_rekanan_kpr'),
        ];

        $rows = KavlingKonsumen::query()
            ->with([
                'konsumen:id,nama', 'kavling.project:id,nama', 'skemaDpPreset', 'pembayarans',
                'jadwalTagihans.pembayaran', 'biayaTambahans.pembayaran',
                'biayaKelebihanTanahPembayaran', 'rincianBiayaAkad.pembayaran',
                'pencairanKprTahaps', 'tambahanUmPembayaran',
            ])
            ->whereHas('kavling', function ($q) use ($projectId, $isGlobal, $projectScope, $request) {
                if ($projectId) $q->where('project_id', $projectId);
                if ($request->kluster) $q->where('kluster', $request->kluster);
                if ($request->blok) $q->where('blok', $request->blok);
                if (!$isGlobal) $projectScope($q);
            })
            ->when($request->status_penjualan, fn($q) => $q->where('status_penjualan', $request->status_penjualan))
            ->when($request->cara_bayar, fn($q) => $q->where('cara_bayar', $request->cara_bayar))
            ->when($request->bank, fn($q) => $q->where('bank_rekanan_kpr', $request->bank))
            // Transaksi 'completed' (sudah ditandai Selesai) TETAP tampil di
            // sini — cuma yang batal (cancelled) yang disaring keluar.
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($kk) {
                $breakdown = $kk->kartuPiutangBreakdown();
                $isKpr = in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil']);

                return [
                    'id'                     => $kk->id,
                    'konsumen_id'            => $kk->konsumen_id,
                    'konsumen_nama'          => $kk->konsumen->nama,
                    'kavling_nomor'          => $kk->kavling->nomor_lengkap,
                    'project_nama'           => $kk->kavling->project->nama,
                    'status'                 => $kk->status,
                    'status_penjualan'       => $kk->status_penjualan,
                    'status_penjualan_label' => $this->statusPenjualanLabelMap()[$kk->status_penjualan] ?? $kk->status_penjualan_label,
                    'harga_deal'             => $kk->harga_deal,
                    'cara_bayar'             => $kk->cara_bayar,
                    'cara_bayar_label'       => $this->caraBayarLabel($kk->cara_bayar),
                    'bank_rekanan_kpr'       => $kk->bank_rekanan_kpr,
                    'total_piutang_konsumen'  => $breakdown['total_piutang_konsumen'],
                    'total_terbayar_konsumen' => $breakdown['total_terbayar_konsumen'],
                    'is_kpr'                 => $isKpr,
                    'total_piutang_bank'      => $breakdown['total_piutang_bank'],
                    'total_terbayar_bank'     => $breakdown['total_terbayar_bank'],
                ];
            });

        return Inertia::render('Keuangan/Index', [
            'rows'          => $rows,
            'filterOptions' => $filterOptions,
            'filters'       => $request->only(['kluster', 'blok', 'status_penjualan', 'cara_bayar', 'bank']),
        ]);
    }

    /**
     * Detail satu transaksi khusus buat Keuangan — fokus ke Kartu Piutang,
     * Pencairan KPR, & Kartu Piutang Titipan aja (bukan Identitas/Pemberkasan/
     * Rincian Pemesanan yang itu domainnya halaman Konsumen di Penjualan).
     */
    public function detail(KavlingKonsumen $kk): Response
    {
        abort_unless(Auth::user()->can('view keuangan'), 403);
        $this->authorizeProjectAccess($kk->kavling->project);

        $kk->load([
            'konsumen:id,nama,no_hp', 'kavling.project:id,nama', 'skemaDpPreset', 'promoPreset:id,nama', 'pembayarans',
            'jadwalTagihans' => fn($q) => $q->orderBy('jenis')->orderBy('nomor_cicilan'),
            'jadwalTagihans.pembayaran',
            'biayaTambahans.pembayaran',
            'biayaKelebihanTanahPembayaran',
            'rincianBiayaAkad' => fn($q) => $q->orderBy('kategori')->orderBy('nama'),
            'rincianBiayaAkad.pembayaran',
            'pencairanKprTahaps' => fn($q) => $q->orderBy('tanggal_cair'),
            'tambahanUmPembayaran',
        ]);

        $breakdown = $kk->kartuPiutangBreakdown();
        $isKpr = in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil']);
        $isKprSubsidi = $kk->cara_bayar === 'kpr_subsidi';
        $dpTotal = (float) $kk->jadwalTagihans->where('jenis', 'dp')->sum('jumlah');

        return Inertia::render('Keuangan/TransaksiDetail', [
            'transaksi' => [
                'id'                     => $kk->id,
                'konsumen_id'            => $kk->konsumen_id,
                'konsumen_nama'          => $kk->konsumen->nama,
                'konsumen_no_hp'         => $kk->konsumen->no_hp,
                'kavling_nomor'          => $kk->kavling->nomor_lengkap,
                'project_nama'           => $kk->kavling->project->nama,
                'harga_deal'             => $kk->harga_deal,
                'cara_bayar'             => $kk->cara_bayar,
                'cara_bayar_label'       => $this->caraBayarLabel($kk->cara_bayar),
                'bank_rekanan_kpr'       => $kk->bank_rekanan_kpr,
                // ── Rincian Pembayaran: breakdown kalkulasi harga persis
                // seperti Rincian Pemesanan di tab Konsumen — supaya
                // finance juga tahu Pelunasan/Plafon itu terdiri dari apa
                // saja, bukan cuma angka akhir.
                'harga_dasar'      => $kk->harga_dasar,
                'booking_fee'      => $kk->booking_fee,
                'dp_nominal'       => $dpTotal,
                'biaya_kelebihan_tanah_aktif'   => $kk->biaya_kelebihan_tanah_aktif,
                'biaya_kelebihan_tanah_nominal' => $kk->biaya_kelebihan_tanah_nominal,
                'biaya_tambahan'   => $kk->biayaTambahans->map(fn($bt) => [
                    'nama'    => $bt->nama,
                    'nominal' => $bt->nominal,
                ]),
                'diskon_mode'    => $kk->diskon_mode,
                'diskon_nilai'   => $kk->diskon_nilai,
                'diskon_nominal' => $kk->diskon_nominal,
                'promo_nama'     => $kk->promoPreset?->nama,
                'skema_dp_preset' => $kk->skemaDpPreset ? [
                    'nama'                         => $kk->skemaDpPreset->nama,
                    'booking_fee_aktif'            => $kk->skemaDpPreset->booking_fee_aktif,
                    'booking_fee_tipe'             => $kk->skemaDpPreset->booking_fee_tipe,
                    'booking_fee_nilai'            => $kk->skemaDpPreset->booking_fee_nilai,
                    'booking_fee_tenor'            => $kk->skemaDpPreset->booking_fee_tenor,
                    'booking_fee_masuk_harga_jual' => $kk->skemaDpPreset->booking_fee_masuk_harga_jual,
                    'dp_aktif'                     => $kk->skemaDpPreset->dp_aktif,
                    'dp_tipe'                      => $kk->skemaDpPreset->dp_tipe,
                    'dp_nilai'                     => $kk->skemaDpPreset->dp_nilai,
                    'dp_tenor'                     => $kk->skemaDpPreset->dp_tenor,
                    'dp_masuk_harga_jual'          => $kk->skemaDpPreset->dp_masuk_harga_jual,
                ] : null,
                'status_penjualan'       => $kk->status_penjualan,
                'status_penjualan_label' => $this->statusPenjualanLabelMap()[$kk->status_penjualan] ?? $kk->status_penjualan_label,
                'status'                 => $kk->status,
                'is_locked'              => $kk->is_locked,
                'is_kpr'                 => $isKpr,
                'is_kpr_subsidi'         => $isKprSubsidi,
                'kartu_piutang_static'   => $breakdown['kartu_piutang_static'],
                'pencairan_kpr'          => $breakdown['pencairan_kpr'],
                'total_piutang_konsumen'  => $breakdown['total_piutang_konsumen'],
                'total_terbayar_konsumen' => $breakdown['total_terbayar_konsumen'],
                'total_piutang_bank'     => $breakdown['total_piutang_bank'],
                'total_terbayar_bank'    => $breakdown['total_terbayar_bank'],
                'can_complete'           => $breakdown['total_terbayar_konsumen'] >= $breakdown['total_piutang_konsumen']
                    && $breakdown['total_terbayar_bank'] >= $breakdown['total_piutang_bank'],
                'pencairan_kpr_tahaps'   => $kk->pencairanKprTahaps->map(fn($t) => [
                    'id'           => $t->id,
                    'nominal'      => $t->nominal,
                    'tanggal_cair' => $t->tanggal_cair->format('d M Y'),
                    'tanggal_cair_raw' => $t->tanggal_cair->format('Y-m-d'),
                    'keterangan'   => $t->keterangan,
                ]),
                'rincian_biaya_akad'     => $kk->rincianBiayaAkad->map(fn($r) => [
                    'id'            => $r->id,
                    'nama'          => $r->nama,
                    'kategori'      => $r->kategori,
                    'nominal'       => $r->nominal,
                    'status'        => $r->status,
                    'jumlah_dibayar' => $r->pembayaran?->jumlah,
                    'tanggal_bayar' => $r->pembayaran?->tanggal_bayar?->format('d M Y'),
                ]),
                'jadwal_tagihan' => $kk->jadwalTagihans->map(fn($j) => [
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
                'pembayarans' => $kk->pembayarans->map(fn($p) => [
                    'id'            => $p->id,
                    'jenis_label'   => $p->jenis_label,
                    'jumlah'        => $p->jumlah,
                    'tanggal_bayar' => $p->tanggal_bayar?->format('d M Y'),
                    'keterangan'    => $p->keterangan,
                ]),
            ],
        ]);
    }

    /**
     * Tandai transaksi Selesai — INI yang jadi pemicu lock (bukan status
     * Akad), karena pencairan bank/dajam bisa masih proses beberapa saat
     * setelah akad. Tombolnya cuma aktif kalau semua piutang konsumen DAN
     * semua piutang bank (kalau KPR) sudah lunas — dicek ulang di server,
     * bukan cuma percaya tombol frontend disabled.
     */
    public function markComplete(KavlingKonsumen $kk): RedirectResponse
    {
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->authorizeProjectAccess($kk->kavling->project);

        $kk->load([
            'jadwalTagihans.pembayaran', 'biayaTambahans.pembayaran',
            'biayaKelebihanTanahPembayaran', 'rincianBiayaAkad.pembayaran',
            'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps', 'tambahanUmPembayaran',
        ]);
        $breakdown = $kk->kartuPiutangBreakdown();

        abort_unless(
            $breakdown['total_terbayar_konsumen'] >= $breakdown['total_piutang_konsumen']
            && $breakdown['total_terbayar_bank'] >= $breakdown['total_piutang_bank'],
            422,
            'Semua pembayaran konsumen dan pencairan bank harus lunas dulu sebelum transaksi bisa ditandai selesai.'
        );

        $kk->update(['status' => 'completed']);

        return back()->with('success', 'Transaksi ditandai selesai.');
    }

    /**
     * Input/edit pembayaran Pelunasan untuk transaksi cash/cash bertahap LAMA
     * yang belum punya jadwal cicilan (fallback single-row — lihat
     * KavlingKonsumen::kartuPiutangBreakdown()). Dibuat sebagai
     * updateOrCreate: kalau sudah pernah dicatat, submit berikutnya EDIT
     * baris yang sama (bukan bikin baris baru) — supaya bisa dikoreksi
     * kalau salah input atau memang baru bayar sebagian.
     */
    public function storePembayaran(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Catat pembayaran');

        $validated = $request->validate([
            'jenis'        => 'required|in:booking_fee,dp,angsuran,pelunasan',
            'jumlah'       => 'required|numeric|min:1',
            'tanggal_bayar' => 'required|date',
            'keterangan'   => 'nullable|string|max:500',
        ]);

        $existing = $kk->pembayarans()->where('jenis', $validated['jenis'])->first();
        if ($existing) {
            $existing->update([
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? $existing->keterangan,
            ]);
            $pembayaran = $existing;
        } else {
            $pembayaran = $kk->pembayarans()->create([
                ...$validated,
                'created_by' => Auth::id(),
            ]);
        }

        // Tandai lunas cicilan tertua di Kartu Piutang yang jenisnya cocok
        // (FIFO) — angsuran/pelunasan tidak mengacu ke jadwal tenor preset,
        // jadi tidak ikut di-match otomatis.
        if (in_array($validated['jenis'], ['booking_fee', 'dp'])) {
            $kk->jadwalTagihans()
                ->where('jenis', $validated['jenis'])
                ->where('status', 'belum_bayar')
                ->orderBy('nomor_cicilan')
                ->first()
                ?->update(['status' => 'lunas', 'pembayaran_konsumen_id' => $pembayaran->id]);
        }

        return back()->with('success', 'Pembayaran berhasil dicatat.');
    }

    /**
     * Batalkan pencatatan pembayaran Pelunasan fallback (transaksi lama
     * tanpa jadwal cicilan) — reset ke belum_bayar.
     */
    public function destroyPembayaran(PembayaranKonsumen $pembayaran): RedirectResponse
    {
        $kk = $pembayaran->transaksi;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Hapus pembayaran');

        $pembayaran->delete();

        return back()->with('success', 'Pencatatan pembayaran dibatalkan.');
    }

    /**
     * Catat/edit pembayaran untuk satu cicilan Kartu Piutang (booking
     * fee/DP/pelunasan bertahap) — beda dari storePembayaran() yang match
     * FIFO otomatis, di sini user pilih cicilan yang mana persis. Kalau
     * cicilan ini sudah pernah dicatat pembayarannya, submit berikutnya
     * EDIT baris yang sama (bukan bikin baru) — nominal yang diinput
     * dibanding ke nominal cicilan menentukan status sebagian/lunas.
     */
    public function payJadwalTagihan(Request $request, JadwalTagihan $jadwal): RedirectResponse
    {
        $kk = $jadwal->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Catat pembayaran cicilan');

        $validated = $request->validate([
            'jumlah'        => 'required|numeric|min:1',
            'tanggal_bayar' => 'required|date',
            'keterangan'    => 'nullable|string|max:500',
        ]);

        if ($jadwal->pembayaran) {
            $jadwal->pembayaran->update([
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? $jadwal->pembayaran->keterangan,
            ]);
        } else {
            $pembayaran = $kk->pembayarans()->create([
                'jenis'         => $jadwal->jenis,
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? "{$jadwal->jenis_label} #{$jadwal->nomor_cicilan}",
                'created_by'    => Auth::id(),
            ]);
            $jadwal->pembayaran_konsumen_id = $pembayaran->id;
        }

        $jadwal->status = $this->resolveItemStatus((float) $validated['jumlah'], (float) $jadwal->jumlah);
        $jadwal->save();

        return back()->with('success', 'Pembayaran cicilan berhasil dicatat.');
    }

    /**
     * Batalkan pencatatan pembayaran satu cicilan — reset ke belum_bayar
     * (mis. salah pilih cicilan atau salah transaksi sama sekali).
     */
    public function destroyJadwalTagihanPembayaran(JadwalTagihan $jadwal): RedirectResponse
    {
        $kk = $jadwal->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Hapus pembayaran cicilan');

        $pembayaran = $jadwal->pembayaran;
        $jadwal->update(['status' => 'belum_bayar', 'pembayaran_konsumen_id' => null]);
        $pembayaran?->delete();

        return back()->with('success', 'Pencatatan pembayaran cicilan dibatalkan.');
    }

    /**
     * Ubah rencana satu cicilan (nominal dan/atau tanggal jatuh tempo) —
     * dipakai admin utk koreksi manual dari hasil bagi rata otomatis saat
     * booking (mis. cicilan terakhir digenapkan, atau konsumen minta
     * jadwal disesuaikan). Hanya untuk cicilan yang belum ada pencatatan
     * pembayaran sama sekali — begitu ada pembayaran (sebagian/lunas)
     * rencananya dikunci supaya konsisten dengan uang yang sudah diterima.
     */
    public function updateJadwalTagihanTanggal(Request $request, JadwalTagihan $jadwal): RedirectResponse
    {
        $kk = $jadwal->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        abort_unless($jadwal->status === 'belum_bayar', 422, 'Cicilan yang sudah ada pembayarannya tidak bisa diubah rencananya.');
        $this->assertTransactionEditable($kk, 'Ubah rencana cicilan');

        $validated = $request->validate([
            'tanggal_jatuh_tempo' => 'nullable|date',
            'jumlah'              => 'nullable|numeric|min:1',
        ]);

        // Nominal Booking Fee/DP wajib ikut skema pembayaran yang dipilih —
        // cuma cicilan Pelunasan (cash/cash bertahap, tidak ada preset yang
        // mengatur pembagian per periode) yang boleh dikoreksi manual.
        abort_if(
            array_key_exists('jumlah', $validated) && $validated['jumlah'] !== null && $jadwal->jenis !== 'pelunasan',
            422,
            'Nominal ' . $jadwal->jenis_label . ' mengikuti skema pembayaran yang dipilih, tidak bisa diubah manual.'
        );

        $jadwal->update(array_filter($validated, fn($v) => $v !== null));

        return back()->with('success', 'Rencana cicilan berhasil diperbarui.');
    }

    /**
     * Tambah baris cicilan Pelunasan custom di luar tenor yang digenerate
     * otomatis saat booking — mengakomodasi pelunasan dipercepat, cicilan
     * tambahan, atau restrukturisasi di lapangan. Cuma berlaku utk
     * Pelunasan (cash/cash bertahap) — Booking Fee/DP baris & tenornya
     * terkunci ke skema preset, tidak bisa ditambah manual.
     */
    public function storePelunasanCicilan(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        abort_unless(in_array($kk->cara_bayar, ['cash', 'cash_bertahap']), 422, 'Cicilan Pelunasan cuma berlaku utk cara bayar Cash/Cash Bertahap.');
        $this->assertTransactionEditable($kk, 'Tambah cicilan Pelunasan');

        $validated = $request->validate([
            'jumlah'              => 'required|numeric|min:1',
            'tanggal_jatuh_tempo' => 'required|date',
        ]);

        $nomorBerikutnya = 1 + (int) $kk->jadwalTagihans()->where('jenis', 'pelunasan')->max('nomor_cicilan');

        $kk->jadwalTagihans()->create([
            'jenis'               => 'pelunasan',
            'nomor_cicilan'       => $nomorBerikutnya,
            'jumlah'              => $validated['jumlah'],
            'tanggal_jatuh_tempo' => $validated['tanggal_jatuh_tempo'],
            'status'              => 'belum_bayar',
        ]);

        return back()->with('success', 'Cicilan Pelunasan berhasil ditambahkan.');
    }

    /**
     * Hapus satu baris cicilan Pelunasan sepenuhnya (bukan cuma
     * pembayarannya) — dipakai kalau admin salah tambah baris custom.
     * Cuma boleh utk baris yang belum ada pembayaran sama sekali, dan
     * cuma utk jenis Pelunasan (Booking Fee/DP tidak boleh dihapus barisnya
     * karena mengikuti skema preset).
     */
    public function destroyJadwalTagihan(JadwalTagihan $jadwal): RedirectResponse
    {
        $kk = $jadwal->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        abort_unless($jadwal->jenis === 'pelunasan', 422, 'Cuma baris cicilan Pelunasan yang bisa dihapus.');
        abort_unless($jadwal->status === 'belum_bayar', 422, 'Cicilan yang sudah ada pembayarannya tidak bisa dihapus.');
        $this->assertTransactionEditable($kk, 'Hapus baris cicilan Pelunasan');

        $jadwal->delete();

        return back()->with('success', 'Baris cicilan Pelunasan berhasil dihapus.');
    }

    /**
     * Catat/edit pembayaran Biaya Penambahan Tanah (field tunggal, bukan
     * relasi tersendiri, jadi statusnya disimpan langsung di
     * kavling_konsumen). Submit berikutnya kalau sudah ada pembayaran =
     * edit baris yang sama, status sebagian/lunas dari perbandingan nominal.
     */
    public function payBiayaTanah(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        abort_unless($kk->biaya_kelebihan_tanah_aktif, 422, 'Transaksi ini tidak punya Biaya Penambahan Tanah.');
        $this->assertTransactionEditable($kk, 'Catat pembayaran biaya tanah');

        $validated = $request->validate([
            'jumlah'        => 'required|numeric|min:1',
            'tanggal_bayar' => 'required|date',
            'keterangan'    => 'nullable|string|max:500',
        ]);

        if ($kk->biayaKelebihanTanahPembayaran) {
            $kk->biayaKelebihanTanahPembayaran->update([
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? $kk->biayaKelebihanTanahPembayaran->keterangan,
            ]);
        } else {
            $pembayaran = $kk->pembayarans()->create([
                'jenis'         => 'biaya_tanah',
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? 'Biaya Penambahan Tanah',
                'created_by'    => Auth::id(),
            ]);
            $kk->biaya_kelebihan_tanah_pembayaran_id = $pembayaran->id;
        }

        $kk->biaya_kelebihan_tanah_status = $this->resolveItemStatus((float) $validated['jumlah'], (float) $kk->biaya_kelebihan_tanah_nominal);
        $kk->save();

        return back()->with('success', 'Pembayaran Biaya Penambahan Tanah berhasil dicatat.');
    }

    /**
     * Batalkan pencatatan pembayaran Biaya Penambahan Tanah.
     */
    public function destroyBiayaTanahPembayaran(KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Hapus pembayaran biaya tanah');

        $pembayaran = $kk->biayaKelebihanTanahPembayaran;
        $kk->update(['biaya_kelebihan_tanah_status' => 'belum_bayar', 'biaya_kelebihan_tanah_pembayaran_id' => null]);
        $pembayaran?->delete();

        return back()->with('success', 'Pencatatan pembayaran biaya tanah dibatalkan.');
    }

    /**
     * Catat/edit pembayaran Tambahan Uang Muka (muncul kalau bank turun
     * plafon) — nominalnya dihitung ulang dari kartuPiutangBreakdown()
     * (bukan disimpan sebagai kolom terpisah), field tunggal di
     * kavling_konsumen sama seperti Biaya Penambahan Tanah.
     */
    public function payTambahanUm(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Catat pembayaran tambahan uang muka');

        $validated = $request->validate([
            'jumlah'        => 'required|numeric|min:1',
            'tanggal_bayar' => 'required|date',
            'keterangan'    => 'nullable|string|max:500',
        ]);

        $kk->load(['jadwalTagihans', 'skemaDpPreset', 'rincianBiayaAkad']);
        $tambahanUm = (float) ($kk->kartuPiutangBreakdown()['pencairan_kpr']['tambahan_um'] ?? 0);
        abort_if($tambahanUm <= 0, 422, 'Transaksi ini tidak punya Tambahan Uang Muka yang perlu dibayar.');

        if ($kk->tambahanUmPembayaran) {
            $kk->tambahanUmPembayaran->update([
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? $kk->tambahanUmPembayaran->keterangan,
            ]);
        } else {
            $pembayaran = $kk->pembayarans()->create([
                'jenis'         => 'tambahan_um',
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? 'Tambahan Uang Muka',
                'created_by'    => Auth::id(),
            ]);
            $kk->tambahan_um_pembayaran_id = $pembayaran->id;
        }

        $kk->tambahan_um_status = $this->resolveItemStatus((float) $validated['jumlah'], $tambahanUm);
        $kk->save();

        return back()->with('success', 'Pembayaran Tambahan Uang Muka berhasil dicatat.');
    }

    /**
     * Batalkan pencatatan pembayaran Tambahan Uang Muka.
     */
    public function destroyTambahanUmPembayaran(KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Hapus pembayaran tambahan uang muka');

        $pembayaran = $kk->tambahanUmPembayaran;
        $kk->update(['tambahan_um_status' => 'belum_bayar', 'tambahan_um_pembayaran_id' => null]);
        $pembayaran?->delete();

        return back()->with('success', 'Pencatatan pembayaran tambahan uang muka dibatalkan.');
    }

    /**
     * Catat/edit pembayaran satu item Biaya Tambahan Lain.
     */
    public function payBiayaTambahan(Request $request, KavlingKonsumenBiayaTambahan $item): RedirectResponse
    {
        $kk = $item->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Catat pembayaran biaya tambahan');

        $validated = $request->validate([
            'jumlah'        => 'required|numeric|min:1',
            'tanggal_bayar' => 'required|date',
            'keterangan'    => 'nullable|string|max:500',
        ]);

        if ($item->pembayaran) {
            $item->pembayaran->update([
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? $item->pembayaran->keterangan,
            ]);
        } else {
            $pembayaran = $kk->pembayarans()->create([
                'jenis'         => 'biaya_tambahan',
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? $item->nama,
                'created_by'    => Auth::id(),
            ]);
            $item->pembayaran_konsumen_id = $pembayaran->id;
        }

        $item->status = $this->resolveItemStatus((float) $validated['jumlah'], (float) $item->nominal);
        $item->save();

        return back()->with('success', 'Pembayaran biaya tambahan berhasil dicatat.');
    }

    /**
     * Batalkan pencatatan pembayaran satu item Biaya Tambahan.
     */
    public function destroyBiayaTambahanPembayaran(KavlingKonsumenBiayaTambahan $item): RedirectResponse
    {
        $kk = $item->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage pembayaran'), 403);
        $this->assertTransactionEditable($kk, 'Hapus pembayaran biaya tambahan');

        $pembayaran = $item->pembayaran;
        $item->update(['status' => 'belum_bayar', 'pembayaran_konsumen_id' => null]);
        $pembayaran?->delete();

        return back()->with('success', 'Pencatatan pembayaran biaya tambahan dibatalkan.');
    }

    /**
     * Catat/edit pencairan/pembayaran satu item Rincian Biaya Akad — SBUM &
     * Dana Jaminan (subjek Pemerintah/Bank, dipakai di section Pencairan
     * KPR) atau Biaya Akad titipan (subjek Konsumen, dipakai di Kartu
     * Piutang Titipan). Mekanisme pencatatannya sama, dibedakan lewat
     * permission 'manage kpr' — sama seperti CRUD rincian biaya akad.
     */
    public function payDajamSbum(Request $request, KavlingKonsumenDajamSbum $item): RedirectResponse
    {
        $kk = $item->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);
        $this->assertTransactionEditable($kk, 'Catat pembayaran ' . $item->kategori);

        $validated = $request->validate([
            'jumlah'        => 'required|numeric|min:1',
            'tanggal_bayar' => 'required|date',
            'keterangan'    => 'nullable|string|max:500',
        ]);

        if ($item->pembayaran) {
            $item->pembayaran->update([
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? $item->pembayaran->keterangan,
            ]);
        } else {
            $pembayaran = $kk->pembayarans()->create([
                'jenis'         => $item->kategori,
                'jumlah'        => $validated['jumlah'],
                'tanggal_bayar' => $validated['tanggal_bayar'],
                'keterangan'    => $validated['keterangan'] ?? $item->nama,
                'created_by'    => Auth::id(),
            ]);
            $item->pembayaran_konsumen_id = $pembayaran->id;
        }

        $item->status = $this->resolveItemStatus((float) $validated['jumlah'], (float) $item->nominal);
        $item->save();

        return back()->with('success', 'Pembayaran berhasil dicatat.');
    }

    /**
     * Batalkan pencatatan pencairan/pembayaran satu item Rincian Biaya Akad.
     */
    public function destroyDajamSbumPembayaran(KavlingKonsumenDajamSbum $item): RedirectResponse
    {
        $kk = $item->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);
        $this->assertTransactionEditable($kk, 'Hapus pembayaran ' . $item->kategori);

        $pembayaran = $item->pembayaran;
        $item->update(['status' => 'belum_bayar', 'pembayaran_konsumen_id' => null]);
        $pembayaran?->delete();

        return back()->with('success', 'Pencatatan pembayaran dibatalkan.');
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
     * Catat satu tahap Pencairan KPR (uang cair dari bank ke developer).
     * Beda dari Booking Fee/DP/Pelunasan, bank tidak ikut skema/tenor apa
     * pun — jadi setiap tahap ditambahkan manual oleh admin (bisa
     * berkali-kali kalau bank cair bertahap), bukan digenerate otomatis.
     */
    public function storePencairanKprTahap(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);
        abort_unless(in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil']), 422, 'Pencairan KPR cuma berlaku utk cara bayar KPR.');
        $this->assertTransactionEditable($kk, 'Catat tahap Pencairan KPR');

        $validated = $request->validate([
            'nominal'      => 'required|numeric|min:1',
            'tanggal_cair' => 'required|date',
            'keterangan'   => 'nullable|string|max:500',
        ]);

        $kk->pencairanKprTahaps()->create([
            ...$validated,
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'Tahap Pencairan KPR berhasil dicatat.');
    }

    /**
     * Edit satu tahap Pencairan KPR yang sudah dicatat (koreksi human error).
     */
    public function updatePencairanKprTahap(Request $request, PencairanKprTahap $tahap): RedirectResponse
    {
        $kk = $tahap->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);
        $this->assertTransactionEditable($kk, 'Ubah tahap Pencairan KPR');

        $validated = $request->validate([
            'nominal'      => 'required|numeric|min:1',
            'tanggal_cair' => 'required|date',
            'keterangan'   => 'nullable|string|max:500',
        ]);

        $tahap->update($validated);

        return back()->with('success', 'Tahap Pencairan KPR berhasil diperbarui.');
    }

    /**
     * Hapus satu tahap Pencairan KPR (salah catat).
     */
    public function destroyPencairanKprTahap(PencairanKprTahap $tahap): RedirectResponse
    {
        $kk = $tahap->kavlingKonsumen;
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('manage kpr'), 403);
        $this->assertTransactionEditable($kk, 'Hapus tahap Pencairan KPR');

        $tahap->delete();

        return back()->with('success', 'Tahap Pencairan KPR berhasil dihapus.');
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
                    ? route('media.show', ['path' => $developer->logo_path]) : null,
                'kop_surat'   => $developer->kop_surat_path
                    ? route('media.show', ['path' => $developer->kop_surat_path]) : null,
            ],
        ]);
    }
}
