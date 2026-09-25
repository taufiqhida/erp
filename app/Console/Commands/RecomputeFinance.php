<?php

namespace App\Console\Commands;

use App\Models\KavlingKonsumen;
use Illuminate\Console\Command;

class RecomputeFinance extends Command
{
    protected $signature = 'finance:recompute {--check : Hanya bandingkan angka tersimpan dengan rumus Kartu Piutang, tanpa menulis} {--project= : Batasi ke satu proyek (id)}';

    protected $description = 'Hitung ulang total keuangan tersimpan (kolom fin_*) semua transaksi dari Kartu Piutang';

    public function handle(): int
    {
        $check = (bool) $this->option('check');
        $query = KavlingKonsumen::query()
            ->when($this->option('project'), fn ($q, $id) => $q->whereHas('kavling', fn ($k) => $k->where('project_id', $id)));

        $total = 0;
        $mismatch = [];

        $query->orderBy('id')->chunkById(100, function ($chunk) use ($check, &$total, &$mismatch) {
            foreach ($chunk as $kk) {
                $total++;
                if ($check) {
                    $kk->load(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps']);
                    $b = $kk->kartuPiutangBreakdown();
                    $expected = [
                        'piutang_konsumen' => round($b['total_piutang_konsumen'], 2),
                        'terbayar_konsumen' => round($b['total_terbayar_konsumen'], 2),
                        'piutang_bank' => round($b['total_piutang_bank'], 2),
                        'terbayar_bank' => round($b['total_terbayar_bank'], 2),
                    ];
                    foreach ($expected as $key => $value) {
                        $stored = (float) $kk->getAttribute("fin_{$key}");
                        if (abs($stored - $value) > 0.009) {
                            $mismatch[] = "transaksi #{$kk->id}: fin_{$key} tersimpan {$stored} vs rumus {$value}";
                        }
                    }
                } else {
                    $kk->refreshFinance();
                }
            }
        });

        if ($check) {
            $this->info("Diperiksa {$total} transaksi, " . count($mismatch) . ' selisih.');
            foreach (array_slice($mismatch, 0, 20) as $line) $this->line("  - {$line}");
            return count($mismatch) ? self::FAILURE : self::SUCCESS;
        }

        $this->info("Selesai: {$total} transaksi dihitung ulang.");
        return self::SUCCESS;
    }
}
