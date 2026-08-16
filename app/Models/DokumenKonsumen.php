<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DokumenKonsumen extends Model
{
    protected $fillable = [
        'kavling_konsumen_id',
        'nama_dokumen',
        'sifat',
        'status',
        'file_path',
        'catatan',
        'catatan_revisi',
        'tanggal_upload',
        'tanggal_verifikasi',
        'verified_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_upload'     => 'datetime',
        'tanggal_verifikasi' => 'datetime',
    ];

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class, 'kavling_konsumen_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /* ---------------------------------------------------------------
     | Accessors
     --------------------------------------------------------------- */

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'belum_ada'    => 'Belum Ada',
            'sudah_ada'    => 'Sudah Ada',
            'perlu_revisi' => 'Perlu Revisi',
            'ditolak'      => 'Ditolak',
            default        => $this->status,
        };
    }

    public function getStatusIconAttribute(): string
    {
        return match($this->status) {
            'sudah_ada'    => '✅',
            'perlu_revisi' => '⚠️',
            'ditolak'      => '🚫',
            default        => '❌',
        };
    }

    public function getSifatLabelAttribute(): string
    {
        return DokumenTemplate::sifatLabel()[$this->sifat] ?? $this->sifat;
    }
}
