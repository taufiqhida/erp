<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Memuat database/data/wilayah.csv.gz ke tabel `wilayah` (mengganti isinya). Data konsumen yang sudah menyimpan wilayah tidak tersentuh. */
class ImporWilayah extends Command
{
    protected $signature = 'wilayah:impor {--berkas= : Path berkas CSV terkompres (default database/data/wilayah.csv.gz)}';

    protected $description = 'Muat data wilayah Indonesia dari berkas CSV terkompres ke tabel wilayah';

    public function handle(): int
    {
        $berkas = $this->option('berkas') ?: database_path('data/wilayah.csv.gz');
        if (!is_file($berkas)) {
            $this->error("Berkas {$berkas} tidak ditemukan. Jalankan `php artisan wilayah:unduh` dulu.");

            return self::FAILURE;
        }

        $fh = gzopen($berkas, 'rb');
        $paket = [];
        $jumlah = 0;
        DB::transaction(function () use ($fh, &$paket, &$jumlah) {
            DB::table('wilayah')->delete();
            while (($baris = gzgets($fh)) !== false) {
                $baris = rtrim($baris, "\r\n");
                if ($baris === '') continue;
                [$kode, $nama, $pos] = array_pad(explode(';', $baris, 3), 3, '');
                $segmen = substr_count($kode, '.') + 1;
                $paket[] = [
                    'kode' => $kode,
                    'level' => $segmen,
                    'induk' => $segmen > 1 ? substr($kode, 0, strrpos($kode, '.')) : null,
                    'nama' => $nama,
                    'kode_pos' => $pos !== '' ? $pos : null,
                ];
                if (count($paket) >= 2000) {
                    DB::table('wilayah')->insert($paket);
                    $jumlah += count($paket);
                    $paket = [];
                }
            }
            if ($paket) {
                DB::table('wilayah')->insert($paket);
                $jumlah += count($paket);
            }
        });
        gzclose($fh);

        $this->info("{$jumlah} baris wilayah dimuat.");

        return self::SUCCESS;
    }
}
