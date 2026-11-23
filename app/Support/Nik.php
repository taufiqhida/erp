<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Aturan NIK (16 digit): 2 digit provinsi, 2 kabupaten/kota, 2 kecamatan, 6 digit tanggal lahir (hari +40 untuk perempuan,
 * bulan, 2 digit tahun), 4 digit nomor urut. Satu-satunya tempat aturan NIK — dipakai form booking, edit konsumen, import, dan audit.
 */
class Nik
{
    /** Kode provinsi Kemendagri (38 provinsi, termasuk pemekaran Papua 2022). */
    public const PROVINSI = [
        11, 12, 13, 14, 15, 16, 17, 18, 19, 21,
        31, 32, 33, 34, 35, 36,
        51, 52, 53,
        61, 62, 63, 64, 65,
        71, 72, 73, 74, 75, 76,
        81, 82,
        91, 92, 93, 94, 95, 96,
    ];

    /** Bersihkan pemisah kosmetik (spasi, titik, strip, apostrof, tab). Hasil bisa tetap tidak valid — periksa() yang menilai. */
    public static function bersihkan(?string $nik): ?string
    {
        if ($nik === null) return null;
        $b = preg_replace('/[\s.\-\'`]/u', '', trim($nik));

        return $b === '' ? null : $b;
    }

    /** Pesan error bila NIK tidak valid, null bila valid. NIK kosong dianggap valid (kolom NIK opsional). */
    public static function periksa(?string $nik): ?string
    {
        if ($nik === null || trim($nik) === '') return null;
        $nik = trim($nik);

        if (preg_match('/^\d(?:[.,]\d+)?E\+?\d+$/i', $nik)) {
            return "NIK '{$nik}' tampil dalam notasi ilmiah Excel (angka terpotong). Ubah format kolom NIK jadi Teks lalu ketik ulang.";
        }
        if (!preg_match('/^\d+$/', $nik)) return "NIK '{$nik}' harus berisi angka saja.";
        if (strlen($nik) !== 16) return "NIK '{$nik}' harus tepat 16 digit (terbaca " . strlen($nik) . ' digit).';
        if (preg_match('/^(\d)\1{15}$/', $nik)) return "NIK '{$nik}' tidak valid (semua digit sama).";
        if (substr($nik, 12) === '0000') return "NIK '{$nik}' tidak valid: 4 digit terakhir (nomor urut) tidak boleh 0000.";

        if (!in_array((int) substr($nik, 0, 2), self::PROVINSI, true)) {
            return "NIK '{$nik}' tidak valid: kode provinsi " . substr($nik, 0, 2) . ' tidak dikenal.';
        }

        $hari = (int) substr($nik, 6, 2);
        $bulan = (int) substr($nik, 8, 2);
        if ($hari > 40) $hari -= 40;
        if ($bulan < 1 || $bulan > 12) return "NIK '{$nik}' tidak valid: bulan lahir " . substr($nik, 8, 2) . ' (harus 01-12).';
        $maks = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31][$bulan];
        if ($hari < 1 || $hari > $maks) return "NIK '{$nik}' tidak valid: tanggal lahir " . substr($nik, 6, 2) . " tidak mungkin untuk bulan {$bulan} (laki-laki 01-{$maks}, perempuan 41-" . (40 + $maks) . ').';

        return null;
    }

    /** Peringatan (tidak memblokir) untuk NIK yang lolos periksa(): usia hasil hitungan tidak masuk akal. */
    public static function peringatan(?string $nik): ?string
    {
        if ($nik === null || self::periksa($nik) !== null) return null;

        $hari = (int) substr($nik, 6, 2);
        if ($hari > 40) $hari -= 40;
        $bulan = (int) substr($nik, 8, 2);
        $yy = (int) substr($nik, 10, 2);

        $usia = [];
        foreach ([1900 + $yy, 2000 + $yy] as $tahun) {
            try {
                $lahir = Carbon::create($tahun, $bulan, $hari);
            } catch (\Throwable) {
                continue;
            }
            if ($lahir->isFuture()) continue;
            $usia[] = $lahir->diffInYears(now());
        }
        foreach ($usia as $u) {
            if ($u >= 17 && $u <= 100) return null;
        }

        return "NIK {$nik}: tanggal lahir di NIK menghasilkan usia yang tidak wajar (di bawah 17 atau di atas 100 tahun) — pastikan NIK-nya benar.";
    }

    public static function valid(?string $nik): bool
    {
        return $nik !== null && trim($nik) !== '' && self::periksa($nik) === null;
    }
}
