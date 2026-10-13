<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DokumenTemplate extends Model
{
    protected $fillable = [
        'cara_bayar',
        'nama_dokumen',
        'sifat',
        'syarat_pernikahan',
        'syarat_pekerjaan',
        'urutan',
    ];

    protected $casts = [
        'urutan' => 'integer',
    ];

    public function scopeForCaraBayar($query, string $caraBayar)
    {
        return $query->where('cara_bayar', $caraBayar)->orderBy('urutan');
    }

    public static function caraBayarLabel(): array
    {
        return [
            'cash'          => 'Cash',
            'cash_bertahap' => 'Cash Bertahap',
            'kpr_subsidi'   => 'KPR Subsidi',
            'kpr_komersil'  => 'KPR Komersil',
        ];
    }

    public static function sifatLabel(): array
    {
        return [
            'wajib'       => 'Wajib',
            'kondisional' => 'Kondisional',
            'opsional'    => 'Opsional',
        ];
    }

    public function getSifatLabelAttribute(): string
    {
        return self::sifatLabel()[$this->sifat] ?? $this->sifat;
    }

    /** Daftar kunci syarat (disimpan dipisah koma di DB). */
    public function syaratPernikahan(): array
    {
        return self::pecahSyarat($this->syarat_pernikahan);
    }

    public function syaratPekerjaan(): array
    {
        return self::pecahSyarat($this->syarat_pekerjaan);
    }

    /** Terima array atau string "a,b"; simpan sebagai string dipisah koma, null bila kosong. */
    public function setSyaratPernikahanAttribute($value): void
    {
        $this->attributes['syarat_pernikahan'] = self::gabungSyarat($value);
    }

    public function setSyaratPekerjaanAttribute($value): void
    {
        $this->attributes['syarat_pekerjaan'] = self::gabungSyarat($value);
    }

    private static function pecahSyarat(?string $nilai): array
    {
        return $nilai === null || $nilai === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $nilai))));
    }

    private static function gabungSyarat($nilai): ?string
    {
        $daftar = is_array($nilai) ? $nilai : (is_string($nilai) ? explode(',', $nilai) : []);
        $daftar = array_values(array_unique(array_filter(array_map('trim', $daftar))));

        return $daftar ? implode(',', $daftar) : null;
    }

    /** Berkas kondisional yang punya syarat otomatis (ditentukan dari data konsumen). */
    public function punyaSyarat(): bool
    {
        return $this->sifat === 'kondisional' && ($this->syaratPernikahan() || $this->syaratPekerjaan());
    }

    /**
     * Apakah berkas ini berlaku untuk konsumen tertentu. null = tidak ditentukan otomatis (kondisional tanpa syarat → diatur
     * manual per konsumen). Kedua syarat (pernikahan dan pekerjaan) harus terpenuhi bila keduanya diisi. Data konsumen yang
     * masih kosong dianggap berlaku — lebih baik berkas ditagih daripada terlewat karena data belum dilengkapi.
     */
    public function berlakuUntuk(?Konsumen $konsumen): ?bool
    {
        if ($this->sifat !== 'kondisional') return true;
        if (!$this->punyaSyarat()) return null;

        $cocok = fn (array $syarat, ?string $nilai) => !$syarat || $nilai === null || $nilai === '' || in_array($nilai, $syarat, true);

        return $cocok($this->syaratPernikahan(), $konsumen?->status_pernikahan) && $cocok($this->syaratPekerjaan(), $konsumen?->pekerjaan);
    }

    /** Teks ringkas syarat untuk tampilan, mis. "Menikah · Wirausaha". */
    public function syaratLabel(): ?string
    {
        if ($this->sifat !== 'kondisional') return null;
        $a = array_map(fn ($k) => Konsumen::statusPernikahanLabel()[$k] ?? $k, $this->syaratPernikahan());
        $b = array_map(fn ($k) => Konsumen::jenisPekerjaanLabel()[$k] ?? $k, $this->syaratPekerjaan());
        $bagian = array_filter([implode(' / ', $a), implode(' / ', $b)]);

        return $bagian ? implode(' · ', $bagian) : null;
    }
}
