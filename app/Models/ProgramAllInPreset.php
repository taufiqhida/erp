<?php

namespace App\Models;

use App\Models\Concerns\HasUrutan;
use Illuminate\Database\Eloquent\Model;

class ProgramAllInPreset extends Model
{
    use HasUrutan;

    protected $fillable = [
        'nama',
        'nominal',
        'include_booking_fee',
        'include_dp',
        'is_active',
    ];

    protected $casts = [
        'nominal'              => 'decimal:2',
        'include_booking_fee'  => 'boolean',
        'include_dp'           => 'boolean',
        'is_active'            => 'boolean',
    ];
}
