<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SbumRecord extends Model
{
    protected $fillable = [
        'kavling_konsumen_id',
        'jumlah_sbum',
        'status',
        'tanggal_pengajuan',
        'tanggal_cair',
        'catatan',
    ];

    protected $casts = [
        'jumlah_sbum'       => 'decimal:2',
        'tanggal_pengajuan' => 'date',
        'tanggal_cair'      => 'date',
    ];

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class, 'kavling_konsumen_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'belum'   => 'Belum Diajukan',
            'proses'  => 'Dalam Proses',
            'cair'    => 'Sudah Cair',
            'ditolak' => 'Ditolak',
            default   => $this->status,
        };
    }
}
