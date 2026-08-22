<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Project extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'nama',
        'kode',
        'deskripsi',
        'lokasi',
        'kota',
        'luas_tanah_total',
        'siteplan_image',
        'siteplan_marker_size',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'luas_tanah_total' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $eventName) => "Project {$this->nama} telah di-{$eventName}");
    }

    public function kavlings(): HasMany
    {
        return $this->hasMany(Kavling::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getKavlingCountAttribute(): int
    {
        return $this->kavlings()->count();
    }

    public function getKavlingTerjualAttribute(): int
    {
        return $this->kavlings()->where('status_jual', 'sold')->count();
    }

    public function getKavlingAvailableAttribute(): int
    {
        return $this->kavlings()->where('status_jual', 'available')->count();
    }

    public function getProgressPersentaseAttribute(): float
    {
        $total = $this->kavling_count;
        if ($total === 0) return 0;
        return round(($this->kavling_terjual / $total) * 100, 1);
    }
}
