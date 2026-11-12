<?php

namespace App\Console\Commands;

use App\Models\KavlingKonsumen;
use App\Support\FinanceCache;
use Illuminate\Console\Command;

/**
 * Membuka kembali transaksi berstatus Selesai (completed) yang ternyata belum lunas — peninggalan sebelum ada peninjauan ulang
 * otomatis (mis. pembayaran dikoreksi lewat override setelah transaksi ditandai Selesai). Tanpa --terapkan hanya pratinjau seluruh
 * kandidat beserta sisanya. Menerapkan WAJIB menyebut unit (--unit=B1-6, boleh berulang) atau --semua, plus --alasan: Keuangan yang
 * memutuskan, karena sisanya bisa saja sudah dibayar di luar sistem. Yang dibuka kembali hanya yang MASIH belum lunas saat dijalankan.
 */
class BukaKembaliSelesai extends Command
{
    protected $signature = 'transaksi:buka-kembali-selesai {--terapkan : Buka kembali (tanpa opsi ini hanya pratinjau)} {--unit=* : Unit yang dibuka kembali, mis. B1-6 (boleh berulang)} {--semua : Semua kandidat} {--alasan= : Alasan (wajib saat --terapkan)}';

    protected $description = 'Pratinjau/buka kembali transaksi Selesai yang belum lunas (pratinjau dulu, --terapkan --unit=... --alasan=...)';

    public function handle(): int
    {
        $terapkan = (bool) $this->option('terapkan');
        $unitDipilih = array_map('mb_strtolower', array_filter((array) $this->option('unit')));
        $rp = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');

        $kandidat = [];
        KavlingKonsumen::query()
            ->with(['kavling', 'konsumen', 'jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
            ->where('status', 'completed')->orderBy('id')
            ->chunkById(100, function ($semua) use (&$kandidat) {
                foreach ($semua as $kk) {
                    $b = $kk->kartuPiutangBreakdown();
                    if (KavlingKonsumen::lunasPenuh($b)) continue;
                    $kandidat[] = [
                        'kk' => $kk,
                        'unit' => $kk->kavling->blok . '-' . $kk->kavling->nomor_kavling,
                        'sisa_konsumen' => round(max(0, $b['total_piutang_konsumen'] - $b['total_terbayar_konsumen']), 2),
                        'sisa_bank' => round(max(0, $b['total_piutang_bank'] - $b['total_terbayar_bank']), 2),
                    ];
                }
            });

        $this->info(($terapkan ? 'MENERAPKAN' : 'PRATINJAU') . ': ' . count($kandidat) . ' transaksi Selesai belum lunas.');
        foreach ($kandidat as $k) {
            $this->line("  #{$k['kk']->id} {$k['unit']} {$k['kk']->konsumen?->nama} ({$k['kk']->cara_bayar}): sisa konsumen {$rp($k['sisa_konsumen'])}, sisa bank {$rp($k['sisa_bank'])}");
        }

        if (!$terapkan) {
            if ($kandidat) $this->line('Untuk membuka kembali: --terapkan --unit=<unit> (atau --semua) --alasan="..."');
            return self::SUCCESS;
        }

        if (!filled($this->option('alasan'))) {
            $this->error('--alasan wajib diisi saat --terapkan.');
            return self::FAILURE;
        }
        if (!$unitDipilih && !$this->option('semua')) {
            $this->error('Sebutkan --unit=<unit> (boleh berulang) atau --semua. Tidak ada yang diubah.');
            return self::FAILURE;
        }

        $terpilih = array_values(array_filter($kandidat, fn ($k) => $this->option('semua') || in_array(mb_strtolower($k['unit']), $unitDipilih, true)));
        $tidakCocok = array_diff($unitDipilih, array_map(fn ($k) => mb_strtolower($k['unit']), $kandidat));
        foreach ($tidakCocok as $u) $this->warn("  Dilewati — unit '{$u}' bukan kandidat (tidak Selesai, atau sudah lunas).");

        foreach ($terpilih as $k) {
            $kk = $k['kk'];
            KavlingKonsumen::query()->whereKey($kk->id)->update(['status' => 'active']);
            activity('transaksi')->performedOn($kk)
                ->withProperties(['sisa_konsumen' => $k['sisa_konsumen'], 'sisa_bank' => $k['sisa_bank'], 'alasan' => $this->option('alasan')])
                ->log("Status Selesai dibuka kembali lewat perintah (sisa konsumen {$rp($k['sisa_konsumen'])}, sisa bank {$rp($k['sisa_bank'])}). Alasan: {$this->option('alasan')}");
            $this->info("  Dibuka kembali: #{$kk->id} {$k['unit']}");
        }
        if ($terpilih) FinanceCache::bump();
        $this->info(count($terpilih) . ' transaksi dibuka kembali.');

        return self::SUCCESS;
    }
}
