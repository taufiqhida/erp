<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KavlingKonsumenBiayaTambahan extends Model
{
    protected $table = 'kavling_konsumen_biaya_tambahan';

    protected $fillable = [
        'kavling_konsumen_id',
        'biaya_tambahan_preset_id',
        'nama',
        'nominal',
        'status',
        'pembayaran_konsumen_id',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
    ];

    public function kavlingKonsumen(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class);
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(BiayaTambahanPreset::class, 'biaya_tambahan_preset_id');
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(PembayaranKonsumen::class, 'pembayaran_konsumen_id');
    }
}
