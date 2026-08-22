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
        'kavling_konsumen_id',
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
}
