<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PembiayaanProyek extends Model
{
    protected $fillable = [
        'project_id',
        'kpr_subsidi_config',
        'kpr_komersil_config',
    ];

    protected $casts = [
        'kpr_subsidi_config'  => 'array',
        'kpr_komersil_config' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Ambil konfigurasi default KPR Subsidi.
     */
    public static function defaultKprSubsidi(): array
    {
        return [
            ['key' => 'sbum',              'label' => 'SBUM',                     'jumlah' => 0],
            ['key' => 'dajam_sertifikat',  'label' => 'Dana Jaminan Sertifikat',  'jumlah' => 0],
            ['key' => 'dajam_air',         'label' => 'Dana Jaminan Air',         'jumlah' => 0],
            ['key' => 'dajam_listrik',     'label' => 'Dana Jaminan Listrik',     'jumlah' => 0],
        ];
    }

    /**
     * Ambil komponen subsidi untuk ditampilkan di form booking.
     */
    public function getSubsidiKomponenAttribute(): array
    {
        return $this->kpr_subsidi_config ?? self::defaultKprSubsidi();
    }
}
