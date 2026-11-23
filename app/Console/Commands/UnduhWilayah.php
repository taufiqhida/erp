<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Mengambil data wilayah Indonesia (provinsi, kabupaten/kota, kecamatan, kelurahan/desa) dari API statis emsifa/api-wilayah-indonesia
 * dan menyimpannya sebagai berkas CSV terkompres di repositori (database/data/wilayah.csv.gz). Aplikasi TIDAK memanggil API itu saat berjalan:
 * data dimuat ke tabel `wilayah` lewat `wilayah:impor`. Jalankan perintah ini hanya untuk memperbarui data (sekitar 7.800 permintaan).
 */
class UnduhWilayah extends Command
{
    protected $signature = 'wilayah:unduh {--keluaran= : Path berkas keluaran (default database/data/wilayah.csv.gz)} {--sumber=https://www.emsifa.com/api-wilayah-indonesia/v2}';

    protected $description = 'Unduh data wilayah Indonesia dari API emsifa ke berkas CSV terkompres (untuk pembaruan data, bukan untuk dijalankan rutin)';

    public function handle(): int
    {
        $sumber = rtrim((string) $this->option('sumber'), '/');
        $keluaran = $this->option('keluaran') ?: database_path('data/wilayah.csv.gz');
        @mkdir(dirname($keluaran), 0775, true);

        $ambil = function (array $urls) use ($sumber): array {
            $hasil = [];
            foreach (array_chunk($urls, 20, true) as $kelompok) {
                $respons = Http::pool(fn (Pool $pool) => collect($kelompok)->map(fn ($u, $kunci) => $pool->as((string) $kunci)->retry(4, 800, throw: false)->timeout(40)->get("{$sumber}/{$u}"))->all());
                foreach ($kelompok as $kunci => $u) {
                    $r = $respons[(string) $kunci] ?? null;
                    if (!$r instanceof \Illuminate\Http\Client\Response || !$r->ok()) {
                        throw new \RuntimeException("Gagal mengambil {$u}: " . ($r instanceof \Illuminate\Http\Client\Response ? $r->status() : 'tanpa respons'));
                    }
                    $hasil[$kunci] = $r->json('data') ?? [];
                }
            }

            return $hasil;
        };

        $baris = [];   // kode => [nama, kodepos]
        $this->info('Provinsi...');
        $provinsi = $ambil(['p' => 'provinces.json'])['p'];
        foreach ($provinsi as $p) $baris[$p['id']] = [$p['name'], ''];

        $this->info('Kabupaten/Kota...');
        $kab = $ambil(collect($provinsi)->mapWithKeys(fn ($p) => [$p['id'] => "regencies/{$p['id']}.json"])->all());
        $kabIds = [];
        foreach ($kab as $daftar) foreach ($daftar as $k) { $baris[$k['id']] = [$k['name'], '']; $kabIds[] = $k['id']; }

        $this->info('Kecamatan (' . count($kabIds) . ' permintaan)...');
        $kec = $ambil(collect($kabIds)->mapWithKeys(fn ($id) => [$id => "districts/{$id}.json"])->all());
        $kecIds = [];
        foreach ($kec as $daftar) foreach ($daftar as $k) { $baris[$k['id']] = [$k['name'], '']; $kecIds[] = $k['id']; }

        $this->info('Kelurahan/Desa (' . count($kecIds) . ' permintaan, beberapa menit)...');
        $bar = $this->output->createProgressBar(count($kecIds));
        foreach (array_chunk($kecIds, 400) as $paket) {
            $kel = $ambil(collect($paket)->mapWithKeys(fn ($id) => [$id => "villages/{$id}.json"])->all());
            foreach ($kel as $daftar) foreach ($daftar as $k) $baris[$k['id']] = [$k['name'], (string) ($k['postal_code'] ?? '')];
            $bar->advance(count($paket));
        }
        $bar->finish();
        $this->newLine();

        ksort($baris, SORT_NATURAL);
        $fh = gzopen($keluaran, 'wb9');
        foreach ($baris as $kode => [$nama, $pos]) gzwrite($fh, implode(';', [$kode, str_replace(';', ',', $nama), $pos]) . "\n");
        gzclose($fh);

        $this->info(count($baris) . " baris ditulis ke {$keluaran} (" . round(filesize($keluaran) / 1024) . ' KB).');

        return self::SUCCESS;
    }
}
