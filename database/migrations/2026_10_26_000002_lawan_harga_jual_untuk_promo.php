<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Peleburan Promo dan Diskon: diskon harga adalah promo yang "lawannya" Harga Jual. Supaya perilaku lama terjaga:
 *  - semua promo yang sudah ada diberi lawan Harga Jual (bisa dicabut per promo di Pengaturan > Promo);
 *  - transaksi yang punya diskon tetapi tanpa promo dikaitkan ke satu promo baru "Diskon Harga".
 * Nilai diskon tetap tersimpan di kolom diskon_* yang ada, jadi laporan tidak berubah.
 */
return new class extends Migration
{
    private const JENIS = 'harga_jual';

    public function up(): void
    {
        $now = now();
        $beri = function (int $promoId) use ($now) {
            $ada = DB::table('promo_preset_lawan')->where('promo_preset_id', $promoId)->where('jenis', self::JENIS)->exists();
            if (!$ada) {
                DB::table('promo_preset_lawan')->insert(['promo_preset_id' => $promoId, 'jenis' => self::JENIS, 'biaya_tambahan_preset_id' => null, 'created_at' => $now, 'updated_at' => $now]);
            }
        };

        foreach (DB::table('promo_presets')->pluck('id') as $id) $beri((int) $id);

        $yatim = DB::table('kavling_konsumen')->where('diskon_nominal', '>', 0)->whereNull('promo_preset_id')->pluck('id');
        if ($yatim->isNotEmpty()) {
            $promoId = DB::table('promo_presets')->where('nama', 'Diskon Harga')->value('id')
                ?? DB::table('promo_presets')->insertGetId([
                    'nama' => 'Diskon Harga', 'keterangan' => 'Dibuat otomatis untuk transaksi lama yang punya diskon tanpa promo',
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            $beri((int) $promoId);
            DB::table('kavling_konsumen')->whereIn('id', $yatim)->update(['promo_preset_id' => $promoId]);
        }
    }

    public function down(): void
    {
        DB::table('promo_preset_lawan')->where('jenis', self::JENIS)->delete();
    }
};
