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
        'tanggal_akad',
        'harga_deal',
        'metode_bayar',
        'booking_fee',
        'cara_bayar',
        'cicilan_kali',
        'skema_dp',
        'plafon_kpr',
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
        'rekening_kpr_dibuka',
        'biaya_akad_lunas',
        'tanggal_bast',
        'realisasi_cair',
        'dajam_ditahan',
        'catatan',
        'created_by',
    ];

    protected $casts = [
        'tanggal_akad'           => 'date',
        'tanggal_rencana_akad'   => 'date',
        'tanggal_pengajuan_bank' => 'date',
        'tanggal_keputusan_bank' => 'date',
        'tanggal_sp3k'           => 'date',
        'tanggal_expired_sp3k'   => 'date',
        'tanggal_disetujui_sp3k' => 'date',
        'tanggal_bast'           => 'date',
        'harga_deal'             => 'decimal:2',
        'booking_fee'            => 'decimal:2',
        'plafon_kpr'             => 'decimal:2',
        'realisasi_cair'         => 'decimal:2',
        'dajam_ditahan'          => 'decimal:2',
        'rekening_kpr_dibuka'    => 'boolean',
        'biaya_akad_lunas'       => 'boolean',
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
}

