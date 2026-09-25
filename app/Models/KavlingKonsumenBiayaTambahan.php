<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KavlingKonsumenBiayaTambahan extends Model
{
    protected $table = 'kavling_konsumen_biaya_tambahan';

    protected $fillable = [
        'kavling_konsumen_id',
        'biaya_tambahan_preset_id',
        'nama',
        'nominal',
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

    /** Cicilan bebas item ini — lihat KavlingKonsumen::kartuPiutangBreakdown(). */
    public function pembayarans(): HasMany
    {
        return $this->hasMany(PembayaranKonsumen::class, 'kavling_konsumen_biaya_tambahan_id');
    }
}
