<?php

namespace Tests\Feature;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Models\BiayaTambahanPreset;
use App\Models\BonusPreset;
use App\Models\CancellationRequest;
use App\Models\CatatanTransaksi;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Project;
use App\Models\PromoPreset;
use App\Models\PromoPresetLawan;
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
 * Persetujuan promo pada booking: Admin Sales yang memberi promo/potongan/diskon/label/bonus tidak langsung menerapkannya.
 * Booking dibuat tanpa itu (tagihan harga penuh) dan usulannya masuk Pengajuan; SPV/Leader menyetujui lewat jalur Ubah Rincian.
 * Pemegang izin override (Superadmin) booking langsung.
 */
class PromoBookingPersetujuanTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SalesAgent $agen;
    private SumberLead $lead;
    private SkemaDpPreset $skemaKpr;
    private SkemaDpPreset $skemaCash;
    private BiayaTambahanPreset $hook;
    private PromoPreset $promoGabungan;     // lawan: tanah + Hook + harga jual
    private PromoPreset $promoLabel;        // Free Kanopi (tanpa lawan)
    private BonusPreset $tandon;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->agen = SalesAgent::create(['nama' => 'Agen 1', 'tipe' => 'sales', 'is_active' => true]);
        $this->lead = SumberLead::create(['nama' => 'Instagram', 'is_referral' => false, 'is_active' => true]);
        $skema = fn ($cara) => SkemaDpPreset::create(['nama' => "Skema {$cara}", 'cara_bayar' => $cara,
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => true,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 1660000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto']);
        $this->skemaKpr = $skema('kpr_subsidi');
        $this->skemaCash = $skema('cash');
        $this->hook = BiayaTambahanPreset::create(['nama' => 'Kavling Hook', 'is_active' => true]);
        $this->promoGabungan = PromoPreset::create(['nama' => 'Promo Gabungan', 'is_active' => true]);
        $this->promoGabungan->lawan()->create(['jenis' => PromoPresetLawan::TANAH]);
        $this->promoGabungan->lawan()->create(['jenis' => PromoPresetLawan::HARGA_JUAL]);
        $this->promoGabungan->lawan()->create(['jenis' => PromoPresetLawan::BIAYA_TAMBAHAN, 'biaya_tambahan_preset_id' => $this->hook->id]);
        $this->promoLabel = PromoPreset::create(['nama' => 'Free Kanopi', 'is_active' => true]);
        $this->tandon = BonusPreset::create(['nama' => 'Tandon Air', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function booking(string $oleh, array $ubah = [], string $cara = 'kpr_subsidi')
    {
        $this->nomor++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor,
            'status_jual' => 'available', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $r = $this->actingAs($this->user($oleh))->post(route('bookings.store', $unit), array_merge([
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'Budi ' . $this->nomor, 'konsumen_no_hp' => '0812', 'konsumen_nik' => '32010101019000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT),
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agen->id, 'cara_bayar' => $cara, 'skema_dp_preset_id' => ($cara === 'cash' ? $this->skemaCash : $this->skemaKpr)->id,
            'biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_mode' => 'per_m2', 'biaya_kelebihan_tanah_luas' => 12, 'biaya_kelebihan_tanah_harga_per_m2' => 700000,
            'biaya_tambahan' => [['preset_id' => $this->hook->id, 'nominal' => 5000000]],
        ], $ubah));
        $r->assertSessionHasNoErrors();
        $this->lastResponse = $r;

        return KavlingKonsumen::where('kavling_id', $unit->id)->firstOrFail();
    }

    private $lastResponse;

    /** Isian promo lengkap: potongan tanah 100%, potongan Hook Rp 1,5 jt, bonus Tandon. */
    private function promoLengkap(): array
    {
        return [
            'promo_preset_id' => $this->promoGabungan->id, 'promo_tanah_id' => $this->promoGabungan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100],
            'biaya_tambahan' => [['preset_id' => $this->hook->id, 'nominal' => 5000000, 'promo_preset_id' => $this->promoGabungan->id, 'potongan' => ['mode' => 'nominal', 'nilai' => 1500000]]],
            'bonus_ids' => [$this->tandon->id],
        ];
    }

    private function usulan(KavlingKonsumen $kk): ?CancellationRequest
    {
        return CancellationRequest::where('kavling_konsumen_id', $kk->id)->where('type', CancellationRequestType::RincianChange->value)->first();
    }

    private function breakdown(KavlingKonsumen $kk): array
    {
        return $kk->fresh(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])->kartuPiutangBreakdown();
    }

    // ── Admin Sales: menunggu persetujuan ──

    public function test_booking_admin_sales_dengan_promo_tersimpan_tanpa_promo_dan_membuat_usulan(): void
    {
        $kk = $this->booking('admin_sales', $this->promoLengkap());

        // Tagihan harga penuh: tidak ada potongan, tidak ada promo, tidak ada bonus.
        $this->assertEquals(8400000, $kk->biaya_kelebihan_tanah_nominal);
        $this->assertEquals(0, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertNull($kk->promo_preset_id);
        $this->assertNull($kk->biaya_kelebihan_tanah_promo_id);
        $this->assertEquals(0, $kk->biayaTambahans()->firstOrFail()->potongan);
        $this->assertCount(0, $kk->bonuses);
        $this->assertEquals(166000000 + 8400000 + 5000000, $kk->harga_deal);

        $u = $this->usulan($kk);
        $this->assertNotNull($u);
        $this->assertTrue($u->isPending());
        $this->assertSame('booking', $u->payload['asal']);
        $this->assertStringContainsString('Promo Gabungan', $u->alasan);
        $this->assertStringContainsString('Tandon Air', $u->alasan);
        $this->assertSame($kk->biayaTambahans()->firstOrFail()->id, $u->payload['rincian']['biaya_tambahan'][0]['id']);       // menunjuk baris yang sudah ada
        $this->assertSame(1, CatatanTransaksi::where('kavling_konsumen_id', $kk->id)->where('isi', 'like', 'Menunggu persetujuan%')->count());
        $this->lastResponse->assertSessionHas('success', fn ($m) => str_contains($m, 'menunggu persetujuan'));
    }

    public function test_hanya_bonus_atau_hanya_label_promo_juga_perlu_persetujuan(): void
    {
        $bonus = $this->booking('admin_sales', ['bonus_ids' => [$this->tandon->id]]);
        $this->assertCount(0, $bonus->bonuses);
        $this->assertNotNull($this->usulan($bonus));

        $label = $this->booking('admin_sales', ['promo_preset_id' => $this->promoLabel->id]);
        $this->assertNull($label->promo_preset_id);
        $this->assertNotNull($this->usulan($label));
    }

    public function test_booking_tanpa_promo_tidak_membuat_usulan(): void
    {
        $kk = $this->booking('admin_sales');

        $this->assertNull($this->usulan($kk));
        $this->assertSame(0, CancellationRequest::count());
    }

    public function test_superadmin_booking_langsung_dengan_promo_tanpa_usulan(): void
    {
        $kk = $this->booking('superadmin', $this->promoLengkap());

        $this->assertNull($this->usulan($kk));
        $this->assertEquals(8400000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertEquals($this->promoGabungan->id, $kk->promo_preset_id);
        $this->assertCount(1, $kk->bonuses);
    }

    // ── Keputusan SPV ──

    public function test_spv_menyetujui_promo_diterapkan_dan_piutang_turun(): void
    {
        $kk = $this->booking('admin_sales', $this->promoLengkap());
        $u = $this->usulan($kk);
        $sebelum = $this->breakdown($kk)['total_piutang_konsumen'];
        $this->assertEquals(1000000 + 1660000 + 8400000 + 5000000, $sebelum);                           // KPR: BF + DP + tanah + Hook, harga penuh

        $this->actingAs($this->user('spv'))->patch(route('cancellation-requests.approve', $u), [])->assertSessionHas('success');

        $kk = $kk->fresh();
        $this->assertTrue($u->fresh()->isApproved());
        $this->assertEquals(8400000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertEquals(1500000, $kk->biayaTambahans()->firstOrFail()->potongan);
        $this->assertEquals($this->promoGabungan->id, $kk->promo_preset_id);
        $this->assertCount(1, $kk->bonuses);
        $this->assertCount(1, $kk->biayaTambahans);                                                     // tidak terduplikasi
        $this->assertEquals(166000000 + 3500000, $kk->harga_deal);                                      // tanah gratis, Hook 3,5 jt
        $this->assertEquals(1000000 + 1660000 + 0 + 3500000, $this->breakdown($kk)['total_piutang_konsumen']);   // tanah gratis, Hook 3,5 jt
        $this->assertSame(1, CatatanTransaksi::where('kavling_konsumen_id', $kk->id)->where('tahap', 'Ubah rincian')->count());
    }

    public function test_spv_menolak_transaksi_tetap_tanpa_promo(): void
    {
        $kk = $this->booking('admin_sales', $this->promoLengkap());
        $u = $this->usulan($kk);

        $this->actingAs($this->user('spv'))->patch(route('cancellation-requests.reject', $u), ['catatan_reviewer' => 'Promo tidak berlaku untuk unit ini'])->assertSessionHas('success');

        $this->assertTrue($u->fresh()->isRejected());
        $kk = $kk->fresh();
        $this->assertEquals(0, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertCount(0, $kk->bonuses);
        $this->assertEquals(166000000 + 8400000 + 5000000, $kk->harga_deal);
    }

    public function test_cash_dengan_diskon_harga_jual_disetujui_memunculkan_peringatan_selisih_jadwal(): void
    {
        $promoDiskon = PromoPreset::create(['nama' => 'Diskon Akhir Tahun', 'is_active' => true]);
        $promoDiskon->lawan()->create(['jenis' => PromoPresetLawan::HARGA_JUAL]);
        $kk = $this->booking('admin_sales', ['promo_preset_id' => $promoDiskon->id, 'diskon_promo_id' => $promoDiskon->id, 'diskon_mode' => 'nominal', 'diskon_nilai' => 3000000], 'cash');
        $this->assertEquals(0, $kk->diskon_nominal);

        $this->actingAs($this->user('spv'))->patch(route('cancellation-requests.approve', $this->usulan($kk)), [])
            ->assertSessionHas('success')
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'cicilan Pelunasan') && str_contains($m, 'Rp 3.000.000'));
        $this->assertEquals(3000000, $kk->fresh()->diskon_nominal);
    }

    // ── Tampilan di Pengajuan ──

    public function test_usulan_muncul_di_tab_rincian_dan_promo_dengan_penanda_promo_booking_dan_menaikkan_angka_menu(): void
    {
        $kk = $this->booking('admin_sales', $this->promoLengkap());
        $spv = $this->user('spv');

        $html = $this->actingAs($spv)->get(route('cancellation-requests.index', ['tab' => 'rincian']))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $props = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];

        $this->assertCount(1, $props['requests']['data']);
        $baris = $props['requests']['data'][0];
        $this->assertSame('booking', $baris['asal']);
        $this->assertNotEmpty($baris['perubahan']['baris']);                                              // pratinjau perubahan untuk peninjau
        $this->assertSame(1, $props['pengajuanMenunggu']);
        $this->assertSame(1, collect($props['tabs'])->firstWhere('key', 'rincian')['menunggu']);
    }
}
