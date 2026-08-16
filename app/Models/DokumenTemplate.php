<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DokumenTemplate extends Model
{
    protected $fillable = [
        'cara_bayar',
        'nama_dokumen',
        'sifat',
        'urutan',
    ];

    protected $casts = [
        'urutan' => 'integer',
    ];

    public function scopeForCaraBayar($query, string $caraBayar)
    {
        return $query->where('cara_bayar', $caraBayar)->orderBy('urutan');
    }

    public static function caraBayarLabel(): array
    {
        return [
            'cash'          => 'Cash',
            'cash_bertahap' => 'Cash Bertahap',
            'kpr_subsidi'   => 'KPR Subsidi',
            'kpr_komersil'  => 'KPR Komersil',
        ];
    }

    public static function sifatLabel(): array
    {
        return [
            'wajib'       => 'Wajib',
            'kondisional' => 'Kondisional',
            'opsional'    => 'Opsional',
        ];
    }

    public function getSifatLabelAttribute(): string
    {
        return self::sifatLabel()[$this->sifat] ?? $this->sifat;
    }
}
