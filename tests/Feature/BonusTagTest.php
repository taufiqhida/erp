<?php

namespace Tests\Feature;

use App\Models\BonusPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
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
 * Bonus saat booking (tandon, kipas, AC, ...): master nama saja, banyak per transaksi, label tanpa dampak ke keuangan.
 */
class BonusTagTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SalesAgent $agen;
    private SumberLead $lead;
    private SkemaDpPreset $skema;
    private BonusPreset $tandon;
    private BonusPreset $kipas;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->agen = SalesAgent::create(['nama' => 'Agen 1', 'tipe' => 'sales', 'is_active' => true]);
        $this->lead = SumberLead::create(['nama' => 'Instagram', 'is_referral' => false, 'is_active' => true]);
        $this->skema = SkemaDpPreset::create([
            'nama' => 'KPR UTJ 1jt', 'cara_bayar' => 'kpr_subsidi',
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => false,
            'dp_aktif' => false, 'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto',
        ]);
        $this->tandon = BonusPreset::create(['nama' => 'Tandon Air', 'is_active' => true]);
        $this->kipas = BonusPreset::create(['nama' => 'Kipas Angin', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function booking(array $ubah = []): KavlingKonsumen
    {
        $this->nomor++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor,
            'status_jual' => 'available', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $this->actingAs($this->user('admin_sales'))->post(route('bookings.store', $unit), array_merge([
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'Budi ' . $this->nomor, 'konsumen_no_hp' => '0812', 'konsumen_nik' => '32010000000000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT),
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agen->id, 'cara_bayar' => 'kpr_subsidi', 'skema_dp_preset_id' => $this->skema->id,
        ], $ubah))->assertSessionHasNoErrors();

        return KavlingKonsumen::where('kavling_id', $unit->id)->firstOrFail();
    }

    private function ubahRincian(KavlingKonsumen $kk, array $ubah)
    {
        return $this->actingAs($this->user('spv'))->patch(route('bookings.rincian-pesanan', $kk), array_merge([
            'biaya_kelebihan_tanah_aktif' => false, 'biaya_tambahan' => [], 'promo_preset_id' => null,
        ], $ubah));
    }

    // ── Master ────────────────────────────────────────────────────────────

    public function test_master_bonus_bisa_dikelola_oleh_pemegang_izin_dan_ditolak_untuk_lainnya(): void
    {
        $admin = $this->user('superadmin');
        $this->actingAs($admin)->get(route('pengaturan.bonus'))->assertOk();
        $this->actingAs($admin)->post(route('pengaturan.bonus.store'), ['nama' => 'AC', 'keterangan' => '1 PK'])->assertSessionHasNoErrors();
        $ac = BonusPreset::where('nama', 'AC')->firstOrFail();
        $this->assertSame(3, $ac->urutan);                                                     // masuk paling bawah

        $this->actingAs($admin)->patch(route('pengaturan.bonus.update', $ac), ['nama' => 'AC 1 PK', 'keterangan' => null, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($ac->fresh()->is_active);
        $this->assertSame('AC 1 PK', $ac->fresh()->nama);

        $sales = $this->user('admin_sales');
        $this->actingAs($sales)->post(route('pengaturan.bonus.store'), ['nama' => 'Kulkas'])->assertRedirect(route('dashboard'));   // ditolak middleware izin
        $this->assertSame(0, BonusPreset::where('nama', 'Kulkas')->count());
    }

    public function test_bonus_yang_sudah_dipakai_tidak_bisa_dihapus_yang_belum_bisa(): void
    {
        $this->booking(['bonus_ids' => [$this->tandon->id]]);
        $admin = $this->user('superadmin');

        $this->actingAs($admin)->delete(route('pengaturan.bonus.destroy', $this->tandon))->assertSessionHas('error');
        $this->assertNotNull(BonusPreset::find($this->tandon->id));

        $this->actingAs($admin)->delete(route('pengaturan.bonus.destroy', $this->kipas))->assertSessionHas('success');
        $this->assertNull(BonusPreset::find($this->kipas->id));
    }

    // ── Booking ───────────────────────────────────────────────────────────

    public function test_booking_menyimpan_banyak_bonus_tanpa_mengubah_harga(): void
    {
        $tanpa = $this->booking();
        $dengan = $this->booking(['bonus_ids' => [$this->tandon->id, $this->kipas->id]]);

        $this->assertCount(0, $tanpa->bonuses);
        $this->assertEqualsCanonicalizing([$this->tandon->id, $this->kipas->id], $dengan->bonuses->pluck('id')->all());
        $this->assertEquals($tanpa->harga_deal, $dengan->harga_deal);                          // bonus tidak mempengaruhi harga
    }

    public function test_booking_menolak_bonus_yang_tidak_ada(): void
    {
        $this->nomor++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'Z', 'nomor_kavling' => '1',
            'status_jual' => 'available', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $this->actingAs($this->user('admin_sales'))->post(route('bookings.store', $unit), [
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'X', 'konsumen_no_hp' => '0812', 'sales_agent_id' => $this->agen->id,
            'cara_bayar' => 'kpr_subsidi', 'skema_dp_preset_id' => $this->skema->id, 'bonus_ids' => [99999],
        ])->assertSessionHasErrors('bonus_ids.0');
    }

    // ── Ubah Rincian ──────────────────────────────────────────────────────

    public function test_ubah_rincian_mengganti_bonus_dan_pratinjau_menampilkannya(): void
    {
        $kk = $this->booking(['bonus_ids' => [$this->tandon->id]]);

        $this->ubahRincian($kk, ['bonus_ids' => [$this->kipas->id], 'simulasi' => true])->assertOk()
            ->assertJsonFragment(['label' => 'Bonus', 'lama' => 'Tandon Air', 'baru' => 'Kipas Angin']);
        $this->assertSame([$this->tandon->id], $kk->fresh()->bonuses->pluck('id')->all());     // simulasi tidak menyimpan

        $this->ubahRincian($kk, ['bonus_ids' => [$this->kipas->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->kipas->id], $kk->fresh()->bonuses->pluck('id')->all());

        $this->ubahRincian($kk, ['bonus_ids' => []])->assertSessionHasNoErrors();             // dikosongkan
        $this->assertCount(0, $kk->fresh()->bonuses);
    }

    public function test_payload_lama_tanpa_kunci_bonus_tidak_menghapus_bonus(): void
    {
        $kk = $this->booking(['bonus_ids' => [$this->tandon->id, $this->kipas->id]]);

        // Usulan/form lama tidak mengirim bonus_ids sama sekali: bonus dibiarkan apa adanya.
        $this->ubahRincian($kk, ['diskon_mode' => null])->assertSessionHasNoErrors();
        $this->assertCount(2, $kk->fresh()->bonuses);
    }

    public function test_halaman_konsumen_dan_keuangan_memuat_nama_bonus(): void
    {
        $kk = $this->booking(['bonus_ids' => [$this->tandon->id]]);
        $super = $this->user('superadmin');

        $this->actingAs($super)->get(route('konsumens.show', $kk->konsumen_id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('transaksis.0.bonus_nama', ['Tandon Air'])->where('transaksis.0.bonus_ids', [$this->tandon->id])->has('bonusPresets', 2));
        $this->actingAs($super)->get(route('keuangan.detail', $kk))->assertOk()
            ->assertInertia(fn ($p) => $p->where('transaksi.bonus_nama', ['Tandon Air']));
    }
}
