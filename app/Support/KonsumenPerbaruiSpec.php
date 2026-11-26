<?php

namespace App\Support;

use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Wilayah as WilayahModel;

/**
 * Template "Perbarui Data Konsumen". Kolom dikenali lewat JUDUL (bukan posisi) karena susunannya mengikuti kelompok data yang dipilih
 * saat mengunduh. Tidak ada kolom keuangan sama sekali, jadi angka tidak mungkin tertimpa. Judul tak dikenal menolak seluruh file.
 */
class KonsumenPerbaruiSpec
{
    public const SHEET = 'Perbarui';

    /** Selalu ada: pengenal unit + verifikasi konsumen. */
    public const IDENTITAS = ['kluster', 'blok', 'unit', 'nama', 'nik'];

    /** kelompok => [label, kolom] */
    public const KELOMPOK = [
        'kontak'    => ['Kontak (No. HP, Email, NPWP)', ['hp', 'email', 'npwp']],
        'pekerjaan' => ['Pekerjaan & Status Pernikahan', ['kerja', 'nikah']],
        'ktp'       => ['Alamat KTP & wilayah', ['alamat', 'prov', 'kab', 'kec', 'kel']],
        'domisili'  => ['Alamat Domisili', ['dom_sama', 'dom_alamat', 'dom_prov', 'dom_kab', 'dom_kec', 'dom_kel']],
        'notaris'   => ['Notaris', ['notaris']],
        'penjualan' => ['Penjualan (Sumber Lead, Sales, Bank)', ['lead', 'referral', 'sales', 'bank']],
    ];

    /** Hanya keterangan/pembanding di file unduhan — tidak pernah dibaca importer. */
    public const REFERENSI = ['ref_alamat', 'ref_keyakinan', 'ref_status'];

    /** key => [judul kolom, wajib (w|o), keterangan] */
    public static function definisi(): array
    {
        return [
            'kluster' => ['Kluster', 'o', 'Pengenal unit. Kosongkan kalau proyek tidak punya kluster.'],
            'blok' => ['Blok', 'w', 'Pengenal unit: harus sama dengan Stok Kavling.'],
            'unit' => ['Nomor Kavling', 'w', 'Pengenal unit: harus sama dengan Stok Kavling.'],
            'nama' => ['Nama Konsumen', 'w', 'VERIFIKASI: harus cocok dengan konsumen di unit itu. Tidak bisa diubah lewat import.'],
            'nik' => ['NIK', 'o', '16 digit. Hanya mengisi NIK kosong atau tidak valid; NIK yang sudah valid tidak pernah diganti.'],
            'hp' => ['No. HP', 'o', ''],
            'email' => ['Email', 'o', ''],
            'npwp' => ['NPWP', 'o', '15 atau 16 digit.'],
            'kerja' => ['Pekerjaan', 'o', 'Dropdown.'],
            'nikah' => ['Status Pernikahan', 'o', 'Dropdown.'],
            'alamat' => ['Alamat KTP', 'o', 'Jalan, RT/RW, nomor rumah. Kelurahan sampai provinsi diisi di kolom wilayah, tidak perlu diulang di sini.'],
            'prov' => ['Provinsi KTP', 'o', 'Dropdown.'],
            'kab' => ['Kabupaten/Kota KTP', 'o', 'Nama lengkap, mis. "Kota Semarang" atau "Kabupaten Semarang".'],
            'kec' => ['Kecamatan KTP', 'o', ''],
            'kel' => ['Kelurahan/Desa KTP', 'o', ''],
            'dom_sama' => ['Domisili Sama KTP', 'o', 'Ya atau Tidak. Kalau Ya, kolom domisili lain harus kosong.'],
            'dom_alamat' => ['Alamat Domisili', 'o', 'Diisi bila domisili berbeda dari KTP.'],
            'dom_prov' => ['Provinsi Domisili', 'o', 'Dropdown.'],
            'dom_kab' => ['Kabupaten/Kota Domisili', 'o', ''],
            'dom_kec' => ['Kecamatan Domisili', 'o', ''],
            'dom_kel' => ['Kelurahan/Desa Domisili', 'o', ''],
            'notaris' => ['Notaris', 'o', 'Dropdown. Perlu izin "kelola pipeline sales".'],
            'lead' => ['Sumber Lead', 'o', 'Dropdown.'],
            'referral' => ['Keterangan Referral', 'o', 'Wajib bila Sumber Lead bertanda referral.'],
            'sales' => ['Sales / Agent', 'o', 'Dropdown. Perlu izin "kelola pipeline sales".'],
            'bank' => ['Bank Rekanan KPR', 'o', 'Dropdown. Hanya transaksi KPR. Perlu izin "isi bank rekanan kpr".'],
            'ref_alamat' => ['Alamat Asli (referensi, tidak dibaca)', 'o', 'Alamat sebelum dirapikan, untuk pembanding.'],
            'ref_keyakinan' => ['Keyakinan Saran (referensi, tidak dibaca)', 'o', 'Seberapa yakin sistem menebak wilayahnya. Periksa semuanya.'],
            'ref_status' => ['Status Transaksi (referensi, tidak dibaca)', 'o', 'Berjalan atau Selesai. Pada transaksi Selesai, data konsumen tetap bisa diperbarui; Sumber Lead, Sales, Notaris, dan Bank hanya oleh pemegang izin override.'],
        ];
    }

