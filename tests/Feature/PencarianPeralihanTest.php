<?php

namespace Tests\Feature;

use App\Models\AlihPembeliRiwayat;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pembeli yang transaksinya dialihkan harus tetap bisa ditemukan di daftar Konsumen (Per Konsumen dan Per Unit). */
class PencarianPeralihanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private Konsumen $asli;
    private Konsumen $nama;
    private KavlingKonsumen $trx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id, 'blok' => 'A1', 'nomor_kavling' => '5', 'harga' => 1, 'status_jual' => 'booked']);

        $this->asli = Konsumen::create(['nama' => 'Andi Asli', 'no_hp' => '085700000001', 'nik' => '3201010101900001']);
        $this->nama = Konsumen::create(['nama' => 'Budi Nama', 'no_hp' => '081200000002']);
        $this->trx = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $this->nama->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
            'cara_bayar' => 'cash', 'tanggal_booking' => '2026-03-01', 'harga_dasar' => 1, 'harga_deal' => 1]);
        AlihPembeliRiwayat::create(['kavling_konsumen_id' => $this->trx->id, 'dari_konsumen_id' => $this->asli->id, 'ke_konsumen_id' => $this->nama->id,
            'hubungan' => 'saudara', 'alasan' => 'BI checking', 'disetujui_at' => now()]);
    }

    private function daftar(array $query, ?User $oleh = null, bool $proyekAktif = true)
    {
        $r = $this->actingAs($oleh ?? $this->admin);
        if ($proyekAktif) $r = $r->withSession(['current_project_id' => $this->project->id]);
        $hasil = null;
        $r->get(route('konsumens.index', $query))->assertInertia(function ($page) use (&$hasil) {
            $hasil = $page->toArray()['props']['rows']['data'];
        });

        return collect($hasil);
    }

    public function test_per_konsumen_menampilkan_pembeli_yang_dialihkan_saat_dicentang_walau_ada_proyek_aktif(): void
    {
        $baris = $this->daftar(['view' => 'konsumen', 'tampilkan_nonaktif' => 1]);

        $asli = $baris->firstWhere('id', $this->asli->id);
        $this->assertNotNull($asli, 'pembeli yang dialihkan harus muncul');
        $this->assertSame([['unit' => 'A1-5', 'ke' => 'Budi Nama', 'ke_id' => $this->nama->id]], $asli['dialihkan_ke']);
    }

    public function test_per_konsumen_menemukan_pembeli_yang_dialihkan_saat_mencari_tanpa_mencentang(): void
    {
        foreach (['Andi', '085700000001', '3201010101900001'] as $kata) {
            $this->assertSame([$this->asli->id], $this->daftar(['view' => 'konsumen', 'search' => $kata])->pluck('id')->all(), "pencarian '{$kata}'");
        }
    }

    public function test_per_konsumen_tanpa_pencarian_dan_tanpa_centang_tetap_menyembunyikannya(): void
    {
        $this->assertSame([$this->nama->id], $this->daftar(['view' => 'konsumen'])->pluck('id')->all());
    }

    public function test_per_unit_mencari_nama_pembeli_sebelumnya_menemukan_transaksinya(): void
    {
        foreach (['Andi', '085700000001', 'Budi'] as $kata) {
            $baris = $this->daftar(['view' => 'unit', 'search' => $kata]);
            $this->assertSame([$this->trx->id], $baris->pluck('id')->all(), "pencarian '{$kata}'");
        }
        $this->assertSame('Andi Asli', $this->daftar(['view' => 'unit', 'search' => 'Andi'])->first()['peralihan_dari']);
        $this->assertCount(0, $this->daftar(['view' => 'unit', 'search' => 'Tidak Ada']));
    }

    public function test_pembeli_dialihkan_tidak_terlihat_oleh_pengguna_dari_proyek_lain(): void
    {
        $lain = Project::create(['nama' => 'Lain', 'kode' => 'L1', 'kota' => 'Y', 'is_active' => true]);
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($lain->id);

        $this->assertNotContains($this->asli->id, $this->daftar(['view' => 'konsumen', 'tampilkan_nonaktif' => 1], $sales, false)->pluck('id')->all());
        $this->assertNotContains($this->asli->id, $this->daftar(['view' => 'konsumen', 'search' => 'Andi'], $sales, false)->pluck('id')->all());

        $sales->projects()->attach($this->project->id);
        $this->assertContains($this->asli->id, $this->daftar(['view' => 'konsumen', 'search' => 'Andi'], $sales->fresh(), false)->pluck('id')->all());
    }
}
