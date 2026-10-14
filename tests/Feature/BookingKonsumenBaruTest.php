<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\SalesAgent;
use App\Models\StatusBangunStage;
use App\Models\SumberLead;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Form booking untuk konsumen baru: alamat opsional tersimpan, dan NIK ganda ditolak dengan pesan jelas (bukan error 500). */
class BookingKonsumenBaruTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;
    private Kavling $unit;
    private SalesAgent $agent;
    private SumberLead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->unit = Kavling::create(['project_id' => $project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $this->sales = User::factory()->create();
        $this->sales->assignRole('admin_sales');
        $this->sales->projects()->attach($project->id);
        $this->agent = SalesAgent::create(['nama' => 'Agen 1', 'tipe' => 'sales', 'is_active' => true]);
        $this->lead = SumberLead::create(['nama' => 'Instagram', 'is_referral' => false, 'is_active' => true]);
    }

    private function booking(array $ubah = [])
    {
        return $this->actingAs($this->sales)->post(route('bookings.store', $this->unit), array_merge([
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'Budi Baru', 'konsumen_no_hp' => '0812', 'konsumen_nik' => '3201000000000010',
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agent->id, 'cara_bayar' => 'cash',
        ], $ubah));
    }

    public function test_alamat_opsional_tersimpan_saat_membuat_konsumen_baru(): void
    {
        $this->booking(['konsumen_alamat' => 'Jl. Melati No. 5, Surabaya'])->assertSessionHas('success');

        $this->assertSame('Jl. Melati No. 5, Surabaya', Konsumen::where('nik', '3201000000000010')->firstOrFail()->alamat);
        $this->assertSame('booked', $this->unit->fresh()->status_jual->value);
    }

    public function test_alamat_boleh_dikosongkan(): void
    {
        $this->booking()->assertSessionHas('success');

        $this->assertNull(Konsumen::where('nik', '3201000000000010')->firstOrFail()->alamat);
    }

    public function test_nik_yang_sudah_terdaftar_ditolak_dengan_pesan_menyebut_pemiliknya_bukan_error_500(): void
    {
        Konsumen::create(['nama' => 'Pemilik NIK', 'nik' => '3201000000000010', 'no_hp' => '0899']);

        $r = $this->booking();

        $r->assertSessionHasErrors('konsumen_nik');
        $this->assertStringContainsString('Pemilik NIK', session('errors')->first('konsumen_nik'));
        $this->assertSame(1, Konsumen::count());                                                // tidak ada konsumen ganda
        $this->assertSame(0, KavlingKonsumen::count());
        $this->assertSame('available', $this->unit->fresh()->status_jual->value);               // unit tetap tersedia
    }

    public function test_nik_milik_konsumen_yang_sudah_dihapus_juga_ditolak_dengan_pesan_jelas(): void
    {
        $lama = Konsumen::create(['nama' => 'Sudah Dihapus', 'nik' => '3201000000000010', 'no_hp' => '0899']);
        $lama->delete();

        $this->booking()->assertSessionHasErrors('konsumen_nik');
        $this->assertStringContainsString('dihapus', session('errors')->first('konsumen_nik'));
    }

    public function test_memilih_konsumen_lama_tidak_terkena_pemeriksaan_nik(): void
    {
        $ada = Konsumen::create(['nama' => 'Konsumen Lama', 'nik' => '3201000000000010', 'no_hp' => '0899', 'pekerjaan' => 'wirausaha', 'status_pernikahan' => 'menikah']);

        $this->actingAs($this->sales)->post(route('bookings.store', $this->unit), [
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_id' => $ada->id, 'sales_agent_id' => $this->agent->id, 'cara_bayar' => 'cash',
        ])->assertSessionHas('success');

        $this->assertSame($ada->id, KavlingKonsumen::firstOrFail()->konsumen_id);
        $this->assertSame(1, Konsumen::count());
    }
}
