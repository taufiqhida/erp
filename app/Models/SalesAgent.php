<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SalesAgent extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'nama',
        'tipe',
        'user_id',
        'nik',
        'npwp',
        'no_hp',
        'email',
        'nama_bank',
        'nomor_rekening',
        'atas_nama_rekening',
        'agency_nama',
        'komisi_tipe',
        'komisi_nilai',
        'is_active',
    ];

    protected $casts = [
        'komisi_nilai' => 'decimal:2',
        'is_active'    => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $event) => "Sales/Agent {$this->nama} telah di-{$event}");
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kavlingKonsumens(): HasMany
    {
        return $this->hasMany(KavlingKonsumen::class);
    }

    public function getTipeLabelAttribute(): string
    {
        return $this->tipe === 'freelance' ? 'Freelance' : 'Inhouse';
    }

    public function getKomisiLabelAttribute(): string
    {
        if (!$this->komisi_nilai) return '-';
        return $this->komisi_tipe === 'persen'
            ? "{$this->komisi_nilai}%"
            : 'Rp ' . number_format((float) $this->komisi_nilai, 0, ',', '.');
    }
}
