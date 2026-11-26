<?php

namespace App\Support;

/** Perangkai alamat tampil/cetak: "{teks bebas}, Kelurahan, Kecamatan, Kabupaten/Kota, Provinsi". Tidak pernah mengubah data tersimpan. */
class Alamat
{
    /** Pemisah segmen teks bebas: koma/titik koma/baris baru, atau sebelum penanda wilayah (Kel., Kec., Kab., Kota, Provinsi). */
    public const PEMISAH = '/\s*[,;\r\n]+\s*|\s+(?=(?:kel|kelurahan|kec|kecamatan|kab|kabupaten|kota|prov|provinsi)(?:\.\s*|\s+))/iu';

    /** Samakan bentuk nama untuk perbandingan: huruf kecil, tanpa awalan Kel./Kec./Desa/Kab./Kota/Prov., tanpa tanda baca tepi. */
    public static function inti(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/^(?:kelurahan|kecamatan|kabupaten|provinsi|desa|kota administrasi|kota adm\.?|kota|kel|kec|kab|prov|ds)(?:\.\s*|\s+)/u', '', $s);

        return trim(preg_replace('/\s+/u', ' ', $s), " \t.-:");
    }

    /** Kunci banding nama: inti tanpa spasi, supaya "Gunung Pati" sama dengan "Gunungpati". */
    public static function kunci(string $s): string
    {
        return str_replace(' ', '', self::inti($s));
    }

    /**
     * Gabungkan teks bebas dengan nama wilayah. Sebuah wilayah dianggap SUDAH tertulis hanya bila berdiri sebagai segmen sendiri
     * (bukan sekadar muncul di dalam nama jalan: "Jl. Semarang Raya" tidak menghilangkan "Kota Semarang").
     */
    public static function rangkai(?string $teks, array $wilayah): ?string
    {
        $teks = trim((string) $teks);
        $segmen = $teks === '' ? [] : array_map(self::kunci(...), preg_split(self::PEMISAH, $teks, -1, PREG_SPLIT_NO_EMPTY));

        $bagian = $teks === '' ? [] : [$teks];
        foreach ($wilayah as $nama) {
            if (blank($nama) || in_array(self::kunci($nama), $segmen, true)) continue;
            $bagian[] = $nama;
        }

        return $bagian ? implode(', ', $bagian) : null;
    }
}
