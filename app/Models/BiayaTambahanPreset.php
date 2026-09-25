<?php

namespace App\Models;

use App\Models\Concerns\HasUrutan;
use Illuminate\Database\Eloquent\Model;

class BiayaTambahanPreset extends Model
{
    use HasUrutan;

    protected $fillable = [
        'nama',
        'keterangan',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
