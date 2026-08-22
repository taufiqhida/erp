<?php

namespace App\Http\Controllers;

use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Http\Controllers\Concerns\ChecksTransactionLock;
use App\Models\BiayaTambahanPreset;
use App\Models\DokumenKonsumen;
use App\Models\DokumenTemplate;
use App\Models\Kavling;
use App\Models\KavlingKonsumenBiayaTambahan;
use App\Models\Konsumen;
use App\Models\KavlingKonsumen;
use App\Models\Project;
use App\Models\PromoPreset;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    use AuthorizesProjectAccess, ChecksTransactionLock;

    /**
     * Penjualan tidak lagi punya halaman pilih-proyek sendiri — sudah
     * dilebur ke Halaman Utama (beranda). Rute ini dipertahankan sebagai
     * alias supaya bookmark/link lama tidak 404.
     */
    public function projectList(): RedirectResponse
    {
        return redirect()->route('beranda');
    }

    /**
     * List ringkas kavling berstatus Available dalam satu proyek — dipakai
     * untuk pencarian unit tujuan saat mengajukan Tukar Unit dari halaman
     * Konsumen/Kelola Dokumen (bukan dari Siteplan, jadi tidak ada daftar
     * kavling ter-load di client).
     */
    public function availableKavlings(Project $project): \Illuminate\Http\JsonResponse
    {
        $this->authorizeProjectAccess($project);

        $kavlings = $project->kavlings()
            ->where('status_jual', StatusJual::Available)
            ->orderBy('blok')
            ->orderBy('nomor_kavling')
            ->get(['id', 'nomor_lengkap', 'kluster', 'blok', 'tipe_unit']);

        return response()->json($kavlings);
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
                'kluster'             => $k->kluster,
                'nomor_kavling'       => $k->nomor_kavling,
                'blok'                => $k->blok,
                'tipe_unit'           => $k->tipe_unit,
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
                'progress_bangun'     => $k->progress_bangun,
                'status_unit'         => $k->status_unit,
                'keterangan'          => $k->keterangan,
                'catatan'             => $k->catatan,
                'koordinat_x'         => $k->koordinat_x,
                'koordinat_y'         => $k->koordinat_y,
                'foto_rumah'          => $k->foto_rumah ? route('media.show', ['path' => $k->foto_rumah]) : null,
                'denah_rumah'         => $k->denah_rumah ? route('media.show', ['path' => $k->denah_rumah]) : null,
                'kamar_tidur'         => $k->kamar_tidur,
                'kamar_mandi'         => $k->kamar_mandi,
                'spek_atap'           => $k->spek_atap,
                'spek_dinding'        => $k->spek_dinding,
                'spek_lantai'         => $k->spek_lantai,
                'spek_pondasi'        => $k->spek_pondasi,
                'konsumen_nama'       => $k->activeTransaction?->konsumen?->nama,
                'konsumen_id'         => $k->activeTransaction?->konsumen_id,
                'transaksi_id'        => $k->activeTransaction?->id,
                'status_penjualan'    => $k->activeTransaction?->status_penjualan,
            ]);

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
                    ? route('media.show', ['path' => $project->siteplan_image]) : null,
                'siteplan_marker_size' => $project->siteplan_marker_size,
            ],
            'kavlings' => $kavlings,
            'konsumens' => Konsumen::orderBy('nama')->get(['id', 'nama', 'no_hp', 'nik']),
            'salesAgents' => SalesAgent::where('is_active', true)
                ->orderBy('nama')
                ->get()
                ->map(fn($a) => [
                    'id'           => $a->id,
                    'nama'         => $a->nama,
                    'tipe_label'   => $a->tipe_label,
                    'agency_nama'  => $a->agency_nama,
                    'komisi_tipe'  => $a->komisi_tipe,
                    'komisi_nilai' => $a->komisi_nilai,
                    'komisi_label' => $a->komisi_label,
                ]),
            'biayaTambahanPresets' => BiayaTambahanPreset::where('is_active', true)->orderBy('nama')->get(['id', 'nama']),
            'promoPresets' => PromoPreset::where('is_active', true)->orderBy('nama')->get(['id', 'nama']),
            'skemaDpPresets' => SkemaDpPreset::where('is_active', true)->orderBy('nama')->get([
                'id', 'nama', 'cara_bayar',
                'booking_fee_aktif', 'booking_fee_tipe', 'booking_fee_nilai', 'booking_fee_basis',
                'dp_aktif', 'dp_tipe', 'dp_nilai', 'dp_basis',
            ]),
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
            // Section 1 — Tanggal, Konsumen, Sales/Agent
            'tanggal_booking'     => 'required|date',
            'konsumen_id'         => 'nullable|exists:konsumens,id',
            'konsumen_nama'       => 'nullable|required_without:konsumen_id|string|max:100',
            'konsumen_no_hp'      => 'nullable|string|max:20',
            'konsumen_nik'        => 'nullable|string|max:20',
            'konsumen_email'      => 'nullable|email|max:100',
            'sales_agent_id'      => 'nullable|exists:sales_agents,id',
            // Booking detail — booking_fee & skema_dp dihitung ulang di server
            // dari preset skema_dp_preset_id (lihat bawah), nilai dari client
            // di sini murni informasional dan tidak dipakai untuk menyimpan.
            'booking_fee'         => 'nullable|numeric|min:0',
            'cara_bayar'          => 'required|in:cash,cash_bertahap,kpr_subsidi,kpr_komersil',
            'cicilan_kali'        => 'nullable|integer|min:1|max:360',
            'skema_dp'            => 'nullable|string|max:100',
            'skema_dp_preset_id'  => 'nullable|exists:skema_dp_presets,id',
            'plafon_kpr'          => 'nullable|numeric|min:0',
            'catatan'             => 'nullable|string',
            // Section 2 — Biaya Kelebihan Tanah. luas/harga_per_m2/nominal_input
            // hanya wajib kalau fiturnya betul-betul aktif DAN mode terkait yang
            // dipilih — bukan cuma berdasar mode saja, karena mode selalu terkirim
            // dengan nilai default 'per_m2' dari form walau togglenya mati, dan
            // field "luas" tidak ditampilkan/diisi sama sekali di mode nominal.
            'biaya_kelebihan_tanah_aktif'        => 'boolean',
            'biaya_kelebihan_tanah_luas'         => [
                'nullable', 'numeric', 'min:0',
                Rule::requiredIf(fn () => $request->boolean('biaya_kelebihan_tanah_aktif') && $request->input('biaya_kelebihan_tanah_mode') === 'per_m2'),
            ],
            'biaya_kelebihan_tanah_mode'         => 'required_if:biaya_kelebihan_tanah_aktif,true|nullable|in:per_m2,nominal',
            'biaya_kelebihan_tanah_harga_per_m2' => [
                'nullable', 'numeric', 'min:0',
                Rule::requiredIf(fn () => $request->boolean('biaya_kelebihan_tanah_aktif') && $request->input('biaya_kelebihan_tanah_mode') === 'per_m2'),
            ],
            'biaya_kelebihan_tanah_nominal_input' => [
                'nullable', 'numeric', 'min:0',
                Rule::requiredIf(fn () => $request->boolean('biaya_kelebihan_tanah_aktif') && $request->input('biaya_kelebihan_tanah_mode') === 'nominal'),
            ],
            // Section 2 — Biaya Tambahan Lain (multi-select preset)
            'biaya_tambahan'                     => 'nullable|array',
            'biaya_tambahan.*.preset_id'         => 'required|exists:biaya_tambahan_presets,id',
            'biaya_tambahan.*.nominal'           => 'required|numeric|min:0',
            // Section 2 — Diskon / Promo
            'promo_preset_id'     => 'nullable|exists:promo_presets,id',
            'diskon_mode'         => 'nullable|in:persen,nominal',
            'diskon_nilai'        => 'required_with:diskon_mode|nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($kavling, $validated) {
            // Lock baris kavling supaya dua request booking bersamaan tidak
            // lolos cek status yang sama sebelum salah satunya commit.
            $locked = Kavling::whereKey($kavling->id)->lockForUpdate()->firstOrFail();

            abort_if(
                $locked->status_jual !== StatusJual::Available,
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
                    'nik'   => $validated['konsumen_nik'] ?? null,
                    'email' => $validated['konsumen_email'] ?? null,
                ]);
            }

            // Snapshot skema komisi sales/agent (terkunci pada saat booking)
            $salesAgent = !empty($validated['sales_agent_id'])
                ? SalesAgent::find($validated['sales_agent_id'])
                : null;

            // ── Hitung Biaya Kelebihan Tanah (server-side, jangan percaya client) ──
            $biayaKelebihanNominal = 0;
            if ($validated['biaya_kelebihan_tanah_aktif'] ?? false) {
                $biayaKelebihanNominal = $validated['biaya_kelebihan_tanah_mode'] === 'per_m2'
                    ? round($validated['biaya_kelebihan_tanah_luas'] * $validated['biaya_kelebihan_tanah_harga_per_m2'], 2)
                    : (float) $validated['biaya_kelebihan_tanah_nominal_input'];
            }

            // ── Biaya Tambahan Lain (multi-preset) ──
            $biayaTambahanItems = collect($validated['biaya_tambahan'] ?? [])
                ->map(function ($item) {
                    $preset = BiayaTambahanPreset::find($item['preset_id']);
                    return [
                        'biaya_tambahan_preset_id' => $item['preset_id'],
                        'nama'                     => $preset?->nama ?? 'Biaya Tambahan',
                        'nominal'                  => (float) $item['nominal'],
                    ];
                });
            $biayaTambahanTotal = $biayaTambahanItems->sum('nominal');

            $totalBiayaTambahan = $biayaKelebihanNominal + $biayaTambahanTotal;

            // ── Diskon / Promo ──
            $hargaDasar = (float) $kavling->harga;
            $subtotal   = $hargaDasar + $totalBiayaTambahan;

            $diskonNominal = 0;
            if (!empty($validated['diskon_mode'])) {
                $diskonNominal = $validated['diskon_mode'] === 'persen'
                    ? round($subtotal * ((float) $validated['diskon_nilai'] / 100), 2)
                    : (float) $validated['diskon_nilai'];
            }

            $hargaJualNetto = $subtotal - $diskonNominal;

            // ── Skema Pembayaran: booking fee & DP dihitung ulang di server
            // dari preset (bukan percaya nilai dari client), supaya preset
            // benar-benar terkunci sebagai kebijakan, bukan sekadar saran. ──
            $skemaPreset = !empty($validated['skema_dp_preset_id'])
                ? SkemaDpPreset::find($validated['skema_dp_preset_id'])
                : null;

            $resolveBasis = fn(string $basis) => $basis === 'harga_dasar' ? $hargaDasar : $hargaJualNetto;
            $resolveNominal = function ($tipe, $nilai, $basis) use ($resolveBasis) {
                if (!$nilai) return 0;
                return $tipe === 'persen'
                    ? round(((float) $nilai / 100) * $resolveBasis($basis), 2)
                    : (float) $nilai;
            };

            $bookingFee = ($skemaPreset && $skemaPreset->booking_fee_aktif)
                ? $resolveNominal($skemaPreset->booking_fee_tipe, $skemaPreset->booking_fee_nilai, $skemaPreset->booking_fee_basis)
                : 0;

            $dpNominal = ($skemaPreset && $skemaPreset->dp_aktif)
                ? $resolveNominal($skemaPreset->dp_tipe, $skemaPreset->dp_nilai, $skemaPreset->dp_basis)
                : 0;
            $skemaDpString = $dpNominal > 0 ? "nominal:{$dpNominal}" : 'tanpa_dp';

            // Buat transaksi
            $transaksi = KavlingKonsumen::create([
                'kavling_id'       => $kavling->id,
                'konsumen_id'      => $konsumen->id,
                'status'           => 'active',
                'tanggal_booking'  => $validated['tanggal_booking'],
                'harga_dasar'      => $hargaDasar,
                'harga_deal'       => $hargaJualNetto,
                'booking_fee'      => $bookingFee,
                'cara_bayar'       => $validated['cara_bayar'],
                'cicilan_kali'     => $validated['cicilan_kali'] ?? null,
                'skema_dp'         => $skemaDpString,
                'skema_dp_preset_id' => $skemaPreset?->id,
                'plafon_kpr'       => $validated['plafon_kpr'] ?? null,
                'sales_agent_id'   => $salesAgent?->id,
                'komisi_tipe'      => $salesAgent?->komisi_tipe,
                'komisi_nilai'     => $salesAgent?->komisi_nilai,
                'biaya_kelebihan_tanah_aktif'        => $validated['biaya_kelebihan_tanah_aktif'] ?? false,
                'biaya_kelebihan_tanah_luas'         => $validated['biaya_kelebihan_tanah_luas'] ?? null,
                'biaya_kelebihan_tanah_mode'         => $validated['biaya_kelebihan_tanah_mode'] ?? null,
                'biaya_kelebihan_tanah_harga_per_m2' => $validated['biaya_kelebihan_tanah_harga_per_m2'] ?? null,
                'biaya_kelebihan_tanah_nominal'      => $biayaKelebihanNominal,
                'promo_preset_id'  => $validated['promo_preset_id'] ?? null,
                'diskon_mode'      => $validated['diskon_mode'] ?? null,
                'diskon_nilai'     => $validated['diskon_nilai'] ?? null,
                'diskon_nominal'   => $diskonNominal,
                'total_biaya_tambahan' => $totalBiayaTambahan,
                // Booking otomatis tercomplete & lanjut ke Pemberkasan begitu
                // sales input booking — tidak ada tahap "Booking" manual yang
                // perlu dikonfirmasi terpisah lagi (tetap bisa direview lewat
                // revertToBooking() kalau perlu revisi dari awal).
                'status_penjualan' => 'pemberkasan',
                'catatan'          => $validated['catatan'] ?? null,
                'created_by'       => Auth::id(),
            ]);

            foreach ($biayaTambahanItems as $item) {
                $transaksi->biayaTambahans()->create($item);
            }

            // ── Kartu Piutang: generate jadwal tagihan booking fee & DP dari
            // tenor preset skema pembayaran. Ini rencana tagihan (belum
            // dibayar) — pencatatan pembayaran aktual tetap lewat alur
            // Keuangan terpisah (finance yang konfirmasi uang diterima).
            $tanggalBooking = \Carbon\Carbon::parse($validated['tanggal_booking']);
            $generateJadwalTagihan = function (string $jenis, float $totalNominal, int $tenor) use ($transaksi, $tanggalBooking) {
                if ($totalNominal <= 0 || $tenor < 1) return;
                $perCicilan = round($totalNominal / $tenor, 2);
                $sisa = $totalNominal;
                for ($i = 0; $i < $tenor; $i++) {
                    $isLast = $i === $tenor - 1;
                    $jumlah = $isLast ? $sisa : $perCicilan;
                    $sisa -= $jumlah;
                    $transaksi->jadwalTagihans()->create([
                        'jenis'               => $jenis,
                        'nomor_cicilan'       => $i + 1,
                        'jumlah'              => $jumlah,
                        'tanggal_jatuh_tempo' => $tanggalBooking->copy()->addMonths($i),
                        'status'              => 'belum_bayar',
                    ]);
                }
            };

            if ($skemaPreset?->booking_fee_aktif) {
                $generateJadwalTagihan('booking_fee', $bookingFee, max(1, $skemaPreset->booking_fee_tenor));

                // Booking fee secara bisnis selalu dibayar di tempat saat
                // booking dibuat — langsung catat pembayarannya & tandai
                // lunas semua cicilan booking_fee yang baru dibuat (pola
                // sama dengan matching FIFO di KeuanganController::storePembayaran()).
                if ($bookingFee > 0) {
                    $pembayaranBookingFee = $transaksi->pembayarans()->create([
                        'jenis'         => 'booking_fee',
                        'jumlah'        => $bookingFee,
                        'tanggal_bayar' => $tanggalBooking,
                        'keterangan'    => 'Booking fee otomatis dibayar saat booking',
                        'created_by'    => Auth::id(),
                    ]);

                    $transaksi->jadwalTagihans()
                        ->where('jenis', 'booking_fee')
                        ->update([
                            'status' => 'lunas',
                            'pembayaran_konsumen_id' => $pembayaranBookingFee->id,
                        ]);
                }
            }
            if ($skemaPreset?->dp_aktif) {
                $generateJadwalTagihan('dp', $dpNominal, max(1, $skemaPreset->dp_tenor));
            }

            // Cash & Cash Bertahap: Pelunasan juga dapat jadwal cicilan
            // sendiri (bukan cuma booking_fee/dp) — basisnya harga_deal
            // (sudah termasuk biaya tanah & biaya tambahan) dikurangi
            // Booking Fee/DP yang masuk harga jual, persis pola Plafon KPR
            // tapi pakai harga_deal. Tenor cash bertahap ikut cicilan_kali,
            // cash biasa selalu 1x (lunas sekaligus).
            if (in_array($validated['cara_bayar'], ['cash', 'cash_bertahap'])) {
                $bookingFeePengurang = ($skemaPreset?->booking_fee_masuk_harga_jual ?? false) ? $bookingFee : 0;
                $dpPengurang = ($skemaPreset?->dp_masuk_harga_jual ?? false) ? $dpNominal : 0;
                $pelunasanNominal = max(0, $hargaJualNetto - $bookingFeePengurang - $dpPengurang);
                $pelunasanTenor = $validated['cara_bayar'] === 'cash_bertahap'
                    ? max(1, (int) ($validated['cicilan_kali'] ?? 1))
                    : 1;
                $generateJadwalTagihan('pelunasan', $pelunasanNominal, $pelunasanTenor);
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
     * khusus): booking → pemberkasan → proses_bank (KPR) / rencana_akad
     * (cash & cash bertahap — skip Proses Bank & SP3K), dan rencana_akad →
     * akad. Transisi ke 'sp3k' dan 'rencana_akad' untuk cara bayar KPR TIDAK
     * lewat sini — itu hanya bisa terjadi otomatis lewat endpoint Keputusan
     * Bank / Keputusan SP3K masing-masing, supaya gate dokumen & checklist
     * selalu ditegakkan. Stage "bast" juga tidak diset di sini — itu lewat
     * fitur BAST tersendiri.
     */
    public function updateStatus(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);

        $validated = $request->validate([
            'status_penjualan'     => 'required|in:booking,pemberkasan,proses_bank,rencana_akad,akad,batal',
            'catatan'              => 'nullable|string',
        ]);

        $isBankFlow = in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil']);

        if ($validated['status_penjualan'] === 'proses_bank') {
            abort_unless($isBankFlow, 422, 'Tahap Proses Bank/SLIK hanya berlaku untuk cara bayar KPR.');
            // Dokumen wajib boleh belum lengkap — sales tetap bisa lanjut,
            // tapi wajib isi catatan alasannya (soft gate, bukan blokir keras).
            abort_if(
                !$kk->dokumen_wajib_lengkap && blank($validated['catatan'] ?? null),
                422,
                'Dokumen wajib belum lengkap — isi catatan alasan untuk tetap lanjut.'
            );
            abort_unless(
                filled($kk->bank_rekanan_kpr),
                422,
                'Bank Rekanan KPR harus diisi terlebih dahulu sebelum lanjut ke Proses Bank.'
            );
            $validated['tanggal_pengajuan_bank'] = now();
            $validated['status_bank'] = 'diajukan';
        }

        // Cash & Cash Bertahap tidak melalui Proses Bank/SLIK & SP3K — begitu
        // Pemberkasan selesai (dokumen boleh belum lengkap dengan catatan) &
        // seluruh Kartu Piutang lunas, langsung ke Rencana Akad.
        if ($validated['status_penjualan'] === 'rencana_akad') {
            abort_if($isBankFlow, 422, 'Cara bayar KPR harus melalui Proses Bank & SP3K terlebih dahulu.');
            abort_unless($kk->status_penjualan === 'pemberkasan', 422, 'Transaksi tidak sedang di tahap Pemberkasan.');
            abort_if(
                !$kk->dokumen_wajib_lengkap && blank($validated['catatan'] ?? null),
                422,
                'Dokumen wajib belum lengkap — isi catatan alasan untuk tetap lanjut.'
            );
            abort_unless(
                $kk->piutang_lunas,
                422,
                'Seluruh Kartu Piutang harus lunas terlebih dahulu sebelum lanjut ke Rencana Akad.'
            );
        }

        if ($validated['status_penjualan'] === 'akad') {
            abort_unless(
                $kk->tanggal_rencana_akad,
                422,
                'Isi Tanggal Rencana Akad terlebih dahulu sebelum konfirmasi Akad.'
            );
        }

        $kk->update($validated);

        // Jika akad, ini hanya konfirmasi bahwa akad terlaksana sesuai Tanggal
        // Rencana Akad yang sudah diisi sebelumnya — bukan tanggal hari ini.
        // Status transaksi TIDAK otomatis jadi 'completed' di sini — pencairan
        // bank/dajam bisa masih proses setelah akad, jadi transaksi harus tetap
        // aktif & bisa dicatat pembayarannya sampai admin menekan "Tandai
        // Selesai" di Keuangan (lihat KeuanganController::markComplete()).
        if ($validated['status_penjualan'] === 'akad') {
            $kk->kavling->update(['status_jual' => StatusJual::Sold]);
            $kk->update(['tanggal_akad' => $kk->tanggal_rencana_akad]);
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
     * Simpan keputusan SP3K. SP3K sendiri adalah OUTPUT dari Proses Bank yang
     * sudah disetujui, jadi tidak ada opsi "ditolak" di sini — begitu
     * disimpan (approved/turun_plafon) langsung lanjut ke Rencana Akad.
     * Syarat "buka rekening KPR"/"biaya akad lunas" dihapus dari sini
     * (case-by-case, tidak disistemkan).
     */
    public function updateSp3kDecision(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);
        abort_unless($kk->status_penjualan === 'sp3k', 422, 'Transaksi tidak sedang di tahap SP3K.');

        $validated = $request->validate([
            'tanggal_sp3k'           => 'nullable|date',
            'tanggal_expired_sp3k'   => 'nullable|date',
            'status_sp3k'            => 'required|in:approved,turun_plafon',
            'tanggal_disetujui_sp3k' => 'nullable|date',
            'catatan_sp3k'           => 'nullable|string',
            'plafon_baru'            => 'required_if:status_sp3k,turun_plafon|nullable|numeric|min:0',
        ]);

        $update = [
            'tanggal_sp3k'           => $validated['tanggal_sp3k'] ?? $kk->tanggal_sp3k,
            'tanggal_expired_sp3k'   => $validated['tanggal_expired_sp3k'] ?? $kk->tanggal_expired_sp3k,
            'status_sp3k'            => $validated['status_sp3k'],
            'tanggal_disetujui_sp3k' => $validated['tanggal_disetujui_sp3k'] ?? null,
            'catatan_sp3k'           => $validated['catatan_sp3k'] ?? null,
            'status_penjualan'       => 'rencana_akad',
        ];

        if ($validated['status_sp3k'] === 'turun_plafon') {
            $update['plafon_kpr'] = $validated['plafon_baru'];
        }

        $kk->update($update);

        return back()->with('success', 'Keputusan SP3K disimpan, transaksi lanjut ke Rencana Akad.');
    }

    /**
     * Urutan tahap pipeline, disesuaikan cara_bayar (cash/cash_bertahap
     * melewati Proses Bank & SP3K).
     */
    private function pipelineOrder(bool $isBankFlow): array
    {
        return $isBankFlow
            ? ['booking', 'pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad', 'akad', 'bast']
            : ['booking', 'pemberkasan', 'rencana_akad', 'akad', 'bast'];
    }

    /**
     * Kembalikan transaksi satu tahap ke belakang — dipakai untuk kebutuhan
     * review atau melengkapi dokumen yang tadinya dilewati (soft gate).
     * Bisa dipanggil berulang untuk mundur beberapa tahap sekaligus.
     */
    public function revertToPreviousStage(KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);
        abort_if($kk->status === 'cancelled', 422, 'Transaksi ini sudah batal.');

        $isBankFlow = in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil']);
        $order = $this->pipelineOrder($isBankFlow);
        $idx = array_search($kk->status_penjualan, $order, true);
        abort_if($idx === false || $idx === 0, 422, 'Transaksi tidak bisa dikembalikan dari tahap ini.');

        $current = $kk->status_penjualan;
        $update = ['status_penjualan' => $order[$idx - 1]];

        if ($current === 'proses_bank') {
            $update['status_bank'] = null;
        }
        if ($current === 'sp3k') {
            $update['status_sp3k'] = null;
        }
        if (in_array($current, ['akad', 'bast'], true)) {
            $update['tanggal_akad'] = null;
            if ($kk->kavling->status_jual === StatusJual::Sold) {
                $kk->kavling->update(['status_jual' => StatusJual::Booked]);
            }
        }

        $kk->update($update);

        return back()->with('success', 'Transaksi dikembalikan ke tahap sebelumnya.');
    }

    /**
     * Simpan Tanggal Rencana Akad. Tanggal ini yang nanti dipakai sebagai
     * Tanggal Akad saat staff konfirmasi "Lanjutkan ke Akad" — tahap Akad
     * hanya konfirmasi bahwa akad benar terlaksana di tanggal yang direncanakan.
     */
    public function updateRencanaAkad(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);
        abort_unless($kk->status_penjualan === 'rencana_akad', 422, 'Transaksi tidak sedang di tahap Rencana Akad.');

        $validated = $request->validate([
            'tanggal_rencana_akad' => 'required|date',
        ]);

        $kk->update($validated);

        return back()->with('success', 'Tanggal Rencana Akad berhasil disimpan.');
    }

    /**
     * Simpan Bank Rekanan KPR — diisi sales di tahap Pemberkasan begitu tahu
     * bank tujuan konsumen, jadi syarat wajib sebelum lanjut ke Proses Bank
     * (lihat updateStatus). Tidak relevan untuk cara bayar cash/cash bertahap.
     */
    public function updateBankRekanan(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);
        abort_unless(in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil']), 422, 'Bank Rekanan KPR hanya berlaku untuk cara bayar KPR.');
        $this->assertTransactionEditable($kk, 'Update Bank Rekanan KPR');

        $validated = $request->validate([
            'bank_rekanan_kpr' => 'nullable|string|max:100',
        ]);

        $kk->update($validated);

        return back()->with('success', 'Bank Rekanan KPR berhasil disimpan.');
    }
}
