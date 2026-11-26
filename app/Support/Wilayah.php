<?php

namespace App\Support;

use App\Models\Konsumen;
use App\Models\Wilayah as WilayahModel;

/**
 * Alamat terstruktur konsumen. Klien hanya mengirim SATU kode (tingkat terdalam yang dipilih, mis. kelurahan); server menurunkan
 * seluruh rantai (provinsi, kabupaten/kota, kecamatan, kelurahan) dan namanya dari tabel wilayah, jadi nama dari klien tidak pernah dipercaya.
 */
class Wilayah
{
    public const TINGKAT = ['provinsi', 'kabupaten', 'kecamatan', 'kelurahan'];

    /** Atribut konsumen (8 kolom) kosong: dipakai untuk menghapus wilayah. */
    public static function kosong(string $awalan = ''): array
    {
        $a = [];
        foreach (self::TINGKAT as $t) $a["{$awalan}{$t}_kode"] = $a["{$awalan}{$t}_nama"] = null;

        return $a;
    }

    /** Daftar kode leluhur dari kode (termasuk dirinya): 32.73.01 -> [32, 32.73, 32.73.01]. */
    public static function rantai(string $kode): array
    {
        $segmen = explode('.', $kode);
        $hasil = [];
        for ($i = 1; $i <= count($segmen); $i++) $hasil[] = implode('.', array_slice($segmen, 0, $i));

        return $hasil;
    }

    /** Atribut konsumen dari satu kode wilayah. Kode kosong menghasilkan atribut kosong; kode tak dikenal dilempar sebagai InvalidArgumentException. */
    public static function atribut(?string $kode, string $awalan = ''): array
    {
        $a = self::kosong($awalan);
        if ($kode === null || trim($kode) === '') return $a;

        $baris = WilayahModel::whereIn('kode', self::rantai(trim($kode)))->get()->keyBy('kode');
        if (!$baris->has(trim($kode))) throw new \InvalidArgumentException("Kode wilayah '{$kode}' tidak dikenal.");

        foreach (self::rantai(trim($kode)) as $i => $k) {
            $r = $baris->get($k);
            if (!$r) continue;
            $a[$awalan . self::TINGKAT[$i] . '_kode'] = $r->kode;
            $a[$awalan . self::TINGKAT[$i] . '_nama'] = $r->nama;
        }

        return $a;
    }

    /**
     * Atribut alamat domisili dari pilihan form: 'sama' (sama dengan KTP), 'beda' (alamat/wilayah sendiri), selain itu = belum diisi.
     * Pada 'sama' dan 'belum', kolom domisili_* dikosongkan supaya tidak tersisa data basi.
     */
    public static function domisili(?string $status, ?string $alamat = null, ?string $kode = null): array
    {
        if ($status === 'beda') {
            return ['domisili_sama_ktp' => false, 'domisili_alamat' => $alamat] + self::atribut($kode, 'domisili_');
        }

        return ['domisili_sama_ktp' => $status === 'sama' ? true : null, 'domisili_alamat' => null] + self::kosong('domisili_');
    }

    /** Status domisili untuk form: sama | beda | belum. */
    public static function statusDomisili(Konsumen $k): string
    {
        return match ($k->domisili_sama_ktp) { true => 'sama', false => 'beda', default => 'belum' };
    }

    /** Kode tingkat terdalam yang tersimpan pada konsumen (untuk mengisi ulang pemilih wilayah di form). */
    public static function kodeTerdalam(Konsumen $k, string $awalan = ''): ?string
    {
        return $k->{$awalan . 'kelurahan_kode'} ?? $k->{$awalan . 'kecamatan_kode'} ?? $k->{$awalan . 'kabupaten_kode'} ?? $k->{$awalan . 'provinsi_kode'};
    }

