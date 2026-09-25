<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Spk extends Model
{
    protected $fillable = [
        'project_id',
        'kontraktor_id',
        'nomor_spk',
        'tanggal_terbit',
        'tanggal_deadline',
        'catatan',
        'created_by',
    ];

    protected $casts = [
        'tanggal_terbit'   => 'date',
        'tanggal_deadline' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function kontraktor(): BelongsTo
    {
        return $this->belongsTo(Kontraktor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kavlings(): BelongsToMany
    {
        return $this->belongsToMany(Kavling::class, 'spk_kavling');
    }

    /**
     * Status warna deadline (badge) — pola sama persis
     * KavlingKonsumen::getSp3kExpiryStatusAttribute().
     */
    public function getDeadlineStatusAttribute(): string
    {
        $days = now()->startOfDay()->diffInDays($this->tanggal_deadline, false);

        if ($days < 0) return 'expired';
        if ($days <= 14) return 'critical';
        if ($days <= 30) return 'warning';
        return 'safe';
    }
}
