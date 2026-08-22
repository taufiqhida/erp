<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkemaDpPreset extends Model
{
    protected $fillable = [
        'nama',
        'cara_bayar',
        'booking_fee_aktif',
        'booking_fee_tipe',
        'booking_fee_nilai',
        'booking_fee_tenor',
        'booking_fee_masuk_harga_jual',
        'booking_fee_basis',
        'dp_aktif',
        'dp_tipe',
        'dp_nilai',
        'dp_tenor',
        'dp_masuk_harga_jual',
        'dp_basis',
        'keterangan',
        'is_active',
    ];

    protected $casts = [
        'booking_fee_aktif'            => 'boolean',
        'booking_fee_nilai'            => 'decimal:2',
        'booking_fee_masuk_harga_jual' => 'boolean',
        'dp_aktif'                     => 'boolean',
        'dp_nilai'                     => 'decimal:2',
        'dp_masuk_harga_jual'          => 'boolean',
        'is_active'                    => 'boolean',
    ];

    public static function caraBayarLabel(): array
    {
        return [
            'cash'          => 'Cash',
            'cash_bertahap' => 'Cash Bertahap',
            'kpr_subsidi'   => 'KPR Subsidi',
            'kpr_komersil'  => 'KPR Komersil',
        ];
    }

    public static function basisLabel(): array
    {
        return [
            'harga_dasar' => 'Harga Dasar',
            'harga_netto' => 'Harga Jual Netto',
        ];
    }
}
