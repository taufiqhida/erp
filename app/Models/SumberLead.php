<?php

namespace App\Models;

use App\Models\Concerns\HasUrutan;
use Illuminate\Database\Eloquent\Model;

class SumberLead extends Model
{
    use HasUrutan;

    protected $fillable = [
        'nama',
        'keterangan',
        'is_referral',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_referral' => 'boolean',
    ];
}
