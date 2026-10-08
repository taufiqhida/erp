<?php

namespace App\Support;

/** Pembuat password sementara (dipakai reset password superadmin dan perintah buat-superadmin). */
class PasswordSementara
{
    /** 12 karakter (grup 4-4-4), tanpa karakter yang mudah tertukar (0/O, 1/l/I); selalu ada huruf besar, kecil, dan angka. */
    public static function buat(): string
    {
        $besar = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $kecil = 'abcdefghijkmnpqrstuvwxyz';
        $angka = '23456789';
        $ambil = fn (string $set) => $set[random_int(0, strlen($set) - 1)];

        $chars = [
            $ambil($besar), $ambil($besar), $ambil($besar),
            $ambil($kecil), $ambil($kecil), $ambil($kecil), $ambil($kecil), $ambil($kecil),
            $ambil($angka), $ambil($angka), $ambil($angka), $ambil($kecil),
        ];
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('-', array_map('implode', array_chunk($chars, 4)));
    }
}
