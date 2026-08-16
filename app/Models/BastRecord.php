<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BastRecord extends Model
{
    use LogsActivity;

    protected $fillable = [
        'kavling_konsumen_id',
        'tanggal_bast',
        'penerima',
        'catatan',
        'dokumen_path',
        'status_ttd',
        'checklist',
        'created_by',
    ];

    protected $casts = [
        'tanggal_bast' => 'date',
        'checklist'    => 'array',
    ];

    public const CHECKLIST_ITEMS = [
        'listrik'  => 'Listrik aktif',
        'air'      => 'Air tersedia',
        'meteran'  => 'Meteran terpasang',
        'sanitair' => 'Sanitair lengkap',
        'pintu'    => 'Pintu lengkap',
        'jendela'  => 'Jendela lengkap',
        'kunci'    => 'Kunci tersedia',
        'siap'     => 'Bangunan siap serah terima',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $event) => "BAST telah di-{$event}");
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class, 'kavling_konsumen_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getChecklistCompleteAttribute(): bool
    {
        $checklist = $this->checklist ?? [];
        foreach (array_keys(self::CHECKLIST_ITEMS) as $key) {
            if (empty($checklist[$key])) return false;
        }
        return true;
    }
}
