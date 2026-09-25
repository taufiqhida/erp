<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kontraktor extends Model
{
    protected $fillable = [
        'nama',
        'no_hp',
        'alamat',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function spks(): HasMany
    {
        return $this->hasMany(Spk::class);
    }
}
