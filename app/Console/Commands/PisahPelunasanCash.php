<?php

namespace App\Console\Commands;

use App\Models\JadwalTagihan;
use App\Models\KavlingKonsumen;
use App\Support\FinanceCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menyesuaikan jadwal Pelunasan transaksi Cash/Cash Bertahap buatan SEBELUM biaya tanah & biaya tambahan dipisah menjadi baris sendiri
 * di Kartu Piutang. Dulu nominal Pelunasan = harga deal (sudah mengandung kedua komponen itu); sekarang = harga dasar - diskon - BF/DP
 * yang masuk harga jual, sehingga jadwal lama akan menagih komponen itu dua kali.
 *
 * Tiap transaksi diklasifikasi dari jumlah jadwal Pelunasannya:
 *  - sama dengan rumus LAMA   -> kurangi komponen dari cicilan TERAKHIR yang belum ada pembayarannya (baris yang menjadi 0 dihapus);
 *  - sama dengan rumus BARU   -> sudah beres, dilewati;
 *  - lainnya (diubah manual) atau cicilan yang belum dibayar tak cukup menampung pengurangan (pembayaran sudah menutupi bagian
 *    komponen) -> dilaporkan untuk ditangani manual, TIDAK diubah.
 * Tanpa --terapkan hanya pratinjau.
 */
class PisahPelunasanCash extends Command
{
    protected $signature = 'pelunasan:pisah-komponen {--terapkan : Terapkan perubahan (tanpa opsi ini hanya pratinjau)}';

    protected $description = 'Pratinjau/terapkan penyesuaian jadwal Pelunasan cash lama agar tidak menagih biaya tanah & biaya tambahan dua kali';

    public function handle(): int
    {
        $terapkan = (bool) $this->option('terapkan');
        $rp = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');

        $siap = [];
        $manual = [];
        $beres = 0;

        KavlingKonsumen::query()
            ->with(['kavling', 'konsumen', 'jadwalTagihans.pembayaran', 'biayaTambahans', 'skemaDpPreset'])
            ->whereIn('cara_bayar', ['cash', 'cash_bertahap'])
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->chunkById(100, function ($semua) use (&$siap, &$manual, &$beres) {
                foreach ($semua as $kk) {
                    $komponen = ($kk->biaya_kelebihan_tanah_aktif ? (float) $kk->biaya_kelebihan_tanah_ditagih : 0.0)
                        + (float) $kk->biayaTambahans->sum(fn ($b) => $b->ditagih);
                    $pelunasan = $kk->jadwalTagihans->where('jenis', 'pelunasan')->sortBy('nomor_cicilan')->values();
                    if ($komponen <= 0.5 || $pelunasan->isEmpty()) continue;

                    $unit = $kk->kavling->blok . '-' . $kk->kavling->nomor_kavling;
                    $skema = $kk->skemaDpPreset;
                    $pengurang = (($skema?->booking_fee_masuk_harga_jual ?? false) ? (float) $kk->jadwalTagihans->where('jenis', 'booking_fee')->sum('jumlah') : 0)
                        + (($skema?->dp_masuk_harga_jual ?? false) ? (float) $kk->jadwalTagihans->where('jenis', 'dp')->sum('jumlah') : 0);
                    $jumlah = (float) $pelunasan->sum('jumlah');
                    $lama = max(0, (float) $kk->harga_deal - $pengurang);
                    $baru = max(0, (float) $kk->harga_deal - $komponen - $pengurang);
                    $info = ['kk' => $kk, 'unit' => $unit, 'komponen' => $komponen, 'jumlah' => $jumlah];

                    if (abs($jumlah - $baru) <= 0.5) { $beres++; continue; }
                    if (abs($jumlah - $lama) > 0.5) {
                        $manual[] = $info + ['alasan' => 'jumlah Pelunasan tidak sama dengan rumus lama maupun baru (kemungkinan diubah manual)'];
                        continue;
                    }

                    // Kurangi dari cicilan paling belakang yang belum ada pembayarannya.
                    $sisaKurang = $komponen;
                    $rencana = [];
                    foreach ($pelunasan->reverse() as $j) {
                        if ($sisaKurang <= 0.005) break;
                        if ($j->status !== 'belum_bayar' || $j->pembayaran_konsumen_id) continue;
                        $potong = min((float) $j->jumlah, $sisaKurang);
                        $rencana[] = ['jadwal' => $j, 'dari' => (float) $j->jumlah, 'ke' => round((float) $j->jumlah - $potong, 2)];
                        $sisaKurang = round($sisaKurang - $potong, 2);
                    }
                    if ($sisaKurang > 0.5) {
                        $manual[] = $info + ['alasan' => "cicilan yang belum dibayar tidak cukup menampung pengurangan (kurang {$sisaKurang}); pembayaran Pelunasan sudah menutupi bagian biaya tanah/tambahan"];
                        continue;
                    }
                    $siap[] = $info + ['rencana' => $rencana];
                }
            });

        $this->info(($terapkan ? 'MENERAPKAN' : 'PRATINJAU') . ': ' . count($siap) . ' transaksi bisa disesuaikan, ' . count($manual) . ' perlu ditangani manual, ' . $beres . ' sudah beres.');
        foreach ($siap as $s) {
            $this->line("  #{$s['kk']->id} {$s['unit']} {$s['kk']->konsumen?->nama}: Pelunasan {$rp($s['jumlah'])} -> {$rp($s['jumlah'] - $s['komponen'])} (komponen terpisah {$rp($s['komponen'])})");
            foreach ($s['rencana'] as $r) {
                $this->line("      cicilan #{$r['jadwal']->nomor_cicilan}: {$rp($r['dari'])} -> " . ($r['ke'] <= 0 ? 'dihapus' : $rp($r['ke'])));
            }
        }
        foreach ($manual as $m) {
            $this->warn("  MANUAL #{$m['kk']->id} {$m['unit']} {$m['kk']->konsumen?->nama}: {$m['alasan']}");
        }

        if (!$terapkan) {
            if ($siap) $this->line('Untuk menerapkan: php artisan pelunasan:pisah-komponen --terapkan');
            return self::SUCCESS;
        }

        foreach ($siap as $s) {
            KavlingKonsumen::withoutFinanceRefresh(fn () => DB::transaction(function () use ($s) {
                foreach ($s['rencana'] as $r) {
                    /** @var JadwalTagihan $j */
                    $j = $r['jadwal'];
                    if ($r['ke'] <= 0) $j->delete();
                    else $j->update(['jumlah' => $r['ke']]);
                }
            }));
            KavlingKonsumen::refreshFinanceFor($s['kk']->id);
            activity('transaksi')->performedOn($s['kk'])
                ->withProperties(['komponen_terpisah' => $s['komponen'], 'pelunasan_sebelum' => $s['jumlah']])
                ->log("Jadwal Pelunasan disesuaikan: biaya tanah/biaya tambahan {$rp($s['komponen'])} dipisah dari Pelunasan (perintah pelunasan:pisah-komponen).");
        }
        if ($siap) FinanceCache::bump();
        $this->info(count($siap) . ' transaksi disesuaikan.');

        return self::SUCCESS;
    }
}
