<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitSwapHistory extends Model
{
    protected $fillable = [
        'kavling_konsumen_id',
        'kavling_lama_id',
        'kavling_baru_id',
        'user_id',
        'alasan',
    ];

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class, 'kavling_konsumen_id');
    }

    public function kavlingLama(): BelongsTo
    {
        return $this->belongsTo(Kavling::class, 'kavling_lama_id');
    }

    public function kavlingBaru(): BelongsTo
    {
        return $this->belongsTo(Kavling::class, 'kavling_baru_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
