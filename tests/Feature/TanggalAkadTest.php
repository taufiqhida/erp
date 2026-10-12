<?php

namespace Tests\Feature;

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

/** "Lanjutkan ke Akad": tanggal akad bisa dipilih (konsumen lama), default tanggal rencana, tidak boleh di masa depan. */
class TanggalAkadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Kavling $unit;
    private KavlingKonsumen $trx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->unit = Kavling::create(['project_id' => $project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $konsumen = Konsumen::create(['nama' => 'Budi Lama', 'no_hp' => '0812']);
        $this->trx = KavlingKonsumen::create(['kavling_id' => $this->unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active',
            'status_penjualan' => 'rencana_akad', 'cara_bayar' => 'cash', 'tanggal_booking' => now()->subMonths(3)]);
    }

    private function lanjutkan(array $tambahan = [])
    {
        return $this->actingAs($this->admin)->patch(route('bookings.update-status', $this->trx), array_merge(['status_penjualan' => 'akad'], $tambahan));
    }

    public function test_tanggal_akad_bisa_dipilih_untuk_konsumen_lama_tanpa_rencana_akad(): void
    {
        $this->lanjutkan(['tanggal_akad' => '2026-08-15'])->assertSessionHas('success');

        $trx = $this->trx->fresh();
        $this->assertSame('akad', $trx->status_penjualan);
        $this->assertSame('2026-08-15', $trx->tanggal_akad->format('Y-m-d'));
        $this->assertNull($trx->tanggal_rencana_akad);                       // rencana tidak dikarang
        $this->assertSame('sold', $this->unit->fresh()->status_jual->value);
    }

    public function test_tanggal_pilihan_menggantikan_tanggal_rencana_tetapi_rencana_tetap_tersimpan(): void
    {
        $this->trx->update(['tanggal_rencana_akad' => '2026-09-30']);

        $this->lanjutkan(['tanggal_akad' => '2026-09-25'])->assertSessionHas('success');

        $this->assertSame('2026-09-25', $this->trx->fresh()->tanggal_akad->format('Y-m-d'));
        $this->assertSame('2026-09-30', $this->trx->fresh()->tanggal_rencana_akad->format('Y-m-d'));
    }

    public function test_tanpa_pilihan_memakai_tanggal_rencana_seperti_sebelumnya(): void
    {
        $this->trx->update(['tanggal_rencana_akad' => '2026-09-30']);

        $this->lanjutkan()->assertSessionHas('success');

        $this->assertSame('2026-09-30', $this->trx->fresh()->tanggal_akad->format('Y-m-d'));
    }

    public function test_tanpa_pilihan_dan_tanpa_rencana_ditolak(): void
    {
        $this->lanjutkan()->assertStatus(422);

        $this->assertSame('rencana_akad', $this->trx->fresh()->status_penjualan);
        $this->assertSame('booked', $this->unit->fresh()->status_jual->value);
    }

    public function test_tanggal_akad_di_masa_depan_ditolak(): void
    {
        $this->lanjutkan(['tanggal_akad' => now()->addDays(3)->format('Y-m-d')])->assertSessionHasErrors('tanggal_akad');

        $this->assertSame('rencana_akad', $this->trx->fresh()->status_penjualan);
        $this->assertNull($this->trx->fresh()->tanggal_akad);
    }

    public function test_hari_ini_diperbolehkan_dan_peran_tanpa_izin_pipeline_ditolak(): void
    {
        $keuangan = User::factory()->create();
        $keuangan->assignRole('admin_keuangan');
        $this->actingAs($keuangan)->patch(route('bookings.update-status', $this->trx), ['status_penjualan' => 'akad', 'tanggal_akad' => now()->format('Y-m-d')])->assertStatus(403);

        $this->lanjutkan(['tanggal_akad' => now()->format('Y-m-d')])->assertSessionHas('success');
        $this->assertSame(now()->format('Y-m-d'), $this->trx->fresh()->tanggal_akad->format('Y-m-d'));
    }
}
