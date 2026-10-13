<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan satu kali peralihan pembeli pada sebuah transaksi (dari siapa, ke siapa, alasan, siapa yang mengajukan dan menyetujui). */
class AlihPembeliRiwayat extends Model
{
    protected $fillable = [
        'kavling_konsumen_id', 'dari_konsumen_id', 'ke_konsumen_id', 'hubungan', 'alasan', 'tahap_sebelum',
        'cancellation_request_id', 'diajukan_oleh', 'disetujui_oleh', 'disetujui_at',
    ];

    protected $casts = ['disetujui_at' => 'datetime'];

    public static function hubunganLabel(): array
    {
        return [
            'pasangan'  => 'Pasangan (suami/istri)',
            'orang_tua' => 'Orang tua',
            'anak'      => 'Anak',
            'saudara'   => 'Saudara kandung',
            'kerabat'   => 'Kerabat lain',
            'lainnya'   => 'Lainnya',
        ];
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class, 'kavling_konsumen_id');
    }

    public function dari(): BelongsTo
    {
        return $this->belongsTo(Konsumen::class, 'dari_konsumen_id')->withTrashed();
    }

    public function ke(): BelongsTo
    {
        return $this->belongsTo(Konsumen::class, 'ke_konsumen_id')->withTrashed();
    }

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    /** Bentuk ringkas untuk tampilan (banner transaksi dan riwayat di profil konsumen). */
    public function untukTampil(): array
    {
        return [
            'id'            => $this->id,
            'transaksi_id'  => $this->kavling_konsumen_id,
            'dari_id'       => $this->dari_konsumen_id,
            'dari'          => $this->dari?->nama,
            'ke_id'         => $this->ke_konsumen_id,
            'ke'            => $this->ke?->nama,
            'hubungan'      => self::hubunganLabel()[$this->hubungan] ?? $this->hubungan,
            'alasan'        => $this->alasan,
            'tanggal'       => $this->disetujui_at?->format('d M Y'),
            'diajukan_oleh' => $this->pengaju?->name,
            'disetujui_oleh' => $this->penyetuju?->name,
            'unit'          => $this->transaksi?->kavling?->nomor_lengkap,
            'proyek'        => $this->transaksi?->kavling?->project?->nama,
        ];
    }
}