    /** Urutan kolom untuk kelompok terpilih. @return string[] key */
    public static function kunciKolom(array $kelompok, bool $saran = false): array
    {
        $kunci = self::IDENTITAS;
        foreach (self::KELOMPOK as $nama => [, $kolom]) {
            if (in_array($nama, $kelompok, true)) array_push($kunci, ...$kolom);
        }
        if ($saran) array_push($kunci, 'ref_alamat', 'ref_keyakinan');
        $kunci[] = 'ref_status';

        return $kunci;
    }

    private static function normalJudul(mixed $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', preg_replace('/\s*[*†]+\s*$/u', '', trim((string) $s)))));
    }

    /**
     * Petakan baris judul ke key kolom. Judul tak dikenal, judul ganda, atau kolom pengenal yang hilang = masalah (file ditolak).
     * @return array{0: array<int,string>, 1: string[]} [indeks kolom => key, masalah]
     */
    public static function bacaJudul(array $judul): array
    {
        $dikenal = [];
        foreach (self::definisi() as $key => [$label]) $dikenal[self::normalJudul($label)] = $key;

        $peta = [];
        $masalah = [];
        foreach (array_values($judul) as $i => $j) {
            $n = self::normalJudul($j);
            if ($n === '') continue;
            $key = $dikenal[$n] ?? null;
            if (!$key) { $masalah[] = 'judul kolom "' . trim((string) $j) . '" tidak dikenal'; continue; }
            if (in_array($key, $peta, true)) { $masalah[] = 'judul kolom "' . trim((string) $j) . '" muncul dua kali'; continue; }
            $peta[$i] = $key;
        }
        foreach (['blok', 'unit', 'nama'] as $wajib) {
            if (!in_array($wajib, $peta, true)) $masalah[] = 'kolom wajib "' . self::definisi()[$wajib][0] . '" tidak ada';
        }

        return [$peta, $masalah];
    }

    /** @return string[] masalah header file; kosong bila bisa diproses. */
    public static function validasiHeader(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheetByName(self::SHEET);
        if (!$sheet) return ['Sheet "' . self::SHEET . '" tidak ditemukan. Unduh file dari dialog Import Konsumen mode Perbarui.'];

        $judul = [];
        $maks = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($i = 1; $i <= $maks; $i++) $judul[] = $sheet->getCell([$i, 1])->getValue();
        [, $masalah] = self::bacaJudul($judul);

        return $masalah ? ['[' . self::SHEET . '] Header bermasalah: ' . implode('; ', array_slice($masalah, 0, 4)) . (count($masalah) > 4 ? '; …' : '') . '.'] : [];
    }

