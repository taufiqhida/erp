<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JadwalTagihan extends Model
{
    protected $table = 'jadwal_tagihan';

    protected $fillable = [
        'kavling_konsumen_id',
        'jenis',
        'nomor_cicilan',
        'jumlah',
        'tanggal_jatuh_tempo',
        'status',
        'pembayaran_konsumen_id',
    ];

    protected $casts = [
        'jumlah'               => 'decimal:2',
        'tanggal_jatuh_tempo'  => 'date',
    ];

    public function kavlingKonsumen(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class);
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(PembayaranKonsumen::class, 'pembayaran_konsumen_id');
    }

    public function getJenisLabelAttribute(): string
    {
        return match($this->jenis) {
            'booking_fee' => 'Booking Fee',
            'dp'          => 'DP',
            'pelunasan'   => 'Pelunasan',
            default       => $this->jenis,
        };
    }

    public function getIsTerlambatAttribute(): bool
    {
        return $this->status === 'belum_bayar' && $this->tanggal_jatuh_tempo->isPast();
    }
}
