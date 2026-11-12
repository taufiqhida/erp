<?php

namespace Tests\Feature;

use App\Enums\StatusJual;
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

/**
 * Siteplan/Tabel Unit di Penjualan: unit yang transaksinya sudah Selesai (completed, mis. hasil import yang piutangnya lunas) tetap
 * menampilkan nama pembeli dan id transaksi (tombol lihat pesanan). Transaksi batal tidak.
 */
class UnitSelesaiTetapTampilPembeliTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private TipeUnitPreset $tipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Proyek Uji', 'kode' => 'PU1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
    }

    private function unitDenganTransaksi(string $nomor, string $statusTransaksi, StatusJual $statusJual): array
    {
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id,
            'blok' => 'A1', 'nomor_kavling' => $nomor, 'harga' => 166000000, 'status_jual' => $statusJual]);
        $konsumen = Konsumen::create(['nama' => "Pembeli {$nomor}", 'no_hp' => '0812']);
        $trx = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $konsumen->id, 'status' => $statusTransaksi, 'status_penjualan' => 'bast',
            'cara_bayar' => 'cash', 'tanggal_booking' => '2026-01-10', 'harga_dasar' => 166000000, 'harga_deal' => 166000000]);

        return [$unit, $trx];
    }

    private function baris(string $tampilan): array
    {
        $r = $this->actingAs($this->admin)->get(route('penjualan.project', ['project' => $this->project->id] + ($tampilan === 'tabel' ? ['tampilan' => 'tabel'] : [])))->assertOk();
        $props = $r->viewData('page')['props'];

        return collect($tampilan === 'tabel' ? $props['kavlingsPage']['data'] : $props['kavlings'])->keyBy('nomor_kavling')->all();
    }

    public function test_siteplan_menampilkan_pembeli_untuk_transaksi_berjalan_dan_selesai_tetapi_tidak_untuk_batal(): void
    {
        [, $aktif] = $this->unitDenganTransaksi('1', 'active', StatusJual::Booked);
        [, $selesai] = $this->unitDenganTransaksi('2', 'completed', StatusJual::Sold);
        $this->unitDenganTransaksi('3', 'cancelled', StatusJual::Available);

        $baris = $this->baris('siteplan');

        $this->assertSame('Pembeli 1', $baris['1']['konsumen_nama']);
        $this->assertSame($aktif->id, $baris['1']['transaksi_id']);
        $this->assertSame('Pembeli 2', $baris['2']['konsumen_nama']);                 // Selesai tetap tampil
        $this->assertSame($selesai->id, $baris['2']['transaksi_id']);                 // dan tombol lihat pesanan punya tujuan
        $this->assertNull($baris['3']['konsumen_nama']);                               // batal: unit kosong lagi
        $this->assertNull($baris['3']['transaksi_id']);
    }

    public function test_tabel_unit_juga_menampilkan_pembeli_transaksi_selesai(): void
    {
        $this->unitDenganTransaksi('2', 'completed', StatusJual::Sold);

        $this->assertSame('Pembeli 2', $this->baris('tabel')['2']['konsumen_nama']);
    }
}
