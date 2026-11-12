<?php

namespace App\Support;

use App\Models\PromoPreset;

/**
 * Promo yang dipilih untuk tiap baris biaya (Harga Jual, Kelebihan Tanah, tiap Biaya Tambahan, Titipan Biaya Akad). Tiap baris punya
 * pilihan promo sendiri, dan promo itu hanya berlaku bila memang menjadikan baris tersebut lawannya (lihat PromoPresetLawan).
 *
 * Kunci input per baris: diskon_promo_id, promo_tanah_id, promo_titipan_id, dan biaya_tambahan.*.promo_preset_id. Bila sebuah kunci
 * TIDAK dikirim sama sekali (form lama yang masih terbuka di browser, usulan perubahan rincian yang disimpan sebelum fitur ini ada),
 * dipakai cadangan: promo_preset_id lama yang dulu berlaku untuk semua biaya. Kunci yang dikirim dengan nilai kosong berarti
 * "tanpa promo" dan tidak memakai cadangan.
 */
class PromoPerBiaya
{
    /** @var array<int, ?PromoPreset> */
    private array $preset = [];

    public function __construct(private array $input) {}

    public static function dari(array $input): self
    {
        return new self($input);
    }

    /** Promo untuk potongan Harga Jual (diskon): id bila promo yang dipilih punya lawan Harga Jual, selain itu null. */
    public function hargaJual(): ?int
    {
        return $this->bila($this->pilihan($this->input, 'diskon_promo_id'), fn (PromoPreset $p) => $p->lawanHargaJual());
    }

    public function tanah(): ?int
    {
        return $this->bila($this->pilihan($this->input, 'promo_tanah_id'), fn (PromoPreset $p) => $p->lawanTanah());
    }

    public function titipan(): ?int
    {
        return $this->bila($this->pilihan($this->input, 'promo_titipan_id'), fn (PromoPreset $p) => $p->lawanTitipanBiayaAkad());
    }

    /** @param array $item satu baris biaya_tambahan dari form (preset_id, nominal, promo_preset_id, potongan) */
    public function biayaTambahan(array $item): ?int
    {
        $presetBiayaId = (int) ($item['preset_id'] ?? 0);

        return $this->bila(
            $this->pilihan($item, 'promo_preset_id'),
            fn (PromoPreset $p) => in_array($presetBiayaId, $p->lawanBiayaTambahanIds(), true)
        );
    }

    private function pilihan(array $sumber, string $kunci): ?int
    {
        $id = array_key_exists($kunci, $sumber) ? $sumber[$kunci] : ($this->input['promo_preset_id'] ?? null);

        return filled($id) ? (int) $id : null;
    }

    private function bila(?int $id, callable $cocok): ?int
    {
        if (!$id) return null;
        $this->preset[$id] ??= PromoPreset::with('lawan')->find($id);
        $p = $this->preset[$id];

        return $p && $cocok($p) ? $id : null;
    }
}
