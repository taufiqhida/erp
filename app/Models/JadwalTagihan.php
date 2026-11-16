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

    /**
     * Jatuh tempo EFEKTIF dalam SQL: tanggal sendiri, kalau kosong mengikuti tanggal rencana akad transaksinya. Dipakai di semua query
     * yang menyaring/mengurutkan berdasarkan jatuh tempo (pasangan dari accessor tanggal_tempo di bawah).
     */
    public static function sqlTempoEfektif(): string
    {
        return 'COALESCE(jadwal_tagihan.tanggal_jatuh_tempo, (SELECT kk.tanggal_rencana_akad FROM kavling_konsumen kk WHERE kk.id = jadwal_tagihan.kavling_konsumen_id))';
    }

    /**
     * Jatuh tempo efektif: tanggal sendiri; bila kosong, tanggal rencana akad (cicilan "mengikuti rencana akad" ikut bergeser kalau
     * rencana akad diundur). Null = belum ada keduanya (menunggu rencana akad).
     */
    public function getTanggalTempoAttribute(): ?\Carbon\Carbon
    {
        return $this->tanggal_jatuh_tempo ?? $this->kavlingKonsumen?->tanggal_rencana_akad;
    }

    /** Cicilan tanpa tanggal sendiri: jatuh temponya mengikuti rencana akad (atau masih menunggu bila rencana akad belum ada). */
    public function getMengikutiRencanaAkadAttribute(): bool
    {
        return $this->tanggal_jatuh_tempo === null;
    }

    public function getJenisLabelAttribute(): string
    {
        return match($this->jenis) {
            'booking_fee' => 'Booking Fee',
            'dp'          => $this->kavlingKonsumen && in_array($this->kavlingKonsumen->cara_bayar, ['cash', 'cash_bertahap'], true) ? 'DP / Pengikatan' : 'DP',
            'pelunasan'   => 'Pelunasan',
            default       => $this->jenis,
        };
    }

    public function getIsTerlambatAttribute(): bool
    {
        // Tanpa tanggal sendiri dan rencana akad belum ada = menunggu: belum bisa dianggap terlambat.
        $tempo = $this->tanggal_tempo;

        return $this->status === 'belum_bayar' && $tempo !== null && $tempo->isPast();
    }
}
