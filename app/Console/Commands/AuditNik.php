<?php

namespace App\Console\Commands;

use App\Models\Konsumen;
use App\Support\Nik;
use Illuminate\Console\Command;

/**
 * Audit NIK konsumen yang sudah tersimpan terhadap aturan Nik::periksa(). Default HANYA MELAPORKAN (baca saja).
 * --terapkan hanya merapikan yang kosmetik: spasi/titik/strip dibuang, dan hanya bila hasilnya NIK valid yang belum dipakai konsumen lain.
 * NIK yang rusak sungguhan (kurang digit, notasi ilmiah, dst.) tidak disentuh — perbaiki lewat Import Perbarui atau form Konsumen.
 */
class AuditNik extends Command
{
    protected $signature = 'nik:audit {--terapkan : Rapikan NIK yang hanya bermasalah kosmetik (spasi/titik/strip)} {--csv= : Tulis daftar masalah ke file CSV}';

    protected $description = 'Laporkan NIK konsumen yang tidak sesuai aturan (baca saja; --terapkan hanya merapikan pemisah kosmetik)';

    public function handle(): int
    {
        $total = 0;
        $valid = 0;
        $kosong = 0;
        $kosmetik = [];
        $rusak = [];

        Konsumen::query()->orderBy('id')->each(function (Konsumen $k) use (&$total, &$valid, &$kosong, &$kosmetik, &$rusak) {
            $total++;
            if (blank($k->nik)) { $kosong++; return; }
            $pesan = Nik::periksa($k->nik);
            if ($pesan === null) { $valid++; return; }

            $bersih = Nik::bersihkan($k->nik);
            if ($bersih !== $k->nik && Nik::periksa($bersih) === null) {
                $kosmetik[] = [$k, $bersih];

                return;
            }
            $rusak[] = [$k, $pesan];
        });

        $this->info("Konsumen: {$total} | NIK valid: {$valid} | NIK kosong: {$kosong} | bisa dirapikan: " . count($kosmetik) . ' | bermasalah: ' . count($rusak));

        if ($kosmetik) {
            $this->newLine();
            $this->line('Bisa dirapikan otomatis (pemisah kosmetik):');
            foreach ($kosmetik as [$k, $bersih]) $this->line("  #{$k->id} {$k->nama}: '{$k->nik}' -> {$bersih}");
        }

        if ($rusak) {
            $kelompok = collect($rusak)->groupBy(fn ($r) => match (true) {
                str_contains($r[1], 'notasi ilmiah') => 'Notasi ilmiah Excel (angka terpotong)',
                str_contains($r[1], 'harus tepat 16 digit') => 'Bukan 16 digit',
                str_contains($r[1], 'angka saja') => 'Mengandung huruf/simbol',
                str_contains($r[1], 'semua digit sama') => 'Semua digit sama',
                str_contains($r[1], '0000') => '4 digit terakhir 0000',
                str_contains($r[1], 'provinsi') => 'Kode provinsi tidak dikenal',
                default => 'Tanggal/bulan lahir tidak valid',
            });
            $this->newLine();
            $this->line('Bermasalah (perlu dikoreksi manual):');
            foreach ($kelompok as $judul => $baris) {
                $this->line("  {$judul}: " . count($baris));
                foreach ($baris as [$k]) $this->line("    #{$k->id} {$k->nama}: '{$k->nik}'");
            }
        }

        if ($csv = $this->option('csv')) {
            $fh = fopen($csv, 'w');
            fputcsv($fh, ['id', 'nama', 'nik', 'masalah']);
            foreach ($rusak as [$k, $pesan]) fputcsv($fh, [$k->id, $k->nama, "'" . $k->nik, $pesan]);
            foreach ($kosmetik as [$k, $bersih]) fputcsv($fh, [$k->id, $k->nama, "'" . $k->nik, "Bisa dirapikan menjadi {$bersih}"]);
            fclose($fh);
            $this->info("Daftar masalah ditulis ke {$csv}");
        }

        if (!$this->option('terapkan')) {
            if ($kosmetik) $this->comment('Belum ada yang diubah. Jalankan dengan --terapkan untuk merapikan ' . count($kosmetik) . ' NIK kosmetik.');

            return self::SUCCESS;
        }

        $diubah = 0;
        foreach ($kosmetik as [$k, $bersih]) {
            if (Konsumen::withTrashed()->where('nik', $bersih)->where('id', '!=', $k->id)->exists()) {
                $this->warn("  #{$k->id} dilewati: NIK {$bersih} sudah dipakai konsumen lain.");
                continue;
            }
            $k->update(['nik' => $bersih]);
            $diubah++;
        }
        $this->info("Dirapikan: {$diubah} NIK.");

        return self::SUCCESS;
    }
}
