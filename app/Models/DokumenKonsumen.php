<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DokumenKonsumen extends Model
{
    protected $fillable = [
        'kavling_konsumen_id',
        'dokumen_template_id',
        'nama_dokumen',
        'sifat',
        'berlaku',
        'status',
        'catatan',
        'catatan_revisi',
        'tanggal_verifikasi',
        'verified_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_verifikasi' => 'datetime',
        'berlaku'            => 'boolean',
    ];

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class, 'kavling_konsumen_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DokumenTemplate::class, 'dokumen_template_id');
    }

    /**
     * Ikut dihitung di progress & syarat lanjut tahap: berkas wajib, dan kondisional yang berlaku untuk konsumen ini.
     * Opsional dan kondisional yang tidak berlaku tidak dihitung.
     */
    public function getDihitungAttribute(): bool
    {
        return $this->sifat === 'wajib' || ($this->sifat === 'kondisional' && $this->berlaku);
    }

    /** Kondisional yang tidak berlaku untuk konsumen ini (tampil abu-abu). */
    public function getTidakBerlakuAttribute(): bool
    {
        return $this->sifat === 'kondisional' && !$this->berlaku;
    }

    /** Atribut berkas baru dari template untuk konsumen tertentu (status awal Belum Ada). */
    public static function atributDariTemplate(DokumenTemplate $template, ?Konsumen $konsumen, string $status = 'belum_ada'): array
    {
        return [
            'dokumen_template_id' => $template->id,
            'nama_dokumen'        => $template->nama_dokumen,
            'sifat'               => $template->sifat,
            'berlaku'             => $template->berlakuUntuk($konsumen),
            'status'              => $status,
        ];
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
