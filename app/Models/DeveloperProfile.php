<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeveloperProfile extends Model
{
    protected $fillable = [
        'nama_developer',
        'alamat',
        'telepon',
        'email',
        'npwp',
        'nama_bank',
        'nomor_rekening',
        'atas_nama_rekening',
        'logo_path',
        'kop_surat_path',
    ];

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
