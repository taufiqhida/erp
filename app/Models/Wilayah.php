<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Data referensi wilayah Indonesia (read-only dari sisi aplikasi; dimuat dari CSV lewat `wilayah:impor`). */
class Wilayah extends Model
{
    protected $table = 'wilayah';
    protected $primaryKey = 'kode';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $guarded = [];
}
