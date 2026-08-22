<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeveloperProfileBank extends Model
{
    protected $fillable = [
        'developer_profile_id',
        'nama_bank',
        'nomor_rekening',
        'atas_nama_rekening',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function developerProfile(): BelongsTo
    {
        return $this->belongsTo(DeveloperProfile::class);
    }
}
