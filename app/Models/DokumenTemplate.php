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
     * Apakah berkas ini berlaku untuk konsumen tertentu. Hanya relevan untuk kondisional: berlaku bila konsumen memenuhi SEMUA syarat
     * (pernikahan dan/atau pekerjaan). Kondisional TANPA syarat dianggap tidak berlaku (tidak dihitung); template seperti itu ditandai
     * agar Superadmin memberinya syarat atau mengubahnya jadi Opsional. Data konsumen yang syaratnya bergantung padanya tetapi masih
     * KOSONG (umum pada data lama hasil import) berarti "menunggu data": berkas belum dihitung dan baru menjadi wajib/tidak berlaku
     * setelah data dilengkapi (lihat menungguData()).
     */
    public function berlakuUntuk(?Konsumen $konsumen): bool
    {
        if ($this->sifat !== 'kondisional') return true;
        if (!$this->punyaSyarat()) return false;

        // Dimensi yang datanya diketahui dan tidak memenuhi syarat -> pasti tidak berlaku. Dimensi yang datanya kosong -> belum bisa dinilai.
        $syarat = ['status pernikahan' => [$this->syaratPernikahan(), $konsumen?->status_pernikahan], 'pekerjaan' => [$this->syaratPekerjaan(), $konsumen?->pekerjaan]];
        foreach ($syarat as [$daftar, $nilai]) {
            if (!$daftar) continue;
            if (blank($nilai)) return false;                       // menunggu data
            if (!in_array($nilai, $daftar, true)) return false;
        }

        return true;
    }

    /**
     * Data konsumen yang dibutuhkan syarat berkas ini tetapi masih kosong, mis. ['status pernikahan', 'pekerjaan']. Kosong bila tidak ada
     * yang ditunggu, atau bila berkas sudah pasti tidak berlaku karena data lain yang terisi tidak memenuhi syarat.
     */
    public function menungguData(?Konsumen $konsumen): array
    {
        if (!$this->punyaSyarat()) return [];
        $kosong = [];
        foreach (['status pernikahan' => [$this->syaratPernikahan(), $konsumen?->status_pernikahan], 'pekerjaan' => [$this->syaratPekerjaan(), $konsumen?->pekerjaan]] as $nama => [$daftar, $nilai]) {
            if (!$daftar) continue;
            if (blank($nilai)) $kosong[] = $nama;
            elseif (!in_array($nilai, $daftar, true)) return [];   // sudah pasti tidak berlaku
        }

        return $kosong;
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
