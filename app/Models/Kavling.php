<?php

namespace App\Models;

use App\Enums\StatusJual;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Kavling extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'project_id',
        'tipe_unit_preset_id',
        'kluster',
        'nomor_kavling',
        'blok',
        'harga',
        'status_jual',
        'status_bangun_stage_id',
        'status_unit',
        'keterangan',
        'perlu_biaya_tambahan',
        'koordinat_x',
        'koordinat_y',
        'catatan',
        'id_rumah',
    ];

    protected $casts = [
        'harga'                 => 'decimal:2',
        'koordinat_x'           => 'decimal:3',
        'koordinat_y'           => 'decimal:3',
        'status_jual'           => StatusJual::class,
        'perlu_biaya_tambahan'  => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(
                fn(string $event) => "Kavling {$this->nomor_kavling} (Blok {$this->blok}) telah di-{$event}"
            );
    }

    public function scopeByKluster($query, string $kluster)
    {
        return $query->where('kluster', $kluster);
    }

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tipeUnitPreset(): BelongsTo
    {
        return $this->belongsTo(TipeUnitPreset::class);
    }

    public function statusBangunStage(): BelongsTo
    {
        return $this->belongsTo(StatusBangunStage::class);
    }

    public function kavlingKonsumens(): HasMany
    {
        return $this->hasMany(KavlingKonsumen::class);
    }

    /** Transaksi aktif (konsumen saat ini) */
    public function activeTransaction(): HasOne
    {
        return $this->hasOne(KavlingKonsumen::class)->where('status', 'active')->latest();
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class);
    }

    /* ---------------------------------------------------------------
     | Scopes
     --------------------------------------------------------------- */

    public function scopeAvailable($query)
    {
        return $query->where('status_jual', StatusJual::Available);
    }

    public function scopeByProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByStatusJual($query, string $status)
    {
        return $query->where('status_jual', $status);
    }

    /* ---------------------------------------------------------------
     | Accessors
     --------------------------------------------------------------- */

    public function getNomorLengkapAttribute(): string
    {
        return $this->blok ? "Blok {$this->blok}-{$this->nomor_kavling}" : $this->nomor_kavling;
    }

    /**
     * ID unik untuk matching elemen di file siteplan SVG, format "{blok}-{nomor}"
     * (mis. "A-17"). Admin yang menyiapkan file SVG siteplan harus memberi
     * atribut id persis nilai ini pada tiap bentuk kavling.
     */
    public function getSvgIdAttribute(): string
    {
        return $this->blok ? "{$this->blok}-{$this->nomor_kavling}" : $this->nomor_kavling;
    }

    public function getStatusJualLabelAttribute(): string
    {
        return $this->status_jual->label();
    }

    public function getStatusBangunLabelAttribute(): string
    {
        return $this->statusBangunStage?->nama ?? '-';
    }

    public function getProgressBangunAttribute(): float
    {
        return $this->statusBangunStage?->progressPercent() ?? 0;
    }
}
