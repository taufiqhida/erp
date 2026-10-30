<?php

namespace App\Console\Commands;

use App\Models\KavlingKonsumen;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Memperbaiki transaksi KPR yang "Total Sudah Cair"-nya ikut menghitung Dajam (terhitung dua kali): nominal tahap pencairan terakhir
 * dikurangi sebesar kelebihannya (item Dajam yang melebihi sisa plafon). Tanpa --terapkan hanya menampilkan pratinjau sebelum/sesudah.
 * Hanya menyentuh transaksi yang polanya jelas: kelebihan tidak lebih besar dari Dajam yang sudah dicatat cair, dan tahap terakhir cukup besar.
 */
class PerbaikiDajamGanda extends Command
{
    protected $signature = 'pencairan:perbaiki-dajam {--terapkan : Terapkan perbaikan (tanpa opsi ini hanya pratinjau)} {--project= : Batasi ke satu proyek (id)}';

    protected $description = 'Perbaiki Total Sudah Cair yang ikut menghitung Dajam (pratinjau dulu, --terapkan untuk menyimpan)';

    public function handle(): int
    {
        $terapkan = (bool) $this->option('terapkan');
        $calon = [];
        $dilewati = [];

        KavlingKonsumen::query()
            ->whereIn('cara_bayar', ['kpr_subsidi', 'kpr_komersil'])
            ->where('status', '!=', 'cancelled')
            ->when($this->option('project'), fn ($q, $id) => $q->whereHas('kavling', fn ($k) => $k->where('project_id', $id)))
            ->with(['konsumen:id,nama', 'kavling:id,blok,nomor_kavling', 'jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
            ->orderBy('id')
            ->chunkById(100, function ($semua) use (&$calon, &$dilewati) {
                foreach ($semua as $kk) {
                    $pk = $kk->kartuPiutangBreakdown()['pencairan_kpr'];
                    if (!$pk || $pk['dajam_melebihi'] <= 0.5) continue;

                    $kelebihan = round($pk['dajam_melebihi'], 2);
                    $tahap = $kk->pencairanKprTahaps->sortByDesc(fn ($t) => [$t->tanggal_cair, $t->id])->first();
                    $label = "#{$kk->id} {$kk->konsumen->nama} {$kk->kavling->blok}-{$kk->kavling->nomor_kavling}";
                    if ($pk['dajam_cair'] + 0.5 < $kelebihan) { $dilewati[] = "{$label}: kelebihan Rp " . number_format($kelebihan, 0, ',', '.') . ' lebih besar dari Dajam yang tercatat cair (pola tidak jelas).'; continue; }
                    if (!$tahap || (float) $tahap->nominal - $kelebihan < 1) { $dilewati[] = "{$label}: tahap pencairan terakhir tidak cukup besar untuk dikurangi."; continue; }

                    $calon[] = ['kk' => $kk, 'tahap' => $tahap, 'kelebihan' => $kelebihan, 'label' => $label];
                }
            });

        $rp = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
        $this->info(($terapkan ? 'MENERAPKAN' : 'PRATINJAU') . ': ' . count($calon) . ' transaksi akan diperbaiki, ' . count($dilewati) . ' dilewati.');
        foreach ($calon as $c) {
            $this->line("  {$c['label']}: tahap #{$c['tahap']->id} {$rp($c['tahap']->nominal)} → {$rp($c['tahap']->nominal - $c['kelebihan'])} (dikurangi {$rp($c['kelebihan'])})");
        }
        foreach ($dilewati as $d) $this->warn("  Dilewati — {$d}");

        if (!$terapkan) {
            if ($calon) $this->line('Jalankan ulang dengan --terapkan setelah memeriksa daftar di atas.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($calon) {
            foreach ($calon as $c) {
                $sebelum = (float) $c['tahap']->nominal;
                $c['tahap']->update(['nominal' => $sebelum - $c['kelebihan']]);
                activity('transaksi')->performedOn($c['kk'])->withProperties(['tahap_id' => $c['tahap']->id, 'sebelum' => $sebelum, 'sesudah' => $sebelum - $c['kelebihan']])
                    ->log('Nominal pencairan dikoreksi: Total Sudah Cair sebelumnya ikut menghitung Dajam');
                $c['kk']->refreshFinance();
            }
        });
        $this->info('Selesai. Disarankan menjalankan `php artisan finance:recompute --check` untuk memastikan total tersimpan konsisten.');

        return self::SUCCESS;
    }
}
