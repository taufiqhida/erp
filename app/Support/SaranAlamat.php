<?php

namespace App\Support;

use App\Models\Wilayah as WilayahModel;

/**
 * SARAN perapian alamat teks bebas: menebak wilayah yang tertulis di alamat dan sisa teks jalan/RT/RW. Hasilnya hanya diisikan ke file
 * unduhan Perbarui untuk DIPERIKSA manusia — tidak pernah disimpan otomatis. Bila lebih dari satu kemungkinan, dinyatakan "ambigu".
 */
class SaranAlamat
{
    private const PEMISAH = '/\s*[,;\r\n]+\s*|\s+(?=(?:kel|kelurahan|desa|ds|kec|kecamatan|kab|kabupaten|kota|prov|provinsi)(?:\.\s*|\s+))/iu';

    private ?array $kabupaten = null;   // inti => [kode, ...]
    private ?array $provinsi = null;    // inti => kode

    /** @return array{kode:?string, jalan:?string, keyakinan:string, prov:?string, kab:?string, kec:?string, kel:?string} */
    public function sarankan(string $teks): array
    {
        $kosong = ['kode' => null, 'jalan' => null, 'prov' => null, 'kab' => null, 'kec' => null, 'kel' => null];
        $segmen = preg_split(self::PEMISAH, trim($teks), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$segmen) return $kosong + ['keyakinan' => 'tidak ketemu — isi manual'];

        $inti = [];
        $penanda = [];   // tingkat yang ditandai awalan segmen (4 kel/desa, 3 kec, 2 kab/kota, 1 prov, 0 tanpa)
        foreach ($segmen as $i => $s) {
            $penanda[$i] = match (true) {
                (bool) preg_match('/^(kel|kelurahan|desa|ds)(\.|\s)/iu', $s) => 4,
                (bool) preg_match('/^(kec|kecamatan)(\.|\s)/iu', $s) => 3,
                (bool) preg_match('/^(kab|kabupaten|kota)(\.|\s)/iu', $s) => 2,
                (bool) preg_match('/^(prov|provinsi)(\.|\s)/iu', $s) => 1,
                default => 0,
            };
            $inti[$i] = Alamat::kunci(preg_replace('/\s*\b\d{5}\b\s*$/u', '', $s));   // kode pos di ujung diabaikan
        }

        // Cari segmen (selain yang sudah dipakai) yang intinya sama dengan nama dan penandanya tidak bertentangan.
        // Segmen berawalan "Kota"/"Kab." hanya cocok dengan kabupaten/kota berjenis sama ("Kota Semarang" bukan "Kabupaten Semarang").
        $cari = function (string $nama, int $tingkat, array $dipakai) use ($inti, $penanda, $segmen): ?int {
            $n = Alamat::kunci($nama);
            $namaKota = str_starts_with(mb_strtolower($nama), 'kota ');
            foreach ($inti as $i => $x) {
                if (in_array($i, $dipakai, true) || $x !== $n) continue;
                if ($penanda[$i] !== 0 && $penanda[$i] !== $tingkat) continue;
                if ($tingkat === 2 && $penanda[$i] === 2 && str_starts_with(mb_strtolower($segmen[$i]), 'kota') !== $namaKota) continue;

                return $i;
            }

            return null;
        };

        $namaSegmen = array_values(array_unique(array_filter($inti, fn ($x) => $x !== '' && !preg_match('/\d/', $x))));
        if (!$namaSegmen) return $kosong + ['keyakinan' => 'tidak ketemu — isi manual'];

        $calon = [];   // [skor, kode, segmen dipakai]
        // Nama dibandingkan tanpa spasi ("Gunung Pati" = "Gunungpati").
        $baris = WilayahModel::whereIn('level', [3, 4])
            ->whereRaw('REPLACE(nama, \' \', \'\') IN (' . implode(',', array_fill(0, count($namaSegmen), '?')) . ')', $namaSegmen)
            ->get(['kode', 'nama', 'level']);
        foreach ($this->kabupatenMenurutInti() as $n => $kodes) {
            if (!in_array($n, $namaSegmen, true)) continue;
            foreach ($kodes as $k) $baris->push(new WilayahModel(['kode' => $k, 'nama' => $n, 'level' => 2]));
        }

        $induk = $baris->flatMap(fn ($r) => Wilayah::rantai($r->kode))->unique()->values()->all();
        $nama = WilayahModel::whereIn('kode', $induk)->pluck('nama', 'kode');

        foreach ($baris as $r) {
            $rantai = Wilayah::rantai($r->kode);
            $level = count($rantai);
            $sendiri = $cari($nama[$r->kode] ?? $r->nama, $level, []);
            if ($sendiri === null) continue;

            $dipakai = [$sendiri];
            $cocok = [$level => true];
            for ($t = $level - 1; $t >= 1; $t--) {
                $i = $cari($nama[$rantai[$t - 1]] ?? '', $t, $dipakai);
                if ($i !== null) { $dipakai[] = $i; $cocok[$t] = true; }
            }

            // Syarat minimal supaya satu nama yang kebetulan sama tidak dianggap alamat.
            $diterima = match ($level) {
                4 => isset($cocok[3]) || ($penanda[$sendiri] === 4 && isset($cocok[2])),
                3 => isset($cocok[2]) || $penanda[$sendiri] === 3,
                default => $penanda[$sendiri] === 2 || isset($cocok[1]),
            };
            if (!$diterima) continue;

            $skor = (isset($cocok[4]) ? 4 : 0) + (isset($cocok[3]) ? 3 : 0) + (isset($cocok[2]) ? 2 : 0) + (isset($cocok[1]) ? 1 : 0);
            $calon[] = ['skor' => $skor, 'kode' => $r->kode, 'dipakai' => $dipakai];
        }

        if (!$calon) return $kosong + ['keyakinan' => 'tidak ketemu — isi manual'];
        $maks = max(array_column($calon, 'skor'));
        $terbaik = collect($calon)->where('skor', $maks)->unique('kode')->values();
        if ($terbaik->count() > 1) return $kosong + ['keyakinan' => 'ambigu — isi manual'];

        $pilih = $terbaik->first();
        $a = Wilayah::atribut($pilih['kode']);
        $sisa = collect($segmen)
            ->reject(fn ($s, $i) => in_array($i, $pilih['dipakai'], true) || preg_match('/^\s*\d{5}\s*$/u', $s))
            ->map(fn ($s) => trim($s, " \t,.;-"))->filter()->implode(', ');
        $level = count(Wilayah::rantai($pilih['kode']));

        return [
            'kode' => $pilih['kode'],
            'jalan' => $sisa !== '' ? $sisa : null,
            'prov' => $a['provinsi_nama'], 'kab' => $a['kabupaten_nama'], 'kec' => $a['kecamatan_nama'], 'kel' => $a['kelurahan_nama'],
            'keyakinan' => [4 => 'lengkap (sampai kelurahan)', 3 => 'sebagian (sampai kecamatan)', 2 => 'sebagian (sampai kabupaten/kota)'][$level] ?? 'sebagian',
        ];
    }

    private function kabupatenMenurutInti(): array
    {
        if ($this->kabupaten === null) {
            $this->kabupaten = [];
            foreach (WilayahModel::where('level', 2)->get(['kode', 'nama']) as $r) $this->kabupaten[Alamat::kunci($r->nama)][] = $r->kode;
        }

        return $this->kabupaten;
    }
}
