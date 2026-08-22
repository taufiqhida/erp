<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KavlingKonsumenDajamSbum extends Model
{
    protected $table = 'kavling_konsumen_dajam_sbum';

    protected $fillable = [
        'kavling_konsumen_id',
        'dajam_sbum_preset_id',
        'nama',
        'kategori',
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
        return $this->belongsTo(DajamSbumPreset::class, 'dajam_sbum_preset_id');
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(PembayaranKonsumen::class, 'pembayaran_konsumen_id');
    }
}
