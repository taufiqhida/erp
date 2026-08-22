<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperProfile extends Model
{
    protected $fillable = [
        'nama_developer',
        'alamat',
        'telepon',
        'email',
        'npwp',
        'logo_path',
        'kop_surat_path',
    ];

    public function banks(): HasMany
    {
        return $this->hasMany(DeveloperProfileBank::class);
    }

    /**
     * Ambil (atau buat) satu-satunya record profil developer.
     */
    public static function getSingleton(): self
    {
        return self::firstOrCreate(['id' => 1], [
            'nama_developer' => 'Nama Developer',
        ]);
    }
}
