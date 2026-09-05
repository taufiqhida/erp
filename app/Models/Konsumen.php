<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Konsumen extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'nama',
        'nik',
        'no_hp',
        'email',
        'alamat',
        'pekerjaan',
        'status_pernikahan',
        'sumber_lead_id',
        'catatan',
        'drive_folder_link',
    ];

    public static function jenisPekerjaanLabel(): array
    {
        return [
            'karyawan_swasta'          => 'Karyawan Swasta',
            'pns_asn_tni_polri_bumn'   => 'PNS / ASN / TNI / Polri / BUMN',
            'wirausaha'                => 'Wirausaha',
            'freelance'                => 'Freelance',
        ];
    }

    public static function statusPernikahanLabel(): array
    {
        return [
            'belum_menikah' => 'Belum Menikah',
            'menikah'       => 'Menikah',
            'cerai_hidup'   => 'Cerai Hidup',
            'cerai_mati'    => 'Cerai Mati',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn(string $event) => "Konsumen {$this->nama} telah di-{$event}");
    }

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function kavlingKonsumens(): HasMany
    {
        return $this->hasMany(KavlingKonsumen::class);
    }

    public function sumberLead(): BelongsTo
    {
        return $this->belongsTo(SumberLead::class);
    }

    /** Kavling yang aktif dimiliki konsumen */
    public function activeKavlings()
    {
        return $this->kavlingKonsumens()
            ->where('status', 'active')
            ->with('kavling.project');
    }

    /* ---------------------------------------------------------------
     | Scopes
     --------------------------------------------------------------- */

    public function scopeSearch($query, ?string $keyword)
    {
        if (!$keyword) return $query;

        return $query->where(function ($q) use ($keyword) {
            $q->where('nama', 'like', "%{$keyword}%")
              ->orWhere('nik', 'like', "%{$keyword}%")
              ->orWhere('no_hp', 'like', "%{$keyword}%")
              ->orWhere('email', 'like', "%{$keyword}%");
        });
    }

    /* ---------------------------------------------------------------
     | Accessors
     --------------------------------------------------------------- */

    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->nama);
        return strtoupper(
            count($words) >= 2
                ? $words[0][0] . $words[1][0]
                : substr($this->nama, 0, 2)
        );
    }
}
