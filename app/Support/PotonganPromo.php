<?php

namespace App\Support;

use App\Models\PromoPreset;

/**
 * Potongan promo pada biaya (Kelebihan Tanah, Biaya Tambahan). Preset promo hanya menentukan "lawan" yang boleh dipotong; nominal atau
 * persen diisi per transaksi. Biaya disimpan bruto, ditagih = bruto - potongan.
 */
class PotonganPromo
{
    /** Nominal potongan dari mode ('persen'|'nominal') dan nilainya, dibatasi 0..bruto. */
    public static function nominal(float $bruto, ?string $mode, $nilai): float
    {
        if (!in_array($mode, ['persen', 'nominal'], true) || $nilai === null || $nilai === '' || $bruto <= 0) return 0.0;
        $hasil = $mode === 'persen' ? round($bruto * ((float) $nilai / 100), 2) : (float) $nilai;

        return round(max(0.0, min($bruto, $hasil)), 2);
    }

    /**
     * Potongan untuk satu baris biaya: hanya berlaku bila promo yang dipilih memang menjadikan baris itu lawannya.
     * Mengembalikan ['potongan' => float, 'mode' => ?string, 'nilai' => ?float] (nol bila tidak berlaku).
     */
    public static function untukBaris(float $bruto, ?array $input, bool $lawan): array
    {
        $kosong = ['potongan' => 0.0, 'mode' => null, 'nilai' => null];
        if (!$lawan || !$input) return $kosong;
        $mode = $input['mode'] ?? null;
        $nilai = $input['nilai'] ?? null;
        $potongan = self::nominal($bruto, $mode, $nilai);
        if ($potongan <= 0) return $kosong;

        return ['potongan' => $potongan, 'mode' => $mode, 'nilai' => (float) $nilai];
    }

    /** Lawan dari promo yang dipilih: ['tanah' => bool, 'biaya_tambahan' => int[], 'harga_jual' => bool]. */
    public static function lawan(?int $promoId): array
    {
        $kosong = ['tanah' => false, 'biaya_tambahan' => [], 'harga_jual' => false];
        if (!$promoId) return $kosong;
        $promo = PromoPreset::with('lawan')->find($promoId);

        return $promo ? ['tanah' => $promo->lawanTanah(), 'biaya_tambahan' => $promo->lawanBiayaTambahanIds(), 'harga_jual' => $promo->lawanHargaJual()] : $kosong;
    }

    /** Aturan validasi tambahan untuk input potongan di form (booking dan ubah rincian). */
    public static function aturan(): array
    {
        return [
            'potongan_tanah'                  => 'nullable|array',
            'potongan_tanah.mode'             => 'nullable|in:persen,nominal',
            'potongan_tanah.nilai'            => 'nullable|numeric|min:0',
            'biaya_tambahan.*.potongan'       => 'nullable|array',
            'biaya_tambahan.*.potongan.mode'  => 'nullable|in:persen,nominal',
            'biaya_tambahan.*.potongan.nilai' => 'nullable|numeric|min:0',
        ];
    }
}
