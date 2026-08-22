<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiayaTambahanPreset extends Model
{
    protected $fillable = [
        'nama',
        'keterangan',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
