<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\ProgramAllInPreset;
use App\Models\Project;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\SumberLead;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Program All In: DP di dalam paket punya tiga pilihan — tidak termasuk / termasuk mengikuti DP skema / termasuk sebesar nominal tetap.
 * Contoh: All In 10,5 jt "termasuk DP 1,66 jt" tetap menyisakan titipan 8,84 jt pada cash yang pengikatannya 10 jt.
 */
class ProgramAllInDpTetapTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SalesAgent $agen;
    private SumberLead $lead;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->agen = SalesAgent::create(['nama' => 'Agen 1', 'tipe' => 'sales', 'is_active' => true]);
        $this->lead = SumberLead::create(['nama' => 'Instagram', 'is_referral' => false, 'is_active' => true]);
    }

    private function allIn(string $dpMode, ?float $tetap = null, bool $bf = false): ProgramAllInPreset
    {
        return ProgramAllInPreset::create(['nama' => "All In 10,5jt {$dpMode}", 'nominal' => 10500000, 'include_booking_fee' => $bf,
            'include_dp' => $dpMode !== 'tidak', 'dp_nominal_tetap' => $dpMode === 'tetap' ? $tetap : null, 'is_active' => true]);
    }

    private function skema(string $cara, float $dp): SkemaDpPreset
    {
        return SkemaDpPreset::create(['nama' => "{$cara} DP {$dp} #" . ++$this->nomor, 'cara_bayar' => $cara,
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => true,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => $dp, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto']);
    }

    private function booking(string $cara, float $dp, ProgramAllInPreset $allIn): KavlingKonsumen
    {
        $this->nomor++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor,
            'status_jual' => 'available', 'harga' => 200000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);
        $this->actingAs($sales)->post(route('bookings.store', $unit), [
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'Budi ' . $this->nomor, 'konsumen_no_hp' => '0812', 'konsumen_nik' => '32010101019000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT),
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agen->id, 'cara_bayar' => $cara, 'skema_dp_preset_id' => $this->skema($cara, $dp)->id,
            'program_all_in_preset_id' => $allIn->id,
        ])->assertSessionHasNoErrors();

        return KavlingKonsumen::where('kavling_id', $unit->id)->firstOrFail();
    }

    // ── Rumus bersama ──

    public function test_tiga_pilihan_dp_pada_rumus_titipan(): void
    {
        $tidak = $this->allIn('tidak');
        $skema = $this->allIn('skema');
        $tetap = $this->allIn('tetap', 1660000);

        $this->assertEquals(10500000, $tidak->titipanBruto(1000000, 10000000));                  // DP tidak termasuk: nominal penuh
        $this->assertEquals(500000, $skema->titipanBruto(1000000, 10000000));                    // mengikuti DP skema (10 jt)
        $this->assertEquals(8840000, $tetap->titipanBruto(1000000, 10000000));                   // tetap 1,66 jt, apa pun DP skemanya
        $this->assertEquals(8840000, $tetap->titipanBruto(1000000, 1660000));
        $this->assertEquals(8840000, $tetap->titipanBruto(1000000, 0));                          // juga bila skema tidak punya DP
        $this->assertEquals(0, $this->allIn('tetap', 20000000)->titipanBruto(0, 0));            // tidak pernah negatif
    }

    public function test_booking_fee_tetap_mengikuti_centang_dan_bisa_digabung_dengan_dp_tetap(): void
    {
        $this->assertEquals(7840000, $this->allIn('tetap', 1660000, true)->titipanBruto(1000000, 10000000));
        $this->assertEquals(9500000, $this->allIn('tidak', null, true)->titipanBruto(1000000, 10000000));
    }

    // ── Booking ──

    public function test_cash_dengan_pengikatan_10jt_dan_dp_tetap_menyisakan_titipan_8_84jt(): void
    {
        $kk = $this->booking('cash', 10000000, $this->allIn('tetap', 1660000));

        $this->assertEquals(8840000, $kk->titipan_biaya_akad_nominal);
    }

    public function test_pilihan_mengikuti_skema_tetap_memakai_dp_skema_seperti_sebelumnya(): void
    {
        $this->assertEquals(10500000 - 10000000, $this->booking('cash', 10000000, $this->allIn('skema'))->titipan_biaya_akad_nominal);
        $this->assertEquals(10500000 - 1660000, $this->booking('kpr_subsidi', 1660000, $this->allIn('skema'))->titipan_biaya_akad_nominal);
    }

    public function test_dp_tidak_termasuk_menitipkan_nominal_penuh(): void
    {
        $this->assertEquals(10500000, $this->booking('cash', 10000000, $this->allIn('tidak'))->titipan_biaya_akad_nominal);
    }

    public function test_satu_paket_tetap_sama_untuk_kpr_dan_cash(): void
    {
        $paket = $this->allIn('tetap', 1660000);

        $this->assertEquals(8840000, $this->booking('kpr_subsidi', 1660000, $paket)->titipan_biaya_akad_nominal);
        $this->assertEquals(8840000, $this->booking('cash', 10000000, $paket)->titipan_biaya_akad_nominal);
    }

    public function test_keterangan_titipan_termasuk_dp_untuk_nominal_tetap(): void
    {
        $kk = $this->booking('cash', 10000000, $this->allIn('tetap', 1660000))->load('programAllInPreset');

        $this->assertSame('DP', $kk->titipanTermasuk(10000000));
        $this->assertNull($this->booking('cash', 10000000, $this->allIn('tidak'))->load('programAllInPreset')->titipanTermasuk(10000000));
    }

    // ── Ubah Rincian ──

    public function test_ubah_rincian_menghitung_titipan_dengan_dp_tetap_walau_dp_skema_berbeda(): void
    {
        $kk = $this->booking('cash', 10000000, $this->allIn('tidak'));
        $this->assertEquals(10500000, $kk->titipan_biaya_akad_nominal);

        $paket = $this->allIn('tetap', 1660000);
        $spv = User::factory()->create();
        $spv->assignRole('spv');
        $spv->projects()->attach($this->project->id);
        $this->actingAs($spv)->patch(route('bookings.rincian-pesanan', $kk), [
            'biaya_kelebihan_tanah_aktif' => false, 'biaya_tambahan' => [], 'program_all_in_preset_id' => $paket->id,
        ])->assertSessionHas('success');

        $this->assertEquals(8840000, $kk->fresh()->titipan_biaya_akad_nominal);
    }

    // ── Pengaturan ──

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_pengaturan_menyimpan_dp_tetap_dan_mengosongkannya_bila_dp_tidak_termasuk(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('pengaturan.program-all-in.store'), ['nama' => 'Paket Tetap', 'nominal' => 10500000, 'include_booking_fee' => false, 'include_dp' => true, 'dp_nominal_tetap' => 1660000])
            ->assertSessionHas('success');
        $tetap = ProgramAllInPreset::where('nama', 'Paket Tetap')->firstOrFail();
        $this->assertEquals(1660000, $tetap->dp_nominal_tetap);

        // DP tidak termasuk: nominal tetap yang terlanjur terkirim dibuang.
        $this->actingAs($admin)->post(route('pengaturan.program-all-in.store'), ['nama' => 'Paket Tanpa DP', 'nominal' => 10500000, 'include_booking_fee' => false, 'include_dp' => false, 'dp_nominal_tetap' => 1660000]);
        $this->assertNull(ProgramAllInPreset::where('nama', 'Paket Tanpa DP')->firstOrFail()->dp_nominal_tetap);
    }

    public function test_pengaturan_ubah_preset_dan_toggle_aktif_tidak_menghilangkan_dp_tetap(): void
    {
        $admin = $this->superadmin();
        $paket = $this->allIn('tetap', 1660000);

        $this->actingAs($admin)->patch(route('pengaturan.program-all-in.update', $paket), [
            'nama' => $paket->nama, 'nominal' => 10500000, 'include_booking_fee' => false, 'include_dp' => true, 'dp_nominal_tetap' => 1660000, 'is_active' => false,
        ])->assertSessionHas('success');
        $this->assertFalse($paket->fresh()->is_active);
        $this->assertEquals(1660000, $paket->fresh()->dp_nominal_tetap);

        // Diubah menjadi "mengikuti skema": nominal tetap dikosongkan.
        $this->actingAs($admin)->patch(route('pengaturan.program-all-in.update', $paket), [
            'nama' => $paket->nama, 'nominal' => 10500000, 'include_booking_fee' => false, 'include_dp' => true, 'dp_nominal_tetap' => null, 'is_active' => true,
        ]);
        $this->assertNull($paket->fresh()->dp_nominal_tetap);
        $this->assertTrue($paket->fresh()->include_dp);
    }

    public function test_form_booking_menerima_dp_nominal_tetap_di_daftar_preset(): void
    {
        $this->allIn('tetap', 1660000);
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);

        $this->actingAs($sales)->withSession(['current_project_id' => $this->project->id])->get(route('penjualan.project', $this->project))->assertOk()
            ->assertInertia(fn ($p) => $p->where('programAllInPresets.0.dp_nominal_tetap', fn ($v) => (float) $v === 1660000.0));
    }
}
