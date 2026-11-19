<?php

namespace App\Console\Commands;

use App\Support\SvgBersih;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BersihkanSvg extends Command
{
    protected $signature = 'svg:bersihkan
        {--dry : Hanya laporkan, jangan ubah file}';

    protected $description = 'Bersihkan skrip dan atribut berbahaya dari semua SVG unggahan yang sudah tersimpan (logo, siteplan)';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $dry = (bool) $this->option('dry');
        $diproses = 0;
        $tidakTerbaca = [];

        foreach ($disk->allFiles() as $path) {
            if (!SvgBersih::adalahSvg($path)) {
                continue;
            }

            $bersih = SvgBersih::bersihkan((string) $disk->get($path));

            if ($bersih === null) {
                $tidakTerbaca[] = $path;
                continue;
            }

            if (!$dry) {
                $disk->put($path, $bersih);
            }
            $diproses++;
        }

        $this->info(($dry ? '[dry] ' : '')."{$diproses} file SVG dibersihkan.");

        if ($tidakTerbaca) {
            $this->warn(count($tidakTerbaca).' file SVG tidak terbaca sebagai XML valid dan TIDAK diubah — periksa/hapus manual:');
            foreach ($tidakTerbaca as $path) {
                $this->line("  - {$path}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
