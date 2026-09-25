<?php

namespace App\Models;

use App\Enums\StatusJual;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Kavling extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'project_id',
        'tipe_unit_preset_id',
        'kluster',
        'nomor_kavling',
        'blok',
        'harga',
        'status_jual',
        'status_bangun_stage_id',
        'status_bangun_persen',
        'status_unit',
        'keterangan',
        'perlu_biaya_tambahan',
        'koordinat_x',
        'koordinat_y',
        'catatan',
        'id_rumah',
        'hgb_no',
    ];

    protected $casts = [
        'harga'                 => 'decimal:2',
        'status_bangun_persen'  => 'decimal:2',
        'koordinat_x'           => 'decimal:3',
        'koordinat_y'           => 'decimal:3',
        'status_jual'           => StatusJual::class,
        'perlu_biaya_tambahan'  => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(
                fn(string $event) => "Kavling {$this->nomor_kavling} (Blok {$this->blok}) telah di-{$event}"
            );
    }

    public function scopeByKluster($query, string $kluster)
    {
        return $query->where('kluster', $kluster);
    }

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tipeUnitPreset(): BelongsTo
    {
        return $this->belongsTo(TipeUnitPreset::class);
    }

    public function statusBangunStage(): BelongsTo
    {
        return $this->belongsTo(StatusBangunStage::class);
    }

    public function kavlingKonsumens(): HasMany
    {
        return $this->hasMany(KavlingKonsumen::class);
    }

    /** Transaksi aktif (konsumen saat ini) */
    public function activeTransaction(): HasOne
    {
        return $this->hasOne(KavlingKonsumen::class)->where('status', 'active')->latest();
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class);
    }

    public function spks(): BelongsToMany
    {
        return $this->belongsToMany(Spk::class, 'spk_kavling');
    }

    /* ---------------------------------------------------------------
     | Scopes
     --------------------------------------------------------------- */

    public function scopeAvailable($query)
    {
        return $query->where('status_jual', StatusJual::Available);
    }

    public function scopeByProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByStatusJual($query, string $status)
    {
        return $query->where('status_jual', $status);
    }

    /* ---------------------------------------------------------------
     | Accessors
     --------------------------------------------------------------- */

    /**
     * Urutan alami unit: kluster, lalu blok, lalu nomor. Tiap bagian diurutkan
     * per awalan huruf lalu angkanya sebagai ANGKA, supaya "2" sebelum "10"
     * dan "A2" sebelum "A10" (kolomnya teks, jadi urutan alfabet biasa
     * mengurutkan "10" sebelum "2"). Butuh MySQL 8 / MariaDB (REGEXP_SUBSTR).
     */
    public function scopeOrderByUnit($query, string $dir = 'asc')
    {
        return static::applyUnitOrder($query, $dir);
    }

    /** Sama seperti scopeOrderByUnit, tapi bisa dipakai di query tabel lain yang sudah join ke kavlings. */
    public static function applyUnitOrder($query, string $dir = 'asc')
    {
        $dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
        $natural = fn (string $col) => [
            "REGEXP_SUBSTR({$col}, '^[^0-9]*')",
            "CAST(NULLIF(REGEXP_SUBSTR({$col}, '[0-9]+'), '') AS UNSIGNED)",
            $col,
        ];

        $query->orderBy('kluster_key', $dir);
        foreach (['blok_key', 'nomor_kavling'] as $col) {
            foreach ($natural($col) as $expr) $query->orderByRaw("{$expr} {$dir}");
        }

        return $query;
    }

    /** Pengenal unit tanpa kluster: "{blok}-{nomor}" (mis. "A1-1"). */
    public function getNomorUnitAttribute(): string
    {
        return $this->blok ? "{$this->blok}-{$this->nomor_kavling}" : $this->nomor_kavling;
    }

    /** Teks unit di layar: "A1-1", atau "Melati · A1-1" kalau proyeknya punya kluster. */
    public function getNomorLengkapAttribute(): string
    {
        return $this->kluster ? "{$this->kluster} · {$this->nomor_unit}" : $this->nomor_unit;
    }

    /**
     * ID unik untuk matching elemen di file siteplan SVG: "{blok}-{nomor}"
     * (mis. "A1-1"), atau "{kluster}-{blok}-{nomor}" untuk unit ber-kluster
     * supaya tidak bentrok antar kluster. Admin yang menyiapkan file SVG
     * siteplan harus memberi atribut id persis nilai ini pada tiap bentuk kavling.
     */
    public function getSvgIdAttribute(): string
    {
        return $this->kluster ? "{$this->kluster}-{$this->nomor_unit}" : $this->nomor_unit;
    }

    /**
     * Identitas unit = kluster + blok + nomor per proyek (kluster boleh
     * kosong). Termasuk yang sudah di-soft-delete karena indeks unik di DB
     * juga menghitungnya.
     */
    public static function identitasExists(int $projectId, ?string $kluster, ?string $blok, string $nomor, ?int $ignoreId = null): bool
    {
        return static::withTrashed()
            ->where('project_id', $projectId)
            ->where('kluster_key', $kluster ?? '')
            ->where('blok_key', $blok ?? '')
            ->where('nomor_kavling', $nomor)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function getStatusJualLabelAttribute(): string
    {
        return $this->status_jual->label();
    }

    public function getStatusBangunLabelAttribute(): string
    {
        return $this->statusBangunStage?->nama ?? '-';
    }

    public function getProgressBangunAttribute(): float
    {
        return StatusBangunStage::progressFor($this->status_bangun_stage_id, (float) $this->status_bangun_persen);
    }

    /**
     * Bangunan siap serah terima = sudah di tahap terakhir DAN tahap itu 100%.
     * (Tahap terakhir saja tidak cukup: unit yang baru mulai tahap terakhir belum siap.)
     */
    public function getBangunSelesaiAttribute(): bool
    {
        return (bool) ($this->statusBangunStage?->isFinalStage()) && (float) $this->status_bangun_persen >= 100;
    }

    /**
     * SPK terbaru (berdasarkan tanggal_terbit) yang mencakup kavling ini —
     * dipakai buat tampilkan kontraktor & deadline aktif di halaman Proses
     * Bangun, bukan kolom tersimpan supaya riwayat SPK lama tetap ada kalau
     * ada SPK susulan/revisi. Pakai relasi ter-eager-load kalau tersedia
     * (hindari N+1 di listing), fallback query langsung kalau belum.
     */
    public function getSpkAktifAttribute(): ?Spk
    {
        if ($this->relationLoaded('spks')) {
            return $this->spks->sortByDesc('tanggal_terbit')->first();
        }

        return $this->spks()->orderByDesc('tanggal_terbit')->orderByDesc('spks.id')->first();
    }
}
