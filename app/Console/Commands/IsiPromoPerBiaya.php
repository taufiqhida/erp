<?php

namespace App\Console\Commands;

use App\Models\KavlingKonsumen;
use App\Models\PromoPreset;
use App\Support\FinanceCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengisi referensi promo per biaya pada transaksi lama (kolom diskon_promo_id, biaya_kelebihan_tanah_promo_id,
 * titipan_biaya_akad_promo_id, dan promo_preset_id pada item biaya tambahan). Sebelum promo per biaya, satu transaksi hanya punya satu
 * promo (kavling_konsumen.promo_preset_id) untuk semua biaya yang dipotong. Aturan: biaya yang punya potongan > 0 dan referensinya
 * masih kosong diisi dengan promo lama transaksi itu, asalkan promo tersebut memang punya lawan biaya tersebut. Yang tidak cocok
 * dilaporkan dan dibiarkan kosong.
 *
 * Aman: hanya MENGISI kolom referensi yang masih kosong (boleh dijalankan berulang), tidak menyentuh nominal, potongan, maupun
 * promo_preset_id lama. Tanpa --terapkan hanya pratinjau. Sesudah menulis, total sebelum/sesudah dibandingkan dan penulisan dibatalkan
 * bila ada yang berbeda.
 */
class IsiPromoPerBiaya extends Command
{
    protected $signature = 'promo:isi-per-biaya {--terapkan : Tulis ke database (tanpa opsi ini hanya pratinjau)}';

    protected $description = 'Isi referensi promo per biaya pada transaksi lama (pratinjau dulu, --terapkan untuk menyimpan)';

