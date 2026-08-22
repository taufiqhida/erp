<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DajamSbumPreset extends Model
{
    protected $fillable = [
        'nama',
        'kategori',
        'keterangan',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function kategoriLabel(): array
    {
        return [
            'dajam'      => 'Dana Jaminan',
            'sbum'       => 'SBUM',
            'biaya_akad' => 'Biaya Akad',
        ];
    }
}
