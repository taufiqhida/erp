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
        'catatan',
        'status_ttd',
        'created_by',
    ];

    protected $casts = [
        'tanggal_bast' => 'date',
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
}