    public function handle(): int
    {
        $terapkan = (bool) $this->option('terapkan');
        $presets = PromoPreset::with('lawan')->get()->keyBy('id');

        $isi = [];          // [['tabel' => , 'id' => , 'kolom' => , 'promo' => ]]
        $ringkas = [];      // [promo nama][jenis] = jumlah
        $aneh = [];         // pesan untuk ditinjau manual
        $diperiksa = 0;

        KavlingKonsumen::query()
            ->with(['konsumen:id,nama', 'kavling:id,blok,nomor_kavling', 'biayaTambahans'])
            ->orderBy('id')
            ->chunkById(200, function ($semua) use ($presets, &$isi, &$ringkas, &$aneh, &$diperiksa) {
                foreach ($semua as $kk) {
                    $label = "#{$kk->id} " . ($kk->konsumen?->nama ?? '-') . ' ' . ($kk->kavling ? "{$kk->kavling->blok}-{$kk->kavling->nomor_kavling}" : '');
                    $punyaPotongan = (float) $kk->diskon_nominal > 0 || (float) $kk->biaya_kelebihan_tanah_potongan > 0
                        || (float) $kk->titipan_biaya_akad_potongan > 0 || $kk->biayaTambahans->contains(fn ($b) => (float) $b->potongan > 0);
                    if (!$punyaPotongan) continue;
                    $diperiksa++;

                    $promo = $kk->promo_preset_id ? $presets->get($kk->promo_preset_id) : null;
                    $baris = [
                        ['kavling_konsumen', $kk->id, 'diskon_promo_id', 'Harga Jual (diskon)', (float) $kk->diskon_nominal, $kk->diskon_promo_id, fn (PromoPreset $p) => $p->lawanHargaJual()],
                        ['kavling_konsumen', $kk->id, 'biaya_kelebihan_tanah_promo_id', 'Kelebihan Tanah', (float) $kk->biaya_kelebihan_tanah_potongan, $kk->biaya_kelebihan_tanah_promo_id, fn (PromoPreset $p) => $p->lawanTanah()],
                        ['kavling_konsumen', $kk->id, 'titipan_biaya_akad_promo_id', 'Titipan Biaya Akad', (float) $kk->titipan_biaya_akad_potongan, $kk->titipan_biaya_akad_promo_id, fn (PromoPreset $p) => $p->lawanTitipanBiayaAkad()],
                    ];
                    foreach ($kk->biayaTambahans as $bt) {
                        $presetBiayaId = (int) $bt->biaya_tambahan_preset_id;
                        $baris[] = ['kavling_konsumen_biaya_tambahan', $bt->id, 'promo_preset_id', "Biaya Tambahan {$bt->nama}", (float) $bt->potongan, $bt->promo_preset_id,
                            fn (PromoPreset $p) => in_array($presetBiayaId, $p->lawanBiayaTambahanIds(), true)];
                    }

                    foreach ($baris as [$tabel, $id, $kolom, $nama, $potongan, $sudah, $cocok]) {
                        if ($potongan <= 0 || $sudah) continue;
                        if (!$promo) { $aneh[] = "{$label}: {$nama} ada potongan tetapi transaksi tidak punya promo."; continue; }
                        if (!$cocok($promo)) { $aneh[] = "{$label}: {$nama} ada potongan tetapi promo '{$promo->nama}' tidak punya lawan {$nama}."; continue; }
                        $isi[] = ['tabel' => $tabel, 'id' => $id, 'kolom' => $kolom, 'promo' => $promo->id];
                        $ringkas[$promo->nama][$nama] = ($ringkas[$promo->nama][$nama] ?? 0) + 1;
                    }
                }
            });

        $this->info(($terapkan ? 'MENERAPKAN' : 'PRATINJAU') . ": {$diperiksa} transaksi punya potongan promo, " . count($isi) . ' referensi akan diisi, ' . count($aneh) . ' perlu ditinjau.');
        foreach ($ringkas as $namaPromo => $perJenis) {
            foreach ($perJenis as $jenis => $jumlah) {
                $this->line("  {$namaPromo} → {$jenis}: {$jumlah}");
            }
        }
        foreach ($aneh as $a) $this->warn("  Ditinjau — {$a}");

        if (!$terapkan) {
            if ($isi) $this->line('Jalankan ulang dengan --terapkan setelah memeriksa daftar di atas.');
            return self::SUCCESS;
        }
        if (!$isi) {
            $this->info('Tidak ada yang perlu diisi.');
            return self::SUCCESS;
        }

        $sebelum = $this->cekSum();
        try {
            DB::transaction(function () use ($isi, $sebelum) {
                foreach ($isi as $i) {
                    // Hanya mengisi yang masih kosong (query builder: tanpa event model, jadi total keuangan tidak dihitung ulang).
                    DB::table($i['tabel'])->where('id', $i['id'])->whereNull($i['kolom'])->update([$i['kolom'] => $i['promo']]);
                }
                $sesudah = $this->cekSum();
                if ($sesudah !== $sebelum) {
                    throw new \RuntimeException("Total sebelum dan sesudah berbeda, dibatalkan.\nSebelum: " . json_encode($sebelum) . "\nSesudah: " . json_encode($sesudah));
                }
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        FinanceCache::bump();
        $this->info('Selesai. Total keuangan dan potongan sebelum = sesudah: ' . json_encode($sebelum));

        return self::SUCCESS;
    }

    /** Jumlah kolom nominal/potongan/fin_* seluruh transaksi, untuk membuktikan penulisan tidak mengubah angka. */
    private function cekSum(): array
    {
        $kolom = collect(['harga_deal', 'diskon_nominal', 'biaya_kelebihan_tanah_potongan', 'titipan_biaya_akad_potongan', 'total_biaya_tambahan'])
            ->merge(collect(Schema::getColumnListing('kavling_konsumen'))->filter(fn ($c) => str_starts_with($c, 'fin_')))
            ->unique()->values();

        $hasil = [];
        foreach ($kolom as $k) $hasil[$k] = round((float) DB::table('kavling_konsumen')->sum($k), 2);
        $hasil['biaya_tambahan_potongan'] = round((float) DB::table('kavling_konsumen_biaya_tambahan')->sum('potongan'), 2);
        $hasil['biaya_tambahan_nominal'] = round((float) DB::table('kavling_konsumen_biaya_tambahan')->sum('nominal'), 2);

        return $hasil;
    }
}
