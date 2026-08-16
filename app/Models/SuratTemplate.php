<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SuratTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nama',
        'subjek',
        'isi',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Daftar placeholder yang tersedia untuk template surat.
     */
    public static function availablePlaceholders(): array
    {
        return [
            '{{nama_konsumen}}'    => 'Nama Konsumen',
            '{{no_hp_konsumen}}'   => 'No. HP Konsumen',
            '{{nik_konsumen}}'     => 'NIK Konsumen',
            '{{alamat_konsumen}}'  => 'Alamat Konsumen',
            '{{nama_proyek}}'      => 'Nama Proyek',
            '{{kode_proyek}}'      => 'Kode Proyek',
            '{{nomor_kavling}}'    => 'Nomor Kavling',
            '{{blok_kavling}}'     => 'Blok Kavling',
            '{{harga_deal}}'       => 'Harga Deal',
            '{{cara_bayar}}'       => 'Cara Pembayaran',
            '{{tanggal_akad}}'     => 'Tanggal Akad',
            '{{nama_developer}}'   => 'Nama Developer',
            '{{alamat_developer}}' => 'Alamat Developer',
            '{{tanggal_hari_ini}}' => 'Tanggal Hari Ini',
        ];
    }

    /**
     * Render template dengan data konsumen/kavling.
     */
    public function render(array $data): string
    {
        $content = $this->isi;
        foreach ($data as $placeholder => $value) {
            $content = str_replace('{{' . $placeholder . '}}', $value, $content);
        }
        return $content;
    }
}
