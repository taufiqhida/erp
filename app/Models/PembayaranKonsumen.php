<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PembayaranKonsumen extends Model
{
    protected $fillable = [
        'kavling_konsumen_id',
        'jenis',
        'jumlah',
        'tanggal_bayar',
        'keterangan',
        'created_by',
    ];

    protected $casts = [
        'jumlah'       => 'decimal:2',
        'tanggal_bayar' => 'date',
    ];

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class, 'kavling_konsumen_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------------------------------------------------------------
     | Accessors
     --------------------------------------------------------------- */

    public function getJenisLabelAttribute(): string
    {
        return match($this->jenis) {
            'booking_fee'    => 'Booking Fee / UTJ',
            'dp'             => 'Uang Muka (DP)',
            'angsuran'       => 'Angsuran',
            'pelunasan'      => 'Pelunasan',
            'biaya_tanah'    => 'Biaya Penambahan Tanah',
            'biaya_tambahan' => 'Biaya Tambahan',
            'sbum'           => 'SBUM',
            'dajam'          => 'Dana Jaminan',
            'biaya_akad'     => 'Biaya Akad',
            'tambahan_um'    => 'Tambahan Uang Muka',
            default          => $this->jenis,
        };
    }
}
