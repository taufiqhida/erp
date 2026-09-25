<?php

namespace App\Models;

use App\Models\Concerns\HasUrutan;
use Illuminate\Database\Eloquent\Model;

class BankRekananPreset extends Model
{
    use HasUrutan;

    protected $fillable = [
        'nama',
        'nama_pt',
        'kantor_cabang',
        'keterangan',
        'alamat',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
