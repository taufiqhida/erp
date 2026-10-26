<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Lawan" sebuah promo: biaya yang boleh dipotong promo itu (tanpa nominal — nominal diisi per transaksi). */
class PromoPresetLawan extends Model
{
    protected $table = 'promo_preset_lawan';

    public const TANAH = 'tanah';
    public const BIAYA_TAMBAHAN = 'biaya_tambahan';

    protected $fillable = ['promo_preset_id', 'jenis', 'biaya_tambahan_preset_id'];

    public function promo(): BelongsTo
    {
        return $this->belongsTo(PromoPreset::class, 'promo_preset_id');
    }

    public function biayaTambahanPreset(): BelongsTo
    {
        return $this->belongsTo(BiayaTambahanPreset::class);
    }
}
