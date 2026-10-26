<?php

namespace Tests\Feature;

use App\Models\BiayaTambahanPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\PembayaranKonsumen;
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
 * Potongan promo pada biaya: preset promo menentukan "lawan" (tanpa nominal), nominal/persen diisi per transaksi.
 * Biaya disimpan bruto; yang ditagih = bruto - potongan. Berlaku untuk KPR (baris Kartu Piutang) dan Cash (lewat harga deal).
 */
class PotonganPromoTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SalesAgent $agen;
    private SumberLead $lead;
    private SkemaDpPreset $skemaKpr;
    private SkemaDpPreset $skemaCash;
    private BiayaTambahanPreset $hook;
    private BiayaTambahanPreset $strategis;
    private PromoPreset $promoLawan;     // lawan: Kelebihan Tanah + Hook
    private PromoPreset $promoLabel;     // tanpa lawan (label saja)
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->agen = SalesAgent::create(['nama' => 'Agen 1', 'tipe' => 'sales', 'is_active' => true]);
        $this->lead = SumberLead::create(['nama' => 'Instagram', 'is_referral' => false, 'is_active' => true]);

        $skema = fn ($cara, $nama) => SkemaDpPreset::create([
            'nama' => $nama, 'cara_bayar' => $cara,
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => true,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 1660000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto',
        ]);
        $this->skemaKpr = $skema('kpr_subsidi', 'KPR UTJ 1jt DP 1,66jt');
        $this->skemaCash = $skema('cash', 'Cash UTJ 1jt DP 1,66jt');

        $this->hook = BiayaTambahanPreset::create(['nama' => 'Kavling Hook', 'is_active' => true]);
        $this->strategis = BiayaTambahanPreset::create(['nama' => 'Kavling Strategis', 'is_active' => true]);

        $this->promoLawan = PromoPreset::create(['nama' => 'Free Tanah + Hook', 'is_active' => true]);
        $this->promoLawan->lawan()->create(['jenis' => PromoPresetLawan::TANAH]);
        $this->promoLawan->lawan()->create(['jenis' => PromoPresetLawan::BIAYA_TAMBAHAN, 'biaya_tambahan_preset_id' => $this->hook->id]);
        $this->promoLabel = PromoPreset::create(['nama' => 'Free Kanopi', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function unit(): Kavling
    {
        $this->nomor++;

        return Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor,
            'status_jual' => 'available', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
    }

    /** Booking: kelebihan tanah 12 m2 x Rp 700 rb = Rp 8,4 jt, plus Hook Rp 5 jt; potongan sesuai parameter. */
    private function booking(array $ubah = [], string $cara = 'kpr_subsidi'): KavlingKonsumen
    {
        $unit = $this->unit();
        $this->actingAs($this->user('admin_sales'))->post(route('bookings.store', $unit), array_merge([
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'Budi ' . $this->nomor, 'konsumen_no_hp' => '0812', 'konsumen_nik' => '32010000000000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT),
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agen->id, 'cara_bayar' => $cara,
            'skema_dp_preset_id' => ($cara === 'cash' ? $this->skemaCash : $this->skemaKpr)->id,
            'biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_mode' => 'per_m2', 'biaya_kelebihan_tanah_luas' => 12, 'biaya_kelebihan_tanah_harga_per_m2' => 700000,
            'biaya_tambahan' => [['preset_id' => $this->hook->id, 'nominal' => 5000000]],
        ], $ubah))->assertSessionHasNoErrors();

        return KavlingKonsumen::where('kavling_id', $unit->id)->firstOrFail();
    }

    private function breakdown(KavlingKonsumen $kk): array
    {
        return $kk->fresh(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])->kartuPiutangBreakdown();
    }

    private function baris(array $breakdown, string $nama): ?array
    {
        return collect($breakdown['kartu_piutang_static'])->firstWhere('nama', $nama);
    }

    public function test_tanpa_promo_lawan_potongan_diabaikan_dan_tagihan_penuh(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoLabel->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100]]);

        $this->assertEquals(0, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertEquals(8400000 + 5000000, $kk->total_biaya_tambahan);
        $this->assertEquals(8400000, $this->baris($this->breakdown($kk), 'Biaya Penambahan Tanah')['nominal']);
    }

    public function test_potongan_penuh_pada_tanah_membuat_tagihan_nol_dan_piutang_tidak_membengkak(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoLawan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100]]);

        $this->assertEquals(8400000, $kk->biaya_kelebihan_tanah_nominal);          // bruto tetap tercatat
        $this->assertEquals(8400000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertSame('persen', $kk->biaya_kelebihan_tanah_potongan_mode);
        $this->assertEquals(5000000, $kk->total_biaya_tambahan);                   // bersih: hanya Hook
        $this->assertEquals(166000000 + 5000000, $kk->harga_deal);                 // tanpa diskon pengganti

        $b = $this->breakdown($kk);
        $tanah = $this->baris($b, 'Biaya Penambahan Tanah');
        $this->assertEquals(0, $tanah['nominal']);
        $this->assertSame('lunas', $tanah['status']);                              // tidak ada yang perlu dibayar
        $this->assertEquals(8400000, $tanah['bruto']);
        $this->assertEquals(8400000, $tanah['potongan']);
        // Piutang konsumen = Booking Fee 1 jt + DP 1,66 jt + Hook 5 jt (tanpa tanah).
        $this->assertEquals(1000000 + 1660000 + 5000000, $b['total_piutang_konsumen']);
    }

    public function test_potongan_sebagian_dan_banyak_lawan_masing_masing_punya_nilai_sendiri(): void
    {
        $kk = $this->booking([
            'promo_preset_id' => $this->promoLawan->id,
            'potongan_tanah' => ['mode' => 'persen', 'nilai' => 50],
            'biaya_tambahan' => [['preset_id' => $this->hook->id, 'nominal' => 5000000, 'potongan' => ['mode' => 'nominal', 'nilai' => 1500000]]],
        ]);

        $this->assertEquals(4200000, $kk->biaya_kelebihan_tanah_potongan);
        $hook = $kk->biayaTambahans()->firstOrFail();
        $this->assertEquals(1500000, $hook->potongan);
        $this->assertEquals(3500000, $hook->ditagih);
        $this->assertEquals((8400000 - 4200000) + 3500000, $kk->total_biaya_tambahan);

        $b = $this->breakdown($kk);
        $this->assertEquals(4200000, $this->baris($b, 'Biaya Penambahan Tanah')['nominal']);
        $this->assertEquals(3500000, $this->baris($b, 'Kavling Hook')['nominal']);
    }

    public function test_potongan_hanya_untuk_lawan_biaya_lain_dalam_promo_yang_sama_tidak_terpotong(): void
    {
        $kk = $this->booking([
            'promo_preset_id' => $this->promoLawan->id,
            'biaya_tambahan' => [
                ['preset_id' => $this->hook->id, 'nominal' => 5000000, 'potongan' => ['mode' => 'persen', 'nilai' => 100]],
                ['preset_id' => $this->strategis->id, 'nominal' => 3000000, 'potongan' => ['mode' => 'persen', 'nilai' => 100]],   // bukan lawan promo ini
            ],
        ]);

        $this->assertEquals(5000000, $kk->biayaTambahans()->where('biaya_tambahan_preset_id', $this->hook->id)->firstOrFail()->potongan);
        $strategis = $kk->biayaTambahans()->where('biaya_tambahan_preset_id', $this->strategis->id)->firstOrFail();
        $this->assertEquals(0, $strategis->potongan);
        $this->assertEquals(3000000, $strategis->ditagih);
    }

    public function test_potongan_tidak_melebihi_biaya(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoLawan->id, 'potongan_tanah' => ['mode' => 'nominal', 'nilai' => 99000000]]);

        $this->assertEquals(8400000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertEquals(0, $kk->biaya_kelebihan_tanah_ditagih);
    }

    public function test_cash_pelunasan_otomatis_bersih_lewat_harga_deal(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoLawan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100]], 'cash');

        $this->assertEquals(166000000 + 5000000, $kk->harga_deal);
        $pelunasan = $kk->jadwalTagihans()->where('jenis', 'pelunasan')->sum('jumlah');
        $this->assertEquals(171000000 - 1000000 - 1660000, $pelunasan);            // pelunasan memakai harga deal bersih
        $this->assertEquals(171000000, $this->breakdown($kk)['total_piutang_konsumen']);
    }

    public function test_klasifikasi_rekening_titipan_memakai_nilai_bersih(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoLawan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100]]);

        $titipan = $kk->fresh(['biayaTambahans', 'skemaDpPreset'])->kategoriPendapatan()['titipan_rincian'];
        $this->assertEquals(0, $titipan['biaya_tanah']);
        $this->assertEquals(5000000, $titipan['biaya_tambahan_lain']);
    }

    public function test_ubah_rincian_menambah_potongan_dan_menghapus_diskon_pengganti(): void
    {
        // Kasus nyata: tanah "digratiskan" lewat diskon pengganti -> KPR tetap menagih tanah.
        $kk = $this->booking(['promo_preset_id' => $this->promoLawan->id, 'diskon_mode' => 'nominal', 'diskon_nilai' => 8400000, 'biaya_tambahan' => []]);
        $this->assertEquals(8400000, $this->baris($this->breakdown($kk), 'Biaya Penambahan Tanah')['nominal']);   // bug sebelum diperbaiki

        $this->actingAs($this->user('spv'))->patch(route('bookings.rincian-pesanan', $kk), [
            'biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_mode' => 'per_m2', 'biaya_kelebihan_tanah_luas' => 12, 'biaya_kelebihan_tanah_harga_per_m2' => 700000,
            'biaya_tambahan' => [], 'promo_preset_id' => $this->promoLawan->id, 'diskon_mode' => null, 'diskon_nilai' => null,
            'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100],
        ])->assertSessionHas('success');

        $kk = $kk->fresh();
        $this->assertEquals(8400000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertEquals(0, $kk->diskon_nominal);
        $this->assertEquals(166000000, $kk->harga_deal);
        $b = $this->breakdown($kk);
        $this->assertEquals(0, $this->baris($b, 'Biaya Penambahan Tanah')['nominal']);
        $this->assertEquals(1000000 + 1660000, $b['total_piutang_konsumen']);
    }

    public function test_biaya_tanah_yang_sudah_ada_pembayaran_terkunci_termasuk_potongannya(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoLawan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 50], 'biaya_tambahan' => []]);
        PembayaranKonsumen::create(['kavling_konsumen_id' => $kk->id, 'jenis' => 'biaya_tanah', 'jumlah' => 1000000, 'tanggal_bayar' => now()->toDateString(), 'keterangan' => 'Cicilan tanah']);

        $this->actingAs($this->user('spv'))->patch(route('bookings.rincian-pesanan', $kk), [
            'biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_mode' => 'per_m2', 'biaya_kelebihan_tanah_luas' => 12, 'biaya_kelebihan_tanah_harga_per_m2' => 700000,
            'biaya_tambahan' => [], 'promo_preset_id' => $this->promoLawan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100],
        ]);

        $this->assertEquals(4200000, $kk->fresh()->biaya_kelebihan_tanah_potongan);     // tetap 50%, tidak berubah jadi 100%
    }

    public function test_pengaturan_lawan_promo_disimpan_dan_muncul_di_daftar_form(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->put(route('pengaturan.promo.lawan', $this->promoLabel), [
            'tanah' => true, 'biaya_tambahan_preset_ids' => [$this->hook->id, $this->strategis->id],
        ])->assertSessionHas('success');

        $form = PromoPreset::untukForm()->firstWhere('id', $this->promoLabel->id);
        $this->assertTrue($form['lawan_tanah']);
        $this->assertEqualsCanonicalizing([$this->hook->id, $this->strategis->id], $form['lawan_biaya_tambahan']);

        // Mengosongkan lawan menjadikannya label saja lagi.
        $this->actingAs($admin)->put(route('pengaturan.promo.lawan', $this->promoLabel), ['tanah' => false, 'biaya_tambahan_preset_ids' => []]);
        $this->assertSame(0, $this->promoLabel->lawan()->count());
    }

    public function test_admin_sales_tidak_boleh_mengatur_lawan_promo(): void
    {
        $this->actingAs($this->user('admin_sales'))->put(route('pengaturan.promo.lawan', $this->promoLabel), ['tanah' => true])
            ->assertRedirect(route('dashboard'));    // izin kurang: dialihkan ke dashboard dengan pesan (bukan 403)
        $this->assertSame(0, $this->promoLabel->lawan()->count());
    }
}
