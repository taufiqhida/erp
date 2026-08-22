<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PencairanKprTahap extends Model
{
    protected $table = 'pencairan_kpr_tahap';

    protected $fillable = [
        'kavling_konsumen_id',
        'nominal',
        'tanggal_cair',
        'keterangan',
        'created_by',
    ];

    protected $casts = [
        'nominal'      => 'decimal:2',
        'tanggal_cair' => 'date',
    ];

    public function kavlingKonsumen(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
