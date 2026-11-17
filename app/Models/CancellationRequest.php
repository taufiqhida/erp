<?php

namespace App\Models;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CancellationRequest extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'type',
        'kavling_id',
        'kavling_baru_id',
        'konsumen_baru_id',
        'hubungan',
        'payload',
        'tahap_batal',
        'kavling_konsumen_id',
        'konsumen_id',
        'kavling_status_before',
        'requested_by',
        'reviewed_by',
        'status',
        'alasan',
        'catatan_reviewer',
        'reviewed_at',
        'nominal_diterima',
        'nominal_dikembalikan',
        'nominal_hangus',
    ];

    protected $casts = [
        'type'                 => CancellationRequestType::class,
        'status'               => CancellationStatus::class,
        'reviewed_at'          => 'datetime',
        'payload'              => 'array',
        'nominal_diterima'     => 'decimal:2',
        'nominal_dikembalikan' => 'decimal:2',
        'nominal_hangus'       => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $event) => "Pembatalan kavling telah di-{$event}");
    }

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function kavling(): BelongsTo
    {
        return $this->belongsTo(Kavling::class);
    }

    public function kavlingBaru(): BelongsTo
    {
        return $this->belongsTo(Kavling::class, 'kavling_baru_id');
    }

    /** Pembeli pada saat pengajuan dibuat (tidak ikut berubah bila transaksi kemudian dialihkan). */
    public function konsumenSaatPengajuan(): BelongsTo
    {
        return $this->belongsTo(Konsumen::class, 'konsumen_id')->withTrashed();
    }

    public function konsumenBaru(): BelongsTo
    {
        return $this->belongsTo(Konsumen::class, 'konsumen_baru_id')->withTrashed();
    }

    public function kavlingKonsumen(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /* ---------------------------------------------------------------
     | Scopes
     --------------------------------------------------------------- */

    public function scopePending($query)
    {
        return $query->where('status', CancellationStatus::Pending);
    }

    /**
     * Pengajuan yang boleh dilihat pengguna: lintas proyek bagi pemegang 'view all projects', selain itu hanya proyek yang di-assign;
     * bila ada proyek aktif (Halaman Utama), dipersempit ke proyek itu. Satu aturan untuk halaman Pengajuan dan penanda di menu.
     */
    public function scopeTerlihat($query, User $user, ?int $projectId = null)
    {
        return $query
            ->when(!$user->can('view all projects'), fn ($q) => $q->whereHas('kavling.project.users', fn ($q2) => $q2->where('users.id', $user->id)))
            ->when($projectId, fn ($q) => $q->whereHas('kavling', fn ($q2) => $q2->where('project_id', $projectId)));
    }

    /** Tab halaman Pengajuan: kunci => label dan tipe yang ditampilkan. */
    public const TAB = [
        'pembatalan'   => ['label' => 'Pembatalan',      'tipe' => ['cancellation']],
        'alih_pembeli' => ['label' => 'Alih Pembeli',    'tipe' => ['buyer_transfer']],
        'tukar_unit'   => ['label' => 'Tukar Unit',      'tipe' => ['unit_swap']],
        'rincian'      => ['label' => 'Rincian & Promo', 'tipe' => ['rincian_change']],
    ];

    /** Tab yang memuat tipe tertentu (dipakai tautan lama ?type=...). */
    public static function tabUntukTipe(?string $tipe): ?string
    {
        foreach (self::TAB as $kunci => $t) {
            if (in_array($tipe, $t['tipe'], true)) return $kunci;
        }

        return null;
    }

    /* ---------------------------------------------------------------
     | Helpers
     --------------------------------------------------------------- */

    public function isPending(): bool
    {
        return $this->status === CancellationStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === CancellationStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === CancellationStatus::Rejected;
    }

    public function isUnitSwap(): bool
    {
        return $this->type === CancellationRequestType::UnitSwap;
    }

    /** Label tahap transaksi (untuk tahap_batal dan tahap saat ini). */
    public const TAHAP_LABEL = [
        'booking' => 'Booking', 'pemberkasan' => 'Pemberkasan', 'proses_bank' => 'Proses Bank / SLIK', 'sp3k' => 'SP3K',
        'rencana_akad' => 'Rencana Akad', 'akad' => 'Akad', 'bast' => 'BAST',
    ];

    public function getTahapBatalLabelAttribute(): ?string
    {
        return self::TAHAP_LABEL[$this->tahap_batal] ?? null;
    }

    public function isRincianChange(): bool
    {
        return $this->type === CancellationRequestType::RincianChange;
    }

    public function isBuyerTransfer(): bool
    {
        return $this->type === CancellationRequestType::BuyerTransfer;
    }
}
