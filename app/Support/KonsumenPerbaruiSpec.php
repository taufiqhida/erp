<?php

namespace App\Support;

/**
 * Template ramping "Perbarui Data Konsumen": menyusulkan data yang belum terisi pada konsumen/transaksi yang SUDAH ada.
 * Kolom dibaca berdasar POSISI (sama seperti Import Konsumen). Tidak ada kolom keuangan sama sekali, jadi angka tidak mungkin tertimpa.
 */
class KonsumenPerbaruiSpec
{
    public const SHEET = 'Perbarui';

    /** @return array<int, array{0:string,1:string,2:string,3:string}> [key, label, req(w|o), keterangan] */
    public static function columns(): array
    {
        return [
            ['kluster', 'Kluster', 'o', 'Kunci unit. Kosongkan kalau proyek tidak punya kluster.'],
            ['blok', 'Blok', 'w', 'Kunci unit: harus sama dengan Stok Kavling.'],
            ['unit', 'Nomor Kavling', 'w', 'Kunci unit: harus sama dengan Stok Kavling. Unit harus sudah punya transaksi.'],
            ['nama', 'Nama Konsumen', 'w', 'Untuk VERIFIKASI: harus cocok dengan konsumen di unit itu. Kalau tidak cocok, barisnya dilewati (mencegah data masuk ke orang yang salah).'],
            ['nik', 'NIK', 'o', '16 digit. Hanya MENGISI NIK yang masih kosong atau tidak valid. NIK yang sudah valid tidak diganti (bila berbeda, jadi peringatan). Format kolom ini harus Teks.'],
            ['hp', 'No. HP', 'o', 'Hanya mengisi yang masih kosong.'],
            ['email', 'Email', 'o', 'Hanya mengisi yang masih kosong.'],
            ['npwp', 'NPWP', 'o', '15 atau 16 digit. Hanya mengisi yang masih kosong.'],
            ['alamat', 'Alamat', 'o', 'Jalan, RT/RW, nomor rumah (teks bebas). Hanya mengisi yang masih kosong.'],
            ['prov', 'Provinsi', 'o', 'Dropdown. Wilayah diisi dari tingkat paling atas. Hanya mengisi yang masih kosong (atau melengkapi tingkat yang lebih dalam).'],
            ['kab', 'Kabupaten/Kota', 'o', 'Tulis nama lengkap sesuai daftar resmi, mis. "Kota Bandung" atau "Kabupaten Bandung". Wajib diisi bila Kecamatan diisi.'],
            ['kec', 'Kecamatan', 'o', 'Nama kecamatan di kabupaten/kota tersebut. Wajib diisi bila Kelurahan/Desa diisi.'],
            ['kel', 'Kelurahan/Desa', 'o', 'Nama kelurahan/desa di kecamatan tersebut.'],
            ['kerja', 'Pekerjaan', 'o', 'Dropdown. Hanya mengisi yang masih kosong.'],
            ['nikah', 'Status Pernikahan', 'o', 'Dropdown. Hanya mengisi yang masih kosong.'],
            ['lead', 'Sumber Lead', 'o', 'Dropdown. Hanya mengisi yang masih kosong.'],
            ['referral', 'Keterangan Referral', 'o', 'Wajib kalau Sumber Lead bertanda referral dan belum ada keterangannya.'],
            ['sales', 'Sales / Agent', 'o', 'Dropdown. Hanya mengisi yang masih kosong. Perlu izin "kelola pipeline sales".'],
            ['notaris', 'Notaris', 'o', 'Dropdown. Hanya mengisi yang masih kosong. Perlu izin "kelola pipeline sales".'],
            ['bank', 'Bank Rekanan KPR', 'o', 'Dropdown. Hanya untuk transaksi KPR, hanya mengisi yang masih kosong. Perlu izin "isi bank rekanan kpr".'],
        ];
    }

    /** Daftar isi dropdown (sheet "Daftar") — subset dari KonsumenImportSpec::lists(). */
    public static function lists(): array
    {
        $semua = KonsumenImportSpec::lists();

        $hasil = array_intersect_key($semua, array_flip(['Pekerjaan', 'Status Pernikahan', 'Sumber Lead', 'Sales / Agent', 'Notaris', 'Bank Rekanan']));
        $hasil['Provinsi'] = \App\Models\Wilayah::where('level', 1)->orderBy('nama')->pluck('nama')->all();

        return $hasil;
    }

    public static function dropdownListFor(string $key): ?string
    {
        return [
            'kerja' => 'Pekerjaan', 'nikah' => 'Status Pernikahan', 'lead' => 'Sumber Lead',
            'sales' => 'Sales / Agent', 'notaris' => 'Notaris', 'bank' => 'Bank Rekanan', 'prov' => 'Provinsi',
        ][$key] ?? null;
    }

    /** @return string[] daftar masalah header; kosong bila file cocok dengan template saat ini. */
    public static function validasiHeader(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $ss = $reader->load($path);

        $sheet = $ss->getSheetByName(self::SHEET);
        if (!$sheet) {
            return ["Sheet \"" . self::SHEET . "\" tidak ditemukan. Unduh template Perbarui dari dialog Import Konsumen (mode Perbarui) dan isi sheet itu."];
        }

        $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim(preg_replace('/\s*[*†]+\s*$/u', '', trim((string) $s)))));
        $kolom = self::columns();
        $beda = [];
        foreach ($kolom as $i => $c) {
            $dapat = $norm($sheet->getCell([$i + 1, 1])->getValue());
            if ($dapat !== $norm($c[1])) {
                $huruf = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $beda[] = "kolom {$huruf}: file berisi " . ($dapat === '' ? '(kosong)' : '"' . $sheet->getCell([$i + 1, 1])->getValue() . '"') . ", seharusnya \"{$c[1]}\"";
            }
        }
        $lebih = $norm($sheet->getCell([count($kolom) + 1, 1])->getValue());
        if ($lebih !== '') $beda[] = 'ada kolom tambahan setelah "' . end($kolom)[1] . '"';

        return $beda ? ['[' . self::SHEET . '] Header tidak sesuai template: ' . implode('; ', array_slice($beda, 0, 3)) . (count($beda) > 3 ? '; … (' . (count($beda) - 3) . ' lainnya)' : '') . '.'] : [];
    }
}
