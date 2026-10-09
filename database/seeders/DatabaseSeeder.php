<?php

namespace Database\Seeders;

use App\Enums\StatusJual;
use App\Models\DeveloperProfile;
use App\Models\DokumenTemplate;
use App\Models\Kavling;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Roles & Permissions
        $this->call(RolesAndPermissionsSeeder::class);

        // 1b. Master baku (Sumber Lead, Template Pemberkasan) — lihat MasterAwalSeeder
        $this->call(MasterAwalSeeder::class);

        // 2. Users sungguhan (menggantikan 6 dummy lama — lihat RealUsersSeeder)
        $this->call(RealUsersSeeder::class);

        $admin    = User::where('email', 'annaz@erp.local')->first();   // superadmin
        $manajer  = User::where('email', 'ferry@erp.local')->first();   // manager
        $sales    = User::where('email', 'melza@erp.local')->first();  // admin_sales
        $lapangan = User::where('email', 'fathoni@erp.local')->first(); // pelaksana_lapangan
        $finance  = User::where('email', 'nia@erp.local')->first();     // admin_keuangan
        $staffKpr = User::where('email', 'yunita@erp.local')->first();  // admin_pemberkasan

        // 3. Projects
        $project1 = Project::firstOrCreate(
            ['kode' => 'VILLA-01'],
            [
                'nama'                     => 'Villa Hijau Asri',
                'deskripsi'                => 'Perumahan eksklusif dengan konsep hijau dan modern.',
                'lokasi'                   => 'Jl. Raya Ciputat No. 88',
                'kota'                     => 'Tangerang Selatan',
                'luas_tanah_total'         => 15000,
                'is_active'                => true,
                'created_by'               => $admin->id,
            ]
        );
        $project1->users()->syncWithoutDetaching([$admin->id, $manajer->id, $sales->id, $finance->id, $staffKpr->id]);

        $project2 = Project::firstOrCreate(
            ['kode' => 'CLUSTER-01'],
            [
                'nama'                     => 'Cluster Permata Biru',
                'deskripsi'                => 'Cluster modern dengan fasilitas lengkap.',
                'lokasi'                   => 'Jl. BSD Raya Utama No. 1',
                'kota'                     => 'Tangerang',
                'luas_tanah_total'         => 9000,
                'is_active'                => true,
                'created_by'               => $admin->id,
            ]
        );
        $project2->users()->syncWithoutDetaching([$admin->id, $manajer->id]);

        // 4. Kavlings
        $stageByNama = StatusBangunStage::ordered()->get()->keyBy('nama');
        $defaultStageId = $stageByNama->firstWhere('is_default', true)?->id ?? StatusBangunStage::defaultStage()->id;

        // Nomor kavling harus unik per project (bukan per blok)
        $kavlingData = [
            ['blok' => 'A', 'nomor' => 'A01', 'luas_t' => 120, 'luas_b' => 72, 'harga' => 850_000_000, 'status_jual' => StatusJual::Sold, 'status_bangun' => 'Finishing'],
            ['blok' => 'A', 'nomor' => 'A02', 'luas_t' => 110, 'luas_b' => 60, 'harga' => 780_000_000, 'status_jual' => StatusJual::Booked, 'status_bangun' => 'Struktur'],
            ['blok' => 'A', 'nomor' => 'A03', 'luas_t' => 115, 'luas_b' => 72, 'harga' => 820_000_000, 'status_jual' => StatusJual::Available, 'status_bangun' => 'Pondasi'],
            ['blok' => 'B', 'nomor' => 'B01', 'luas_t' => 130, 'luas_b' => 80, 'harga' => 920_000_000, 'status_jual' => StatusJual::Available, 'status_bangun' => 'Belum Mulai'],
            ['blok' => 'B', 'nomor' => 'B02', 'luas_t' => 125, 'luas_b' => 75, 'harga' => 880_000_000, 'status_jual' => StatusJual::Hold, 'status_bangun' => 'Belum Mulai'],
            ['blok' => 'B', 'nomor' => 'B03', 'luas_t' => 120, 'luas_b' => 72, 'harga' => 860_000_000, 'status_jual' => StatusJual::Available, 'status_bangun' => 'Belum Mulai'],
        ];

        foreach ($kavlingData as $kd) {
            $tipe = TipeUnitPreset::firstOrCreate(
                ['project_id' => $project1->id, 'nama' => "{$kd['luas_b']}/{$kd['luas_t']}"],
                ['luas_tanah' => $kd['luas_t'], 'luas_bangunan' => $kd['luas_b']]
            );

            Kavling::firstOrCreate(
                ['project_id' => $project1->id, 'nomor_kavling' => $kd['nomor']],
                [
                    'blok'                   => $kd['blok'],
                    'tipe_unit_preset_id'    => $tipe->id,
                    'harga'                  => $kd['harga'],
                    'status_jual'            => $kd['status_jual'],
                    'status_bangun_stage_id' => $stageByNama[$kd['status_bangun']]->id ?? $defaultStageId,
                ]
            );
        }

        // Project 2 kavlings
        for ($i = 1; $i <= 4; $i++) {
            $luasTanah = 100 + ($i * 5);
            $luasBangunan = 60 + ($i * 3);
            $tipe = TipeUnitPreset::firstOrCreate(
                ['project_id' => $project2->id, 'nama' => "{$luasBangunan}/{$luasTanah}"],
                ['luas_tanah' => $luasTanah, 'luas_bangunan' => $luasBangunan]
            );

            Kavling::firstOrCreate(
                ['project_id' => $project2->id, 'nomor_kavling' => str_pad($i, 2, '0', STR_PAD_LEFT), 'blok' => 'A'],
                [
                    'tipe_unit_preset_id'    => $tipe->id,
                    'harga'                  => 700_000_000 + ($i * 50_000_000),
                    'status_jual'            => StatusJual::Available,
                    'status_bangun_stage_id' => $defaultStageId,
                ]
            );
        }

        // 5. Sample Konsumens
        $konsumen1 = Konsumen::firstOrCreate(
            ['nik' => '3271010101800001'],
            [
                'nama'      => 'Ahmad Fauzan',
                'no_hp'     => '081234567890',
                'email'     => 'ahmad@example.com',
                'pekerjaan' => 'Wiraswasta',
                'alamat'    => 'Jl. Kebon Jeruk No. 5, Jakarta Barat',
            ]
        );

        $konsumen2 = Konsumen::firstOrCreate(
            ['nik' => '3271020202850002'],
            [
                'nama'      => 'Rina Wulandari',
                'no_hp'     => '089876543210',
                'email'     => 'rina@example.com',
                'pekerjaan' => 'Pegawai Swasta',
                'alamat'    => 'Jl. Menteng No. 10, Jakarta Pusat',
            ]
        );

        // 6. Sample transactions
        $soldKavling = Kavling::where('project_id', $project1->id)
            ->where('status_jual', StatusJual::Sold)->first();

        if ($soldKavling && !$soldKavling->activeTransaction) {
            \App\Models\KavlingKonsumen::create([
                'kavling_id'   => $soldKavling->id,
                'konsumen_id'  => $konsumen1->id,
                'status'       => 'active',
                'tanggal_akad' => '2025-03-15',
                'harga_deal'   => $soldKavling->harga,
                'created_by'   => $sales->id,
            ]);
        }

        $bookedKavling = Kavling::where('project_id', $project1->id)
            ->where('status_jual', StatusJual::Booked)->first();

        if ($bookedKavling && !$bookedKavling->activeTransaction) {
            \App\Models\KavlingKonsumen::create([
                'kavling_id'   => $bookedKavling->id,
                'konsumen_id'  => $konsumen2->id,
                'status'       => 'active',
                'tanggal_akad' => null,
                'harga_deal'   => $bookedKavling->harga,
                'created_by'   => $sales->id,
            ]);
        }

        $this->command->info('✅ Seeder selesai!');
        $this->command->info('👤 Semua login pakai password: password');
        $this->command->info('   annaz@erp.local (superadmin)      toro@erp.local (superadmin)');
        $this->command->info('   ferry@erp.local (manager)         icha@erp.local (manager)        eko@erp.local (manager)');
        $this->command->info('   umi@erp.local (spv)                ridwan@erp.local (leader)');
        $this->command->info('   melza@erp.local (admin_sales)      yunita@erp.local (admin_pemberkasan)');
        $this->command->info('   dinda@erp.local (admin_proyek)     fathoni@erp.local (pelaksana_lapangan)');
        $this->command->info('   nia@erp.local (admin_keuangan)');

        // 7. Profil Developer (default)
        DeveloperProfile::firstOrCreate(['id' => 1], [
            'nama_developer' => 'PT. Properti Makmur Indonesia',
            'alamat'         => 'Jl. Contoh No. 1, Jakarta Selatan',
            'telepon'        => '021-12345678',
        ]);

        // 8. Template Dokumen Pemberkasan Default
        $templates = [
            // KPR Subsidi
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'KTP Pemohon',             'sifat' => 'wajib',       'urutan' => 1],
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'KTP Pasangan',             'sifat' => 'kondisional', 'urutan' => 2],
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Kartu Keluarga (KK)',      'sifat' => 'wajib',       'urutan' => 3],
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Buku Nikah / Akta Cerai',  'sifat' => 'kondisional', 'urutan' => 4],
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'NPWP',                     'sifat' => 'wajib',       'urutan' => 5],
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Slip Gaji 3 Bulan Terakhir', 'sifat' => 'wajib',    'urutan' => 6],
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Surat Keterangan Kerja',   'sifat' => 'wajib',       'urutan' => 7],
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Pas Foto 3x4',             'sifat' => 'wajib',       'urutan' => 8],
            ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Surat Pernyataan Belum Punya Rumah', 'sifat' => 'wajib', 'urutan' => 9],
            // KPR Komersil
            ['cara_bayar' => 'kpr_komersil', 'nama_dokumen' => 'KTP Pemohon',              'sifat' => 'wajib',       'urutan' => 1],
            ['cara_bayar' => 'kpr_komersil', 'nama_dokumen' => 'KTP Pasangan',              'sifat' => 'kondisional', 'urutan' => 2],
            ['cara_bayar' => 'kpr_komersil', 'nama_dokumen' => 'Kartu Keluarga (KK)',       'sifat' => 'wajib',       'urutan' => 3],
            ['cara_bayar' => 'kpr_komersil', 'nama_dokumen' => 'NPWP',                      'sifat' => 'wajib',       'urutan' => 4],
            ['cara_bayar' => 'kpr_komersil', 'nama_dokumen' => 'Slip Gaji 3 Bulan Terakhir','sifat' => 'wajib',       'urutan' => 5],
            ['cara_bayar' => 'kpr_komersil', 'nama_dokumen' => 'Rekening Koran 3 Bulan',   'sifat' => 'wajib',       'urutan' => 6],
            ['cara_bayar' => 'kpr_komersil', 'nama_dokumen' => 'Surat Keterangan Kerja',    'sifat' => 'wajib',       'urutan' => 7],
            // Cash Bertahap
            ['cara_bayar' => 'cash_bertahap', 'nama_dokumen' => 'KTP Pemohon',              'sifat' => 'wajib',       'urutan' => 1],
            ['cara_bayar' => 'cash_bertahap', 'nama_dokumen' => 'Kartu Keluarga (KK)',       'sifat' => 'wajib',       'urutan' => 2],
            ['cara_bayar' => 'cash_bertahap', 'nama_dokumen' => 'NPWP',                      'sifat' => 'opsional',    'urutan' => 3],
            // Cash
            ['cara_bayar' => 'cash', 'nama_dokumen' => 'KTP Pemohon',                        'sifat' => 'wajib',       'urutan' => 1],
            ['cara_bayar' => 'cash', 'nama_dokumen' => 'Kartu Keluarga (KK)',                 'sifat' => 'kondisional', 'urutan' => 2],
        ];

        foreach ($templates as $tmpl) {
            DokumenTemplate::firstOrCreate(
                ['cara_bayar' => $tmpl['cara_bayar'], 'nama_dokumen' => $tmpl['nama_dokumen']],
                ['sifat' => $tmpl['sifat'], 'urutan' => $tmpl['urutan']]
            );
        }

        $this->command->info('📋 Template dokumen berhasil dibuat.');
    }
}
