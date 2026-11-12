<?php

namespace App\Models;

use App\Models\Concerns\HasUrutan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Master bonus saat booking (tandon, kipas, AC, ...). Label saja: tanpa nominal, tanpa dampak ke keuangan. */
class BonusPreset extends Model
{
    use HasUrutan;

    protected $fillable = ['nama', 'keterangan', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function transaksis(): BelongsToMany
    {
        return $this->belongsToMany(KavlingKonsumen::class, 'kavling_konsumen_bonus')->withTimestamps();
    }
}
