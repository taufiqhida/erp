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

/**
 * Aturan validasi yang ditemukan lewat uji manual (4 Okt 2026):
 * pembayaran tidak boleh melebihi tagihan, expired SP3K tidak boleh sebelum tanggal terbit.
 */
class ValidasiTransaksiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private KavlingKonsumen $kk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');

        $project = Project::create(['nama' => 'T', 'kode' => 'T1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $project->id, 'nama' => '36', 'is_active' => true]);
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        $kavling = Kavling::create([
            'project_id' => $project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id,
            'nomor_kavling' => '1', 'blok' => 'A', 'harga' => 1, 'status_jual' => 'booked',
        ]);
        $konsumen = Konsumen::create(['nama' => 'Uji', 'nik' => '1234567890123456', 'no_hp' => '0800']);
        $this->kk = KavlingKonsumen::create([
            'kavling_id' => $kavling->id, 'konsumen_id' => $konsumen->id, 'tanggal_booking' => now(),
            'harga_deal' => 100_000_000, 'harga_dasar' => 100_000_000, 'cara_bayar' => 'cash',
            'status_penjualan' => 'sp3k', 'created_by' => $this->admin->id,
        ]);
    }

    public function test_pembayaran_cicilan_melebihi_tagihan_ditolak(): void
    {
        $jadwal = $this->kk->jadwalTagihans()->create([
            'jenis' => 'dp', 'nomor_cicilan' => 1, 'jumlah' => 10_000_000,
            'tanggal_jatuh_tempo' => now()->addMonth(), 'status' => 'belum_bayar',
        ]);

        $this->actingAs($this->admin)
            ->post(route('jadwal-tagihan.bayar', $jadwal), ['jumlah' => 99_999_999, 'tanggal_bayar' => now()->toDateString()])
            ->assertSessionHasErrors('jumlah');
        $this->assertSame(0, $this->kk->pembayarans()->count());

        $this->actingAs($this->admin)
            ->post(route('jadwal-tagihan.bayar', $jadwal), ['jumlah' => 10_000_000, 'tanggal_bayar' => now()->toDateString()])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('lunas', $jadwal->fresh()->status);
    }

    public function test_kurang_bayar_tetap_diterima_sebagai_sebagian(): void
    {
        $jadwal = $this->kk->jadwalTagihans()->create([
            'jenis' => 'dp', 'nomor_cicilan' => 1, 'jumlah' => 10_000_000,
            'tanggal_jatuh_tempo' => now()->addMonth(), 'status' => 'belum_bayar',
        ]);

        $this->actingAs($this->admin)
            ->post(route('jadwal-tagihan.bayar', $jadwal), ['jumlah' => 4_000_000, 'tanggal_bayar' => now()->toDateString()])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('sebagian', $jadwal->fresh()->status);
    }

    public function test_expired_sp3k_sebelum_tanggal_terbit_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('bookings.sp3k-decision', $this->kk), [
                'status_sp3k' => 'approved', 'tanggal_sp3k' => '2026-10-10', 'tanggal_expired_sp3k' => '2026-10-01',
            ])
            ->assertSessionHasErrors('tanggal_expired_sp3k');
        $this->assertSame('sp3k', $this->kk->fresh()->status_penjualan);

        $this->actingAs($this->admin)
            ->patch(route('bookings.sp3k-decision', $this->kk), [
                'status_sp3k' => 'approved', 'tanggal_sp3k' => '2026-10-10', 'tanggal_expired_sp3k' => '2027-01-10',
            ])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('rencana_akad', $this->kk->fresh()->status_penjualan);
    }
}
