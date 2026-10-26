<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoPreset extends Model
{
    protected $fillable = [
        'nama',
        'keterangan',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function lawan(): HasMany
    {
        return $this->hasMany(PromoPresetLawan::class);
    }

    /** Apakah promo ini boleh memotong Biaya Kelebihan Tanah. */
    public function lawanTanah(): bool
    {
        return $this->lawan->contains('jenis', PromoPresetLawan::TANAH);
    }

    /** Apakah promo ini boleh mengurangi Harga Jual (diskon umum). */
    public function lawanHargaJual(): bool
    {
        return $this->lawan->contains('jenis', PromoPresetLawan::HARGA_JUAL);
    }

    /** @return int[] id preset Biaya Tambahan yang boleh dipotong promo ini. */
    public function lawanBiayaTambahanIds(): array
    {
        return $this->lawan->where('jenis', PromoPresetLawan::BIAYA_TAMBAHAN)->pluck('biaya_tambahan_preset_id')->filter()->map(fn ($v) => (int) $v)->values()->all();
    }

    /** Bentuk ringkas untuk form (booking & ubah rincian): daftar promo beserta lawannya. */
    public static function untukForm(): \Illuminate\Support\Collection
    {
        return static::where('is_active', true)->with('lawan')->orderBy('nama')->get()->map(fn (self $p) => [
            'id'                 => $p->id,
            'nama'               => $p->nama,
            'lawan_tanah'        => $p->lawanTanah(),
            'lawan_harga_jual'   => $p->lawanHargaJual(),
            'lawan_biaya_tambahan' => $p->lawanBiayaTambahanIds(),
        ]);
    }
}
