<?php

namespace App\Models;

use App\Models\Concerns\HasUrutan;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SalesAgent extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, HasUrutan;

    // Rekening & skema komisi sengaja tidak dicatat di sini — itu urusan HR
    // di sistem terpisah. Sistem ini cukup simpan atribusi (siapa jual unit
    // apa) lewat relasi kavlingKonsumens(), itu sudah jadi rekap datanya.
    protected $fillable = [
        'nama',
        'tipe',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $event) => "Sales/Agent {$this->nama} telah di-{$event}");
    }

    public function kavlingKonsumens(): HasMany
    {
        return $this->hasMany(KavlingKonsumen::class);
    }

    // Urutan diatur per kategori (tipe) — geser hanya menukar dengan sesama tipe.
    public function urutanGroup(): array
    {
        return ['tipe' => $this->tipe];
    }

    // Kategori mengikuti urutan tipeLabels(), lalu urutan di dalam kategori.
    public function scopeOrdered($query)
    {
        $tipeOrder = implode(',', array_map(fn ($t) => "'{$t}'", array_keys(self::tipeLabels())));

        return $query->orderByRaw("FIELD(tipe, {$tipeOrder})")->orderBy('urutan')->orderBy('id');
    }

    // Asal sales — label tagging saja; fee/agensi diurus di luar sistem ini.
    public static function tipeLabels(): array
    {
        return [
            'inhouse'   => 'Inhouse',
            'freelance' => 'Freelance',
            'agen'      => 'Agen',
            'allowance' => 'Allowance',
        ];
    }

    public function getTipeLabelAttribute(): string
    {
        return self::tipeLabels()[$this->tipe] ?? ucfirst($this->tipe);
    }

}
