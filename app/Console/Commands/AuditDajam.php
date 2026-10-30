<?php

namespace App\Console\Commands;

use App\Models\KavlingKonsumen;
use Illuminate\Console\Command;

/**
 * Audit HANYA-BACA: mengelompokkan transaksi KPR berdasarkan hubungan pencairan, retensi, dan rincian Dajam, supaya terlihat berapa
 * transaksi yang bersih, belum punya pencairan, memiliki Dajam terhitung dua kali, atau masih punya sisa belum dirinci.
 */
class AuditDajam extends Command
{
    protected $signature = 'dajam:audit {--project= : Batasi ke satu proyek (id)} {--contoh=8 : Jumlah contoh per kelompok}';

    protected $description = 'Audit (hanya baca) pencairan KPR, retensi, dan rincian Dajam per transaksi';

    public function handle(): int
    {
        $kelompok = [
            'A. Bersih (pencairan tercatat, retensi sudah dirinci penuh)' => [],
            'B. Belum ada pencairan tercatat (perilaku lama, tidak ada peringatan)' => [],
            'C. Dajam terhitung dua kali (item Dajam melebihi sisa plafon setelah pencairan)' => [],
            'D. Ada sisa retensi yang belum dirinci (perlu follow-up bank)' => [],
        ];
        $kunci = array_keys($kelompok);
        $perluSbum = [];
        $total = 0;

        KavlingKonsumen::query()
            ->whereIn('cara_bayar', ['kpr_subsidi', 'kpr_komersil'])
            ->where('status', '!=', 'cancelled')
            ->when($this->option('project'), fn ($q, $id) => $q->whereHas('kavling', fn ($k) => $k->where('project_id', $id)))
            ->with(['konsumen:id,nama', 'kavling:id,blok,nomor_kavling', 'jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
            ->orderBy('id')
            ->chunkById(100, function ($semua) use (&$kelompok, $kunci, &$perluSbum, &$total) {
                foreach ($semua as $kk) {
                    $pk = $kk->kartuPiutangBreakdown()['pencairan_kpr'];
                    if (!$pk) continue;
                    $total++;
                    $baris = "#{$kk->id} {$kk->konsumen->nama} {$kk->kavling->blok}-{$kk->kavling->nomor_kavling} · plafon " . number_format($pk['plafon_disetujui'], 0, ',', '.')
                        . ' · cair ' . number_format($pk['pencairan_tercatat'], 0, ',', '.') . ' · Dajam ' . number_format($pk['dajam_dirinci'], 0, ',', '.');
                    if ($pk['perlu_sbum']) $perluSbum[] = $baris;

                    $i = !$pk['retensi_aktif'] ? 1 : ($pk['dajam_melebihi'] > 0.5 ? 2 : ($pk['belum_dirinci'] > 0.5 ? 3 : 0));
                    $kelompok[$kunci[$i]][] = $baris;
                }
            });

        $contoh = max(0, (int) $this->option('contoh'));
        $this->info("Diperiksa {$total} transaksi KPR.");
        foreach ($kelompok as $judul => $daftar) {
            $this->line('');
            $this->line("<options=bold>{$judul}</>: " . count($daftar));
            foreach (array_slice($daftar, 0, $contoh) as $b) $this->line("   {$b}");
        }
        $this->line('');
        $this->line('<options=bold>KPR Subsidi sudah akad tanpa SBUM</>: ' . count($perluSbum));
        foreach (array_slice($perluSbum, 0, $contoh) as $b) $this->line("   {$b}");

        return self::SUCCESS;
    }
}
