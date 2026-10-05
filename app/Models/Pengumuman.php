<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Pengumuman extends Model
{
    protected $table = 'pengumuman';

    protected $fillable = ['judul', 'isi', 'tanggal', 'disematkan', 'aktif', 'created_by'];

    protected function casts(): array
    {
        return [
            'tanggal'    => 'date',
            'disematkan' => 'boolean',
            'aktif'      => 'boolean',
        ];
    }

    /** Urutan tampil: yang disematkan dulu, lalu paling baru. */
    public function scopeUrut(Builder $query): Builder
    {
        return $query->orderByDesc('disematkan')->orderByDesc('tanggal')->orderByDesc('id');
    }

    /** Pengumuman yang tampil di halaman login (publik, sebelum login). */
    public function scopeUntukLogin(Builder $query): Builder
    {
        return $query->where('aktif', true)->urut()->limit(20);
    }
}
