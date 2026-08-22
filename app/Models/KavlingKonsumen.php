<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class KavlingKonsumen extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'kavling_konsumen';

    protected $fillable = [
        'kavling_id',
        'konsumen_id',
        'status',
        'tanggal_booking',
        'tanggal_akad',
        'harga_deal',
        'harga_dasar',
        'metode_bayar',
        'booking_fee',
        'cara_bayar',
        'bank_rekanan_kpr',
        'cicilan_kali',
        'skema_dp',
        'skema_dp_preset_id',
        'plafon_kpr',
        'sales_agent_id',
        'komisi_tipe',
        'komisi_nilai',
        'biaya_kelebihan_tanah_aktif',
        'biaya_kelebihan_tanah_luas',
        'biaya_kelebihan_tanah_mode',
        'biaya_kelebihan_tanah_harga_per_m2',
        'biaya_kelebihan_tanah_nominal',
        'biaya_kelebihan_tanah_status',
        'biaya_kelebihan_tanah_pembayaran_id',
        'tambahan_um_status',
        'tambahan_um_pembayaran_id',
        'promo_preset_id',
        'diskon_mode',
        'diskon_nilai',
        'diskon_nominal',
        'total_biaya_tambahan',
        'status_penjualan',
        'tanggal_rencana_akad',
        'tanggal_pengajuan_bank',
        'tanggal_keputusan_bank',
        'status_bank',
        'catatan_bank',
        'tanggal_sp3k',
        'tanggal_expired_sp3k',
        'tanggal_disetujui_sp3k',
        'status_sp3k',
        'catatan_sp3k',
        'tanggal_bast',
        'realisasi_cair',
        'dajam_ditahan',
        'catatan',
        'created_by',
    ];

    protected $casts = [
        'tanggal_booking'                     => 'date',
        'tanggal_akad'                         => 'date',
        'tanggal_rencana_akad'                 => 'date',
        'tanggal_pengajuan_bank'               => 'date',
        'tanggal_keputusan_bank'               => 'date',
        'tanggal_sp3k'                         => 'date',
        'tanggal_expired_sp3k'                 => 'date',
        'tanggal_disetujui_sp3k'               => 'date',
        'tanggal_bast'                         => 'date',
        'harga_deal'                           => 'decimal:2',
        'harga_dasar'                          => 'decimal:2',
        'booking_fee'                          => 'decimal:2',
        'plafon_kpr'                           => 'decimal:2',
        'realisasi_cair'                       => 'decimal:2',
        'dajam_ditahan'                        => 'decimal:2',
        'komisi_nilai'                         => 'decimal:2',
        'biaya_kelebihan_tanah_aktif'          => 'boolean',
        'biaya_kelebihan_tanah_luas'           => 'decimal:2',
        'biaya_kelebihan_tanah_harga_per_m2'   => 'decimal:2',
        'biaya_kelebihan_tanah_nominal'        => 'decimal:2',
        'diskon_nilai'                         => 'decimal:2',
        'diskon_nominal'                       => 'decimal:2',
        'total_biaya_tambahan'                 => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $event) => "Transaksi kavling telah di-{$event}");
    }

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function kavling(): BelongsTo
    {
        return $this->belongsTo(Kavling::class);
    }

    public function konsumen(): BelongsTo
    {
        return $this->belongsTo(Konsumen::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(SalesAgent::class);
    }

    public function skemaDpPreset(): BelongsTo
    {
        return $this->belongsTo(SkemaDpPreset::class);
    }

    public function promoPreset(): BelongsTo
    {
        return $this->belongsTo(PromoPreset::class);
    }

    public function biayaTambahans(): HasMany
    {
        return $this->hasMany(KavlingKonsumenBiayaTambahan::class);
    }

    public function cancellationRequest(): HasOne
    {
        return $this->hasOne(CancellationRequest::class)->latest();
    }

    public function dokumens(): HasMany
    {
        return $this->hasMany(DokumenKonsumen::class);
    }

    public function pembayarans(): HasMany
    {
        return $this->hasMany(PembayaranKonsumen::class);
    }

    public function jadwalTagihans(): HasMany
    {
        return $this->hasMany(JadwalTagihan::class);
    }

    public function rincianBiayaAkad(): HasMany
    {
        return $this->hasMany(KavlingKonsumenDajamSbum::class);
    }

    public function pencairanKprTahaps(): HasMany
    {
        return $this->hasMany(PencairanKprTahap::class);
    }

    public function biayaKelebihanTanahPembayaran(): BelongsTo
    {
        return $this->belongsTo(PembayaranKonsumen::class, 'biaya_kelebihan_tanah_pembayaran_id');
    }

    public function tambahanUmPembayaran(): BelongsTo
    {
        return $this->belongsTo(PembayaranKonsumen::class, 'tambahan_um_pembayaran_id');
    }

    public function sbumRecord(): HasOne
    {
        return $this->hasOne(SbumRecord::class);
    }

    public function swapHistories(): HasMany
    {
        return $this->hasMany(UnitSwapHistory::class)->latest();
    }

    public function bastRecord(): HasOne
    {
        return $this->hasOne(BastRecord::class);
    }

    /* ---------------------------------------------------------------
     | Scopes
     --------------------------------------------------------------- */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /* ---------------------------------------------------------------
     | Accessors
     --------------------------------------------------------------- */

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'active'    => 'Aktif',
            'cancelled' => 'Dibatalkan',
            'completed' => 'Selesai',
            default     => $this->status,
        };
    }

    public function getStatusPenjualanLabelAttribute(): string
    {
        return match($this->status_penjualan) {
            'booking'      => 'Booking',
            'pemberkasan'  => 'Pemberkasan',
            'proses_bank'  => 'Proses Bank / SLIK',
            'sp3k'         => 'SP3K',
            'rencana_akad' => 'Rencana Akad',
            'akad'         => 'Akad',
            'bast'         => 'BAST',
            'batal'        => 'Batal',
            default        => $this->status_penjualan ?? '-',
        };
    }

    public function getStatusBankLabelAttribute(): ?string
    {
        return match($this->status_bank) {
            'diajukan'  => 'Diajukan',
            'disetujui' => 'Disetujui',
            'ditolak'   => 'Ditolak',
            default     => null,
        };
    }

    public function getStatusSp3kLabelAttribute(): ?string
    {
        return match($this->status_sp3k) {
            'approved'     => 'Approved',
            'turun_plafon' => 'Turun Plafon',
            'ditolak'      => 'Ditolak',
            default        => null,
        };
    }

    /** Apakah semua dokumen wajib sudah berstatus sudah_ada */
    public function getDokumenWajibLengkapAttribute(): bool
    {
        $wajib = $this->dokumens->where('sifat', 'wajib');
        if ($wajib->isEmpty()) return true;
        return $wajib->every(fn($d) => $d->status === 'sudah_ada');
    }

    /**
     * Status masa berlaku SP3K untuk badge warna di UI.
     * Ambang (30/14 hari) hanya untuk kategori warna, bukan aturan bisnis
     * universal — tanggal expired-nya sendiri diinput manual per dokumen bank.
     */
    public function getSp3kExpiryStatusAttribute(): ?string
    {
        if (!$this->tanggal_expired_sp3k) return null;

        $days = now()->startOfDay()->diffInDays($this->tanggal_expired_sp3k, false);

        if ($days < 0) return 'expired';
        if ($days <= 14) return 'critical';
        if ($days <= 30) return 'warning';
        return 'safe';
    }

    /** Progress kelengkapan berkas (persen) */
    public function getProgressBerkasAttribute(): array
    {
        $dokumens = $this->dokumens;
        $total = $dokumens->count();
        if ($total === 0) return ['persen' => 0, 'ada' => 0, 'total' => 0];

        $ada = $dokumens->where('status', 'sudah_ada')->count();
        return [
            'persen' => round(($ada / $total) * 100),
            'ada'    => $ada,
            'total'  => $total,
        ];
    }

    /** Total pembayaran yang sudah masuk */
    public function getTotalTerbayarAttribute(): float
    {
        return (float) $this->pembayarans->sum('jumlah');
    }

    /**
     * Lock permanen begitu transaksi ditandai Selesai (bukan otomatis saat
     * Akad — Akad cuma tahap sales, uang dari bank/dajam bisa masih proses
     * beberapa saat setelahnya, jadi transaksi harus tetap bisa dicatat
     * pembayarannya sampai admin sendiri yang konfirmasi "Tandai Selesai"
     * di Keuangan). Lihat ChecksTransactionLock utk cara override oleh
     * manajer/admin, dan KeuanganController::markComplete() utk syaratnya.
     */
    public function getIsLockedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    /** Apakah seluruh Kartu Piutang jenis booking_fee & DP sudah lunas (gate sebelum Proses Bank) */
    public function getDpLunasAttribute(): bool
    {
        $dpJadwal = $this->jadwalTagihans->whereIn('jenis', ['booking_fee', 'dp']);
        if ($dpJadwal->isEmpty()) return true;
        return $dpJadwal->every(fn($j) => $j->status === 'lunas');
    }

    /** Apakah seluruh Kartu Piutang (semua jenis) sudah lunas (gate cash/cash bertahap sebelum Rencana Akad) */
    public function getPiutangLunasAttribute(): bool
    {
        if ($this->jadwalTagihans->isEmpty()) return true;
        return $this->jadwalTagihans->every(fn($j) => $j->status === 'lunas');
    }

    /**
     * Breakdown Kartu Piutang + Pencairan KPR + total agregat pembayaran —
     * dipakai bareng oleh KonsumenController::show() (detail per transaksi)
     * dan KeuanganController (tabel monitoring & detail Keuangan), supaya
     * formula Plafon KPR/SBUM/Pencairan cuma ada di satu tempat.
     *
     * Perlu eager-load: jadwalTagihans(.pembayaran), biayaTambahans.pembayaran,
     * biayaKelebihanTanahPembayaran, rincianBiayaAkad.pembayaran, skemaDpPreset,
     * pembayarans, pencairanKprTahaps, tambahanUmPembayaran — kalau tidak,
     * relasi di-lazy-load otomatis (aman utk 1 transaksi, tapi N+1 kalau
     * dipanggil untuk banyak baris sekaligus).
     */
    public function kartuPiutangBreakdown(): array
    {
        $isKpr = in_array($this->cara_bayar, ['kpr_subsidi', 'kpr_komersil']);
        $isKprSubsidi = $this->cara_bayar === 'kpr_subsidi';
        $bookingFeeTotal = (float) $this->jadwalTagihans->where('jenis', 'booking_fee')->sum('jumlah');
        $dpTotal = (float) $this->jadwalTagihans->where('jenis', 'dp')->sum('jumlah');
        // SBUM hanya berlaku untuk KPR Subsidi — di luar itu tidak relevan
        // sama sekali, jadi tidak dikurangkan dari Plafon KPR.
        $sbumTotal = $isKprSubsidi ? (float) $this->rincianBiayaAkad->where('kategori', 'sbum')->sum('nominal') : 0;

        $terbayarKonsumen = 0.0;

        // Status baris grup (Booking Fee/DP/Pelunasan bertahap) dihitung dari
        // agregat status cicilan-cicilannya: semua lunas → lunas, ada yang
        // udah kebayar (sebagian/lunas) tapi belum semua → sebagian, belum
        // ada yang kebayar sama sekali → belum_bayar.
        $groupStatus = function ($cicilan) {
            if ($cicilan->isEmpty()) return 'belum_bayar';
            if ($cicilan->every(fn($j) => $j->status === 'lunas')) return 'lunas';
            if ($cicilan->contains(fn($j) => $j->status !== 'belum_bayar')) return 'sebagian';
            return 'belum_bayar';
        };

        // ── Kartu Piutang: baris terkomputasi (bukan dari CRUD rincian biaya
        // akad) — Booking Fee/DP dari jadwal tagihan, Biaya Tanah & Biaya
        // Tambahan dari data booking, baris akhir otomatis sesuai skema bayar
        // (cash: Pelunasan, KPR: Plafon KPR pindah ke section Pencairan KPR).
        $kartuPiutangStatic = collect();
        if ($bookingFeeTotal > 0) {
            $cicilan = $this->jadwalTagihans->where('jenis', 'booking_fee');
            $lunas = $cicilan->where('status', 'lunas');
            $dibayar = (float) $cicilan->filter(fn($j) => $j->pembayaran)->sum(fn($j) => $j->pembayaran->jumlah);
            $terbayarKonsumen += $dibayar;
            $kartuPiutangStatic->push([
                'type' => 'booking_fee_group', 'id' => null, 'status' => $groupStatus($cicilan),
                'nama' => 'Booking Fee', 'subjek' => 'Konsumen', 'nominal' => $bookingFeeTotal,
                'jumlah_dibayar' => $dibayar,
                'progress' => $lunas->count() . '/' . $cicilan->count() . ' lunas',
            ]);
        }
        if ($dpTotal > 0) {
            $cicilan = $this->jadwalTagihans->where('jenis', 'dp');
            $lunas = $cicilan->where('status', 'lunas');
            $dibayar = (float) $cicilan->filter(fn($j) => $j->pembayaran)->sum(fn($j) => $j->pembayaran->jumlah);
            $terbayarKonsumen += $dibayar;
            $kartuPiutangStatic->push([
                'type' => 'dp_group', 'id' => null, 'status' => $groupStatus($cicilan),
                'nama' => 'DP', 'subjek' => 'Konsumen', 'nominal' => $dpTotal,
                'jumlah_dibayar' => $dibayar,
                'progress' => $lunas->count() . '/' . $cicilan->count() . ' lunas',
            ]);
        }
        // Biaya Tanah & Biaya Tambahan cuma jadi baris terpisah yang bisa
        // dibayar sendiri untuk KPR — karena Plafon KPR dihitung dari
        // harga_dasar (tidak termasuk komponen ini), jadi aman ditagih
        // terpisah. Untuk cash/cash bertahap, Pelunasan dihitung dari
        // harga_deal yang SUDAH termasuk kedua komponen ini — kalau tetap
        // ditampilkan sebagai baris terpisah maka konsumen tercatat bayar
        // dua kali (double) untuk komponen yang sama.
        if ($isKpr) {
            if ($this->biaya_kelebihan_tanah_aktif) {
                if ($this->biayaKelebihanTanahPembayaran) {
                    $terbayarKonsumen += (float) $this->biayaKelebihanTanahPembayaran->jumlah;
                }
                $kartuPiutangStatic->push([
                    'type' => 'biaya_tanah', 'id' => null, 'status' => $this->biaya_kelebihan_tanah_status,
                    'nama' => 'Biaya Penambahan Tanah', 'subjek' => 'Konsumen',
                    'nominal' => (float) $this->biaya_kelebihan_tanah_nominal,
                    'jumlah_dibayar' => $this->biayaKelebihanTanahPembayaran?->jumlah,
                    'tanggal_bayar' => $this->biayaKelebihanTanahPembayaran?->tanggal_bayar?->format('d M Y'),
                ]);
            }
            foreach ($this->biayaTambahans as $bt) {
                if ($bt->pembayaran) {
                    $terbayarKonsumen += (float) $bt->pembayaran->jumlah;
                }
                $kartuPiutangStatic->push([
                    'type' => 'biaya_tambahan', 'id' => $bt->id, 'status' => $bt->status,
                    'nama' => $bt->nama, 'subjek' => 'Konsumen', 'nominal' => (float) $bt->nominal,
                    'jumlah_dibayar' => $bt->pembayaran?->jumlah,
                    'tanggal_bayar' => $bt->pembayaran?->tanggal_bayar?->format('d M Y'),
                ]);
            }
        }

        // Booking Fee/DP yang TIDAK masuk harga jual berarti biaya terpisah
        // di luar harga rumah, jadi tidak mengurangi Plafon KPR maupun
        // Pelunasan — dipakai ulang di kedua cabang (KPR & cash) di bawah.
        $skema = $this->skemaDpPreset;
        $bookingFeePengurang = ($skema?->booking_fee_masuk_harga_jual ?? false) ? $bookingFeeTotal : 0;
        $dpPengurang = ($skema?->dp_masuk_harga_jual ?? false) ? $dpTotal : 0;

        // ── Pencairan KPR: section terpisah dari Kartu Piutang, urutan sales
        // input SBUM (khusus subsidi) → kalkulasi Plafon KPR → sales input
        // Dana Jaminan → Tambahan Uang Muka (kalau turun plafon) → Pencairan
        // KPR bersih (plafon disetujui - dajam).
        $pencairanKpr = null;
        if ($isKpr) {
            // Plafon KPR = harga dasar - Booking Fee (kalau skema preset-nya
            // set "masuk harga jual") - DP (sama) - SBUM (SBUM cuma relevan
            // utk KPR Subsidi, sudah dinolkan kalau bukan).
            $plafonKprHitung = max(0, (float) $this->harga_dasar - $bookingFeePengurang - $dpPengurang - $sbumTotal);

            // Tambahan Uang Muka: selisih kebutuhan plafon awal dengan plafon
            // yang benar-benar disetujui bank kalau turun plafon — konsumen
            // yang menutup selisihnya.
            $tambahanUm = 0;
            if ($this->status_sp3k === 'turun_plafon' && $this->plafon_kpr !== null) {
                $tambahanUm = max(0, $plafonKprHitung - (float) $this->plafon_kpr);
            }

            $plafonDisetujui = $this->plafon_kpr !== null ? (float) $this->plafon_kpr : $plafonKprHitung;
            $dajamTotal = (float) $this->rincianBiayaAkad->where('kategori', 'dajam')->sum('nominal');
            $pencairanNominal = max(0, $plafonDisetujui - $dajamTotal);

            // Pencairan KPR (uang cair dari bank ke developer) dicatat manual
            // per tahap — bank tidak ikut skema/tenor apa pun, jadi baris-
            // barisnya murni ditambahkan admin tiap kali benar-benar cair
            // (bisa berkali-kali/bertahap), bukan digenerate otomatis.
            $pencairanTercatat = (float) $this->pencairanKprTahaps->sum('nominal');
            $pencairanStatus = 'belum_bayar';
            if ($pencairanTercatat > 0) {
                $pencairanStatus = $pencairanTercatat >= $pencairanNominal ? 'lunas' : 'sebagian';
            }

            $pencairanKpr = [
                'plafon_hitung'      => $plafonKprHitung,
                'tambahan_um'        => $tambahanUm,
                'pencairan_nominal'  => $pencairanNominal,
                'pencairan_tercatat' => $pencairanTercatat,
                'pencairan_status'   => $pencairanStatus,
            ];

            // Tambahan Uang Muka jadi baris Kartu Piutang sungguhan (subjek
            // Konsumen) begitu terdeteksi turun plafon — supaya bisa ditagih
            // & dicatat pembayarannya seperti Biaya Tambahan lain, bukan
            // cuma angka tampilan di section Pencairan KPR.
            if ($tambahanUm > 0) {
                if ($this->tambahanUmPembayaran) {
                    $terbayarKonsumen += (float) $this->tambahanUmPembayaran->jumlah;
                }
                $kartuPiutangStatic->push([
                    'type' => 'tambahan_um', 'id' => null, 'status' => $this->tambahan_um_status,
                    'nama' => 'Tambahan Uang Muka', 'subjek' => 'Konsumen',
                    'nominal' => $tambahanUm,
                    'jumlah_dibayar' => $this->tambahanUmPembayaran?->jumlah,
                    'tanggal_bayar' => $this->tambahanUmPembayaran?->tanggal_bayar?->format('d M Y'),
                ]);
            }
        } else {
            // Pelunasan = harga_deal (sudah termasuk biaya tanah & biaya
            // tambahan, makanya keduanya TIDAK ditampilkan terpisah di atas)
            // dikurangi Booking Fee/DP yang masuk harga jual — persis pola
            // Plafon KPR, cuma basisnya harga_deal (bukan harga_dasar).
            $pelunasanTotal = max(0, (float) $this->harga_deal - $bookingFeePengurang - $dpPengurang);
            $pelunasanJadwal = $this->jadwalTagihans->where('jenis', 'pelunasan');

            // Legacy: pembayaran Pelunasan yang dicatat SEBELUM fitur jadwal
            // cicilan ada (satu baris lump sum, tidak terhubung ke
            // jadwal_tagihan manapun) — tetap dihitung supaya uang yang
            // sudah tercatat tidak hilang begitu transaksi lama ini mulai
            // ditambahkan cicilan baru lewat "+ Tambah Cicilan Custom".
            $pelunasanLegacy = $pelunasanJadwal->isEmpty() ? $this->pembayarans->where('jenis', 'pelunasan')->first() : null;

            // Selalu jadi baris grup (bisa di-expand) — baik yang sudah
            // punya jadwal cicilan (Cash Bertahap/Cash baru) maupun yang
            // belum (booking lama), supaya admin selalu bisa lihat rincian
            // & mulai mencatat pembayaran bertahap dari sini.
            $lunasCicilan = $pelunasanJadwal->where('status', 'lunas');
            $dibayarPelunasan = (float) $pelunasanJadwal->filter(fn($j) => $j->pembayaran)->sum(fn($j) => $j->pembayaran->jumlah);
            if ($pelunasanLegacy) {
                $dibayarPelunasan += (float) $pelunasanLegacy->jumlah;
            }
            $terbayarKonsumen += $dibayarPelunasan;

            $nominalPelunasan = $pelunasanJadwal->isNotEmpty() ? (float) $pelunasanJadwal->sum('jumlah') : $pelunasanTotal;
            $statusPelunasan = 'belum_bayar';
            if ($dibayarPelunasan > 0) {
                $statusPelunasan = $dibayarPelunasan >= $nominalPelunasan ? 'lunas' : 'sebagian';
            }
            $totalCicilanCount = $pelunasanJadwal->count() + ($pelunasanLegacy ? 1 : 0);
            $lunasCicilanCount = $lunasCicilan->count() + ($pelunasanLegacy ? 1 : 0);

            $kartuPiutangStatic->push([
                'type' => 'pelunasan_group', 'id' => null, 'status' => $statusPelunasan,
                'nama' => 'Pelunasan', 'subjek' => 'Konsumen',
                'nominal' => $nominalPelunasan,
                'jumlah_dibayar' => $dibayarPelunasan > 0 ? $dibayarPelunasan : null,
                'progress' => $totalCicilanCount > 0 ? "{$lunasCicilanCount}/{$totalCicilanCount} lunas" : null,
                'legacy_pembayaran' => $pelunasanLegacy ? [
                    'id'            => $pelunasanLegacy->id,
                    'jumlah'        => $pelunasanLegacy->jumlah,
                    'tanggal_bayar' => $pelunasanLegacy->tanggal_bayar?->format('d M Y'),
                ] : null,
            ]);
        }

        // ── Progress sisi Bank: SBUM & Dana Jaminan (dicatat per-item) DAN
        // Pencairan KPR (dicatat per-tahap) — Pencairan KPR biasanya porsi
        // terbesar dari uang bank, jadi harus ikut dihitung, bukan cuma
        // SBUM+Dajam (dipakai buat kolom "Progress Pencairan KPR/Bank" di
        // tabel monitoring Keuangan).
        $bankItems = $this->rincianBiayaAkad->whereIn('kategori', ['sbum', 'dajam']);
        $totalPiutangBank = (float) $bankItems->sum('nominal');
        $totalTerbayarBank = (float) $bankItems->filter(fn($item) => $item->pembayaran)->sum(fn($item) => $item->pembayaran->jumlah);
        if ($isKpr) {
            $totalPiutangBank += $pencairanKpr['pencairan_nominal'];
            $totalTerbayarBank += $pencairanKpr['pencairan_tercatat'];
        }

        return [
            'kartu_piutang_static'     => $kartuPiutangStatic->values(),
            'pencairan_kpr'            => $pencairanKpr,
            'total_piutang_konsumen'   => (float) $kartuPiutangStatic->sum('nominal'),
            'total_terbayar_konsumen'  => $terbayarKonsumen,
            'total_piutang_bank'       => $totalPiutangBank,
            'total_terbayar_bank'      => $totalTerbayarBank,
        ];
    }
}