    /** Nama wilayah tersimpan berurutan [kelurahan, kecamatan, kabupaten, provinsi] (null untuk tingkat yang kosong). */
    public static function namaTersimpan(Konsumen $k, string $awalan = ''): array
    {
        return array_map(fn ($t) => $k->{$awalan . $t . '_nama'}, ['kelurahan', 'kecamatan', 'kabupaten', 'provinsi']);
    }

    /** "Kelurahan, Kecamatan, Kabupaten, Provinsi" dari nama yang tersimpan; null bila belum ada wilayah. */
    public static function ringkas(Konsumen $k, string $awalan = ''): ?string
    {
        $bagian = array_filter(self::namaTersimpan($k, $awalan));

        return $bagian ? implode(', ', $bagian) : null;
    }

    /** Aturan validasi untuk kolom `wilayah_kode`: kosong atau kode yang ada di tabel wilayah. */
    public static function aturan(): array
    {
        return ['nullable', 'string', 'max:16', 'exists:wilayah,kode'];
    }

    private static function normal(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));

        return preg_replace(['/^kab\.?\s+/u', '/^kota adm\.?\s+/u', '/^kota administrasi\s+/u'], ['kabupaten ', 'kota ', 'kota '], $s);
    }

    private static function tanpaAwalan(string $s): string
    {
        return preg_replace('/^(kabupaten|kota)\s+/u', '', self::normal($s));
    }

    /**
     * Cari satu wilayah anak dari $induk (null = provinsi) berdasarkan nama. Cocok persis (tanpa memedulikan huruf besar/kecil) lebih dulu;
     * bila tidak ada, cocok tanpa awalan "Kabupaten/Kota" asal hanya satu. Mengembalikan [kode|null, pesan error|null].
     */
    public static function cariNama(string $nama, ?string $induk, string $label): array
    {
        $anak = WilayahModel::query()->when($induk === null, fn ($q) => $q->where('level', 1), fn ($q) => $q->where('induk', $induk))->get(['kode', 'nama']);

        $persis = $anak->filter(fn ($r) => self::normal($r->nama) === self::normal($nama));
        if ($persis->count() === 1) return [$persis->first()->kode, null];

        $longgar = $anak->filter(fn ($r) => self::tanpaAwalan($r->nama) === self::tanpaAwalan($nama));
        if ($longgar->count() === 1) return [$longgar->first()->kode, null];
        if ($longgar->count() > 1) {
            return [null, "{$label} '{$nama}' ambigu: " . $longgar->pluck('nama')->implode(' / ') . '. Tulis nama lengkapnya (dengan "Kabupaten"/"Kota").'];
        }

        return [null, "{$label} '{$nama}' tidak dikenal" . ($induk ? ' di wilayah induknya.' : '.')];
    }

    /**
     * Cocokkan empat nama (teks dari Excel) menjadi satu kode terdalam. Tingkat bawah hanya boleh diisi bila tingkat di atasnya terisi.
     * Mengembalikan [kode|null, error[]].
     */
    public static function cocokkan(?string $provinsi, ?string $kabupaten, ?string $kecamatan, ?string $kelurahan): array
    {
        $nama = [$provinsi, $kabupaten, $kecamatan, $kelurahan];
        $label = ['Provinsi', 'Kabupaten/Kota', 'Kecamatan', 'Kelurahan/Desa'];
        $isi = array_map(fn ($n) => $n !== null && trim($n) !== '', $nama);
        if (!in_array(true, $isi, true)) return [null, []];

        $errors = [];
        for ($i = 1; $i < 4; $i++) {
            if ($isi[$i] && !$isi[$i - 1]) $errors[] = "{$label[$i]} diisi tetapi {$label[$i - 1]} kosong. Isi wilayah dari tingkat paling atas.";
        }
        if ($errors) return [null, $errors];

        $kode = null;
        foreach ($nama as $i => $n) {
            if (!$isi[$i]) break;
            [$ketemu, $pesan] = self::cariNama(trim($n), $kode, $label[$i]);
            if ($pesan) return [null, [$pesan]];
            $kode = $ketemu;
        }

        return [$kode, []];
    }
}
