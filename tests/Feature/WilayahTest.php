<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Models\Wilayah;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Wilayah Indonesia: data referensi, pemilih bertingkat (endpoint), penyimpanan di konsumen, dan kartu domisili Dashboard. */
class WilayahTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');

        foreach ([
            ['31', 'DKI Jakarta', null], ['32', 'Jawa Barat', null],
            ['32.01', 'Kabupaten Bogor', '32'], ['32.04', 'Kabupaten Bandung', '32'], ['32.73', 'Kota Bandung', '32'],
            ['32.73.01', 'Sukasari', '32.73'], ['32.73.02', 'Coblong', '32.73'],
            ['32.73.01.1001', 'Sukarasa', '32.73.01'], ['32.73.01.1002', 'Gegerkalong', '32.73.01'],
            ['31.74', 'Kota Administrasi Jakarta Selatan', '31'],
        ] as [$kode, $nama, $induk]) {
            Wilayah::create(['kode' => $kode, 'level' => substr_count($kode, '.') + 1, 'induk' => $induk, 'nama' => $nama, 'kode_pos' => null]);
        }
    }

    public function test_endpoint_memberi_provinsi_lalu_anak_per_induk(): void
    {
        $prov = $this->actingAs($this->admin)->getJson(route('wilayah.anak'))->assertOk();
        $this->assertSame(['DKI Jakarta', 'Jawa Barat'], collect($prov->json())->pluck('nama')->all());

        $kab = $this->actingAs($this->admin)->getJson(route('wilayah.anak', ['induk' => '32']))->assertOk();
        $this->assertSame(['Kabupaten Bandung', 'Kabupaten Bogor', 'Kota Bandung'], collect($kab->json())->pluck('nama')->all());
        $this->assertStringContainsString('max-age', $kab->headers->get('Cache-Control'));
    }

    public function test_endpoint_menolak_tamu_dan_induk_tidak_wajar(): void
    {
        $this->getJson(route('wilayah.anak'))->assertRedirect(route('login'));
        $this->actingAs($this->admin)->getJson(route('wilayah.anak', ['induk' => "32' OR 1=1"]))->assertSessionHasErrors('induk');
    }

    public function test_atribut_menurunkan_seluruh_rantai_dari_satu_kode(): void
    {
        $a = \App\Support\Wilayah::atribut('32.73.01.1001');
        $this->assertSame(['32', 'Jawa Barat', '32.73', 'Kota Bandung', '32.73.01', 'Sukasari', '32.73.01.1001', 'Sukarasa'],
            [$a['provinsi_kode'], $a['provinsi_nama'], $a['kabupaten_kode'], $a['kabupaten_nama'], $a['kecamatan_kode'], $a['kecamatan_nama'], $a['kelurahan_kode'], $a['kelurahan_nama']]);

        $k = \App\Support\Wilayah::atribut('32.73');
        $this->assertSame('Kota Bandung', $k['kabupaten_nama']);
        $this->assertNull($k['kecamatan_kode']);

        $this->assertNull(\App\Support\Wilayah::atribut(null)['provinsi_kode']);
        $this->expectException(\InvalidArgumentException::class);
        \App\Support\Wilayah::atribut('99.99');
    }

    public function test_cocokkan_nama_persis_longgar_ambigu_dan_tidak_dikenal(): void
    {
        $W = \App\Support\Wilayah::class;
        $this->assertSame(['32.73.01.1001', []], $W::cocokkan('jawa barat', 'KOTA BANDUNG', 'Sukasari', 'Sukarasa'));
        $this->assertSame(['32.01', []], $W::cocokkan('Jawa Barat', 'Bogor', null, null), 'tanpa awalan Kabupaten, hanya satu Bogor');
        $this->assertSame(['31.74', []], $W::cocokkan('DKI Jakarta', 'Kota Jakarta Selatan', null, null), 'awalan Kota Adm. disamakan');
        $this->assertSame([null, []], $W::cocokkan(null, null, null, null));

        [$kode, $err] = $W::cocokkan('Jawa Barat', 'Bandung', null, null);
        $this->assertNull($kode);
        $this->assertStringContainsString('ambigu', $err[0]);

        [, $err] = $W::cocokkan('Jawa Barat', 'Kota Mars', null, null);
        $this->assertStringContainsString('tidak dikenal', $err[0]);

        [, $err] = $W::cocokkan('Jawa Barat', null, 'Sukasari', null);
        $this->assertStringContainsString('Kabupaten/Kota kosong', $err[0]);

        [, $err] = $W::cocokkan('Jawa Barat', 'Kota Bandung', 'Kecamatan Antah Berantah', null);
        $this->assertStringContainsString('Kecamatan', $err[0]);
    }

    public function test_edit_konsumen_menyimpan_nama_dari_server_bukan_dari_klien_dan_bisa_menghapus(): void
    {
        $k = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        $badan = ['nama' => 'Budi', 'wilayah_kode' => '32.73.01.1001', 'provinsi_nama' => 'PALSU', 'kabupaten_nama' => 'PALSU'];

        $this->actingAs($this->admin)->patch(route('konsumens.update', $k), $badan)->assertSessionHasNoErrors();
        $k->refresh();
        $this->assertSame('Jawa Barat', $k->provinsi_nama);
        $this->assertSame('Kota Bandung', $k->kabupaten_nama);
        $this->assertSame('Sukarasa', $k->kelurahan_nama);

        $this->actingAs($this->admin)->patch(route('konsumens.update', $k), ['nama' => 'Budi', 'wilayah_kode' => ''])->assertSessionHasNoErrors();
        $this->assertNull($k->fresh()->provinsi_kode);
        $this->assertNull($k->fresh()->kelurahan_nama);
    }

    public function test_edit_konsumen_menolak_kode_wilayah_tidak_dikenal(): void
    {
        $k = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        $this->actingAs($this->admin)->patch(route('konsumens.update', $k), ['nama' => 'Budi', 'wilayah_kode' => '99.99'])->assertSessionHasErrors('wilayah_kode');
    }

    public function test_edit_tanpa_mengirim_wilayah_tidak_menghapus_wilayah_yang_ada(): void
    {
        $k = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812'] + \App\Support\Wilayah::atribut('32.73'));
        $this->actingAs($this->admin)->patch(route('konsumens.update', $k), ['nama' => 'Budi Baru'])->assertSessionHasNoErrors();
        $this->assertSame('Kota Bandung', $k->fresh()->kabupaten_nama);
    }

    public function test_dashboard_menghitung_asal_domisili_dari_konsumen_aktif(): void
    {
        $project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $project->id, 'nama' => '36/72', 'is_active' => true]);
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        $buat = function (string $no, array $wilayah) use ($project, $tipe, $stage) {
            $unit = Kavling::create(['project_id' => $project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id, 'blok' => 'A', 'nomor_kavling' => $no, 'harga' => 1, 'status_jual' => 'booked']);
            $konsumen = Konsumen::create(['nama' => "K{$no}"] + $wilayah);
            KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'status_penjualan' => 'booking', 'cara_bayar' => 'cash',
                'tanggal_booking' => '2026-03-01', 'harga_dasar' => 1, 'harga_deal' => 1]);
        };
        $buat('1', \App\Support\Wilayah::atribut('32.73.01'));
        $buat('2', \App\Support\Wilayah::atribut('32.73'));
        $buat('3', \App\Support\Wilayah::atribut('32.01'));
        $buat('4', []);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(function ($page) {
            $page->where('domisili.total', 4)->where('domisili.terisi', 3)
                ->where('domisili.teratas.0.nama', 'Kota Bandung')->where('domisili.teratas.0.total', 2)
                ->where('domisili.teratas.1.nama', 'Kabupaten Bogor');
        });
    }

    public function test_alamat_lengkap_menggabungkan_teks_bebas_dan_wilayah_tanpa_mengulang(): void
    {
        $w = \App\Support\Wilayah::atribut('32.73.01.1001');

        $k = new Konsumen(['alamat' => 'Jl. Merdeka No. 1, RT 02/RW 03'] + $w);
        $this->assertSame('Jl. Merdeka No. 1, RT 02/RW 03, Sukarasa, Sukasari, Kota Bandung, Jawa Barat', $k->alamat_lengkap);

        // Alamat penuh lama sudah memuat sebagian wilayah: tidak diulang ("Bandung" cukup untuk "Kota Bandung").
        $lama = new Konsumen(['alamat' => 'Jl. Merdeka 1, Sukarasa, Sukasari, Bandung'] + $w);
        $this->assertSame('Jl. Merdeka 1, Sukarasa, Sukasari, Bandung, Jawa Barat', $lama->alamat_lengkap);

        $this->assertSame('Sukarasa, Sukasari, Kota Bandung, Jawa Barat', (new Konsumen($w))->alamat_lengkap);
        $this->assertSame('Jl. Mawar 5', (new Konsumen(['alamat' => ' Jl. Mawar 5 ']))->alamat_lengkap);
        $this->assertNull((new Konsumen())->alamat_lengkap);
    }

    public function test_variabel_surat_alamat_konsumen_memakai_alamat_lengkap(): void
    {
        $project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $project->id, 'nama' => '36/72', 'is_active' => true]);
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        $unit = Kavling::create(['project_id' => $project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id, 'blok' => 'A', 'nomor_kavling' => '1', 'harga' => 1, 'status_jual' => 'booked']);
        $konsumen = Konsumen::create(['nama' => 'Budi', 'alamat' => 'Jl. Merdeka 1'] + \App\Support\Wilayah::atribut('32.73'));
        $kk = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'status_penjualan' => 'booking', 'cara_bayar' => 'cash',
            'tanggal_booking' => '2026-03-01', 'harga_dasar' => 1, 'harga_deal' => 1]);

        $this->assertSame('Jl. Merdeka 1, Kota Bandung, Jawa Barat', $kk->konsumen->alamat_lengkap);
        $this->assertSame('Jl. Merdeka 1, Kota Bandung, Jawa Barat', $kk->suratPlaceholders()['alamat_konsumen']);
    }
}
