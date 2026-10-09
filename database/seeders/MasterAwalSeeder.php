<?php

namespace Database\Seeders;

use App\Support\PindahMaster;
use Illuminate\Database\Seeder;

/**
 * Isi awal master yang BAKU untuk server baru: Sumber Lead dan Template Pemberkasan (daftar dokumen per cara bayar).
 * Datanya di database/data/master-awal.json (format yang sama dengan Pengaturan → Pindah Master Data, jadi file itu juga
 * bisa diimpor lewat halaman tersebut dengan pratinjau dulu).
 *
 * Aman diulang: dicocokkan lewat nama; yang sudah ada TIDAK ditimpa (perubahan di layar tetap utuh), yang belum ada ditambah.
 * Master khas perusahaan (bank, skema DP, promo, dst.) sengaja tidak di sini — pindahkan lewat Pindah Master Data.
 *
 *   php artisan db:seed --class=MasterAwalSeeder --force
 */
class MasterAwalSeeder extends Seeder
{
    public function run(): void
    {
        $payload = json_decode((string) file_get_contents(database_path('data/master-awal.json')), true);

        if ($galat = PindahMaster::periksaPayload($payload)) {
            $this->command?->error("master-awal.json tidak valid: {$galat}");

            return;
        }

        $r = PindahMaster::proses($payload, array_keys($payload['data']), false, true);

        if (!$r['diterapkan']) {
            $this->command?->error('Master awal tidak diterapkan: ' . implode(' | ', $r['masalah']));

            return;
        }

        foreach ($r['hasil'] as $key => $h) {
            $label = PindahMaster::definisi()[$key]['label'];
            $this->command?->info("{$label}: {$h['baru']} baru, {$h['sama']} sudah ada.");
        }
    }
}
