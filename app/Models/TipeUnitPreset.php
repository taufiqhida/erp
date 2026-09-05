<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Master data tipe unit — scoped per proyek (bukan global). Spek fisik
 * (luas, kamar, material, foto fasad/denah) sepenuhnya berasal dari sini;
 * Kavling tidak punya kolom spek sendiri lagi, cuma referensi ke tipe.
 * Pengecualian seperti hook/pojok/strategis TIDAK jadi tipe terpisah —
 * itu urusan Kavling.perlu_biaya_tambahan + keterangan (lihat Kavling).
 */
class TipeUnitPreset extends Model
{
    use LogsActivity;

    protected $fillable = [
        'project_id',
        'nama',
        'luas_tanah',
        'luas_bangunan',
        'kamar_tidur',
        'kamar_mandi',
        'spek_atap',
        'spek_dinding',
        'spek_lantai',
        'spek_pondasi',
        'foto_rumah',
        'denah_rumah',
        'is_active',
    ];

    protected $casts = [
        'luas_tanah'    => 'decimal:2',
        'luas_bangunan' => 'decimal:2',
        'is_active'     => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $event) => "Tipe Unit {$this->nama} telah di-{$event}");
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function kavlings(): HasMany
    {
        return $this->hasMany(Kavling::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
