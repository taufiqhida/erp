<?php

namespace App\Models;

use App\Enums\StatusBangun;
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
        'nomor_kavling',
        'blok',
        'luas_tanah',
        'luas_bangunan',
        'harga',
        'status_jual',
        'status_bangun',
        'status_unit',
        'foto_rumah',
        'denah_rumah',
        'tipe_unit',
        'kamar_tidur',
        'kamar_mandi',
        'spek_atap',
        'spek_dinding',
        'spek_lantai',
        'spek_pondasi',
        'keterangan',
        'koordinat_x',
        'koordinat_y',
        'catatan',
    ];

    protected $casts = [
        'luas_tanah'    => 'decimal:2',
        'luas_bangunan' => 'decimal:2',
        'harga'         => 'decimal:2',
        'koordinat_x'   => 'decimal:3',
        'koordinat_y'   => 'decimal:3',
        'status_jual'   => StatusJual::class,
        'status_bangun' => StatusBangun::class,
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

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
        return $this->status_bangun->label();
    }

    public function getProgressBangunAttribute(): int
    {
        return $this->status_bangun->progressPercent();
    }
}
