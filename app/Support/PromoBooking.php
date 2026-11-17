<?php

namespace App\Support;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Models\BonusPreset;
use App\Models\CancellationRequest;
use App\Models\CatatanTransaksi;
use App\Models\KavlingKonsumen;
use App\Models\PromoPreset;
use App\Models\User;

/**
 * Persetujuan promo pada booking. Admin Sales yang memberi promo, potongan, diskon, label promo, atau bonus saat booking tidak
 * langsung menerapkannya: transaksi dibuat TANPA hal-hal itu (tagihan memakai harga penuh), lalu seluruh rincian yang diinginkan
 * masuk Pengajuan (tab Rincian & Promo, penanda "Promo Booking") dan baru berlaku setelah SPV/Leader menyetujui — lewat jalur yang
 * sama dengan Ubah Rincian (PerubahanRincian), sehingga angkanya pasti sama dengan pratinjau. Pemegang izin override
 * (SPV/Leader/Superadmin) booking langsung dengan promo, tanpa usulan.
 */
class PromoBooking
{
    /** Isian form booking yang termasuk promo/potongan/bonus (dibuang dari data booking saat menunggu persetujuan). */
    private const KUNCI_PROMO = [
        'promo_preset_id', 'diskon_mode', 'diskon_nilai', 'diskon_promo_id', 'promo_tanah_id', 'promo_titipan_id',
        'potongan_tanah', 'potongan_titipan', 'bonus_ids',
    ];
    private const KUNCI_PROMO_BIAYA = ['promo_preset_id', 'potongan'];

    private static function potonganTerisi(mixed $p): bool
    {
        return is_array($p) && (float) ($p['nilai'] ?? 0) > 0;
    }

    /** Apakah isian booking memuat promo, potongan, diskon, label promo, atau bonus. */
    public static function ada(array $v): bool
    {
        foreach (['promo_preset_id', 'diskon_mode', 'diskon_promo_id', 'promo_tanah_id', 'promo_titipan_id'] as $k) {
            if (filled($v[$k] ?? null)) return true;
        }
        if (!empty($v['bonus_ids'])) return true;
        if (self::potonganTerisi($v['potongan_tanah'] ?? null) || self::potonganTerisi($v['potongan_titipan'] ?? null)) return true;
        foreach ($v['biaya_tambahan'] ?? [] as $b) {
            if (filled($b['promo_preset_id'] ?? null) || self::potonganTerisi($b['potongan'] ?? null)) return true;
        }

        return false;
    }

    /**
     * Pisahkan isian booking: [data booking tanpa promo/potongan/bonus, rincian LENGKAP yang diusulkan].
     * Biaya tambahan, tanah, dan All In tetap ikut booking (bruto); hanya yang bersifat promo yang menunggu.
     *
     * @return array{0: array, 1: array}
     */
    public static function pisahkan(array $v): array
    {
        $usulan = PerubahanRincian::ambil($v);

        $polos = array_diff_key($v, array_flip(self::KUNCI_PROMO));
        if (isset($polos['biaya_tambahan'])) {
            $polos['biaya_tambahan'] = array_map(fn ($b) => array_diff_key($b, array_flip(self::KUNCI_PROMO_BIAYA)), $polos['biaya_tambahan']);
        }

        return [$polos, $usulan];
    }

    /** Ringkasan teks promo/bonus yang diminta (untuk alasan usulan dan catatan transaksi). */
    public static function ringkas(array $rincian): string
    {
        $idPromo = collect([$rincian['promo_preset_id'] ?? null, $rincian['diskon_promo_id'] ?? null, $rincian['promo_tanah_id'] ?? null, $rincian['promo_titipan_id'] ?? null])
            ->merge(collect($rincian['biaya_tambahan'] ?? [])->pluck('promo_preset_id'))
            ->filter()->unique()->values();
        $promo = $idPromo->isEmpty() ? collect() : PromoPreset::whereIn('id', $idPromo)->pluck('nama');
        $bonus = empty($rincian['bonus_ids']) ? collect() : BonusPreset::whereIn('id', $rincian['bonus_ids'])->pluck('nama');

        $bagian = [];
        if ($promo->isNotEmpty()) $bagian[] = 'Promo: ' . $promo->implode(', ');
        if ($bonus->isNotEmpty()) $bagian[] = 'Bonus: ' . $bonus->implode(', ');
        if (!$bagian) $bagian[] = 'Potongan/diskon';

        return implode('; ', $bagian);
    }

    /** Buat usulan di Pengajuan untuk transaksi yang baru dibooking. Pemanggil mengurus transaksi database. */
    public static function buatUsulan(KavlingKonsumen $kk, array $rincian, User $pengaju): CancellationRequest
    {
        // Baris biaya tambahan sudah dibuat bersama booking: usulan menunjuk baris itu (bukan membuat ganda saat disetujui).
        $ids = $kk->biayaTambahans()->orderBy('id')->pluck('id')->values();
        foreach (array_values($rincian['biaya_tambahan'] ?? []) as $i => $b) {
            $rincian['biaya_tambahan'][$i]['id'] = $ids[$i] ?? null;
        }

        $ringkas = self::ringkas($rincian);
        CatatanTransaksi::tulis($kk, 'booking', "Menunggu persetujuan SPV/Leader di Pengajuan — {$ringkas}. Sampai disetujui, tagihan memakai harga penuh.", 'Promo booking', $pengaju);

        return CancellationRequest::create([
            'type'                  => CancellationRequestType::RincianChange,
            'kavling_id'            => $kk->kavling_id,
            'kavling_konsumen_id'   => $kk->id,
            'konsumen_id'           => $kk->konsumen_id,
            'kavling_status_before' => 'booked',          // status unit tidak diubah oleh usulan ini
            'alasan'                => "Promo saat booking — {$ringkas}",
            'payload'               => ['rincian' => $rincian, 'harga_dasar_ke_unit' => false, 'tanggal_booking' => null, 'asal' => 'booking'],
            'requested_by'          => $pengaju->id,
            'status'                => CancellationStatus::Pending,
        ]);
    }
}