    /** Daftar isi dropdown (sheet "Daftar"). */
    public static function lists(): array
    {
        $hasil = array_intersect_key(KonsumenImportSpec::lists(), array_flip(['Pekerjaan', 'Status Pernikahan', 'Sumber Lead', 'Sales / Agent', 'Notaris', 'Bank Rekanan']));
        $hasil['Provinsi'] = WilayahModel::where('level', 1)->orderBy('nama')->pluck('nama')->all();
        $hasil['Ya Tidak'] = ['Ya', 'Tidak'];

        return $hasil;
    }

    public static function dropdownListFor(string $key): ?string
    {
        return [
            'kerja' => 'Pekerjaan', 'nikah' => 'Status Pernikahan', 'lead' => 'Sumber Lead', 'sales' => 'Sales / Agent', 'notaris' => 'Notaris',
            'bank' => 'Bank Rekanan', 'prov' => 'Provinsi', 'dom_prov' => 'Provinsi', 'dom_sama' => 'Ya Tidak',
        ][$key] ?? null;
    }

    /**
     * Nilai data SAAT INI dalam bentuk teks seperti di file — dipakai untuk mengisi unduhan dan untuk membandingkan saat import
     * (sel yang sama dengan data sekarang tidak dianggap perubahan, jadi tidak divalidasi ulang).
     */
    public static function nilaiSaatIni(KavlingKonsumen $trx): array
    {
        $k = $trx->konsumen;
        $kav = $trx->kavling;

        return array_map(fn ($v) => (string) $v, [
            'kluster' => $kav->kluster, 'blok' => $kav->blok, 'unit' => $kav->nomor_kavling, 'nama' => $k->nama, 'nik' => $k->nik,
            'hp' => $k->no_hp, 'email' => $k->email, 'npwp' => $k->npwp,
            'kerja' => Konsumen::jenisPekerjaanLabel()[$k->pekerjaan] ?? null, 'nikah' => Konsumen::statusPernikahanLabel()[$k->status_pernikahan] ?? null,
            'alamat' => $k->alamat, 'prov' => $k->provinsi_nama, 'kab' => $k->kabupaten_nama, 'kec' => $k->kecamatan_nama, 'kel' => $k->kelurahan_nama,
            'dom_sama' => match ($k->domisili_sama_ktp) { true => 'Ya', false => 'Tidak', default => null },
            'dom_alamat' => $k->domisili_alamat, 'dom_prov' => $k->domisili_provinsi_nama, 'dom_kab' => $k->domisili_kabupaten_nama,
            'dom_kec' => $k->domisili_kecamatan_nama, 'dom_kel' => $k->domisili_kelurahan_nama,
            'notaris' => $trx->notarisPreset?->nama, 'lead' => $k->sumberLead?->nama, 'referral' => $k->referral_keterangan,
            'sales' => $trx->salesAgent?->nama, 'bank' => $trx->bankRekananPreset?->nama,
        ]);
    }

    /** Apakah transaksi masih punya data kosong pada kelompok terpilih (untuk unduhan "hanya yang kosong"). NPWP tidak dihitung. */
    public static function adaYangKosong(KavlingKonsumen $trx, array $kelompok): bool
    {
        $k = $trx->konsumen;
        $kpr = in_array($trx->cara_bayar, ['kpr_subsidi', 'kpr_komersil'], true);
        $kosong = [
            'kontak' => blank($k->no_hp) || blank($k->email),
            'pekerjaan' => blank($k->pekerjaan) || blank($k->status_pernikahan),
            'ktp' => blank($k->alamat) || blank($k->kabupaten_kode),
            'domisili' => $k->domisili_sama_ktp === null || ($k->domisili_sama_ktp === false && blank($k->domisili_kabupaten_kode) && blank($k->domisili_alamat)),
            'notaris' => blank($trx->notaris_preset_id),
            'penjualan' => blank($k->sumber_lead_id) || blank($trx->sales_agent_id) || ($kpr && blank($trx->bank_rekanan_preset_id)),
        ];
        foreach ($kelompok as $g) {
            if ($kosong[$g] ?? false) return true;
        }

        return false;
    }
}
