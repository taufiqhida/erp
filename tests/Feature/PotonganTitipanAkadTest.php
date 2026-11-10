<?php

namespace Tests\Feature;

use App\Models\BiayaTambahanPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\PromoPresetLawan;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\SumberLead;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\PotonganPromo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Promo yang memotong Titipan Biaya Akad (lawan baru "titipan_biaya_akad"), termasuk konsumen yang memakai notaris sendiri
 * (potongan nominal sebesar biaya notaris + catatan). Pola sama dengan potongan Kelebihan Tanah: titipan disimpan BRUTO, yang ditagih
 * = bruto - potongan. Titipan berada di luar harga deal, jadi harga deal tidak berubah.
 */
class PotonganTitipanAkadTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SalesAgent $agen;
    private SumberLead $lead;
    private SkemaDpPreset $skema;
    private ProgramAllInPreset $allIn;          // Rp 11 jt, tidak mencakup BF/DP -> titipan bruto Rp 11 jt
    private ProgramAllInPreset $allInDp;        // Rp 11 jt, mencakup DP 1,66 jt -> titipan bruto Rp 9,34 jt
    private PromoPreset $promoTitipan;          // lawan: Titipan Biaya Akad
    private PromoPreset $promoTanah;            // lawan: Kelebihan Tanah saja
    private PromoPreset $promoLabel;            // tanpa lawan
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
            'nama' => 'KPR UTJ 1jt DP 1,66jt', 'cara_bayar' => 'kpr_subsidi',
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => true,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 1660000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto',
        ]);
        $this->allIn = ProgramAllInPreset::create(['nama' => 'All In 11jt', 'nominal' => 11000000, 'include_booking_fee' => false, 'include_dp' => false, 'is_active' => true]);
        $this->allInDp = ProgramAllInPreset::create(['nama' => 'All In 11jt incl DP', 'nominal' => 11000000, 'include_booking_fee' => false, 'include_dp' => true, 'is_active' => true]);

        $this->promoTitipan = PromoPreset::create(['nama' => 'Diskon Biaya Akad', 'is_active' => true]);
        $this->promoTitipan->lawan()->create(['jenis' => PromoPresetLawan::TITIPAN_BIAYA_AKAD]);
        $this->promoTanah = PromoPreset::create(['nama' => 'Free Tanah', 'is_active' => true]);
        $this->promoTanah->lawan()->create(['jenis' => PromoPresetLawan::TANAH]);
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

    private function booking(array $ubah = []): KavlingKonsumen
    {
        $unit = $this->unit();
        $this->actingAs($this->user('admin_sales'))->post(route('bookings.store', $unit), array_merge([
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'Budi ' . $this->nomor, 'konsumen_no_hp' => '0812', 'konsumen_nik' => '32010000000000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT),
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agen->id, 'cara_bayar' => 'kpr_subsidi', 'skema_dp_preset_id' => $this->skema->id,
            'program_all_in_preset_id' => $this->allIn->id,
        ], $ubah))->assertSessionHasNoErrors();

        return KavlingKonsumen::where('kavling_id', $unit->id)->firstOrFail();
    }

    private function breakdown(KavlingKonsumen $kk): array
    {
        return $kk->fresh(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])->kartuPiutangBreakdown();
    }

    private function barisTitipan(KavlingKonsumen $kk): ?array
    {
        return collect($this->breakdown($kk)['kartu_piutang_static'])->firstWhere('nama', 'Titipan Biaya Akad');
    }

    private function ubahRincian(KavlingKonsumen $kk, array $ubah, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user('spv'))->patch(route('bookings.rincian-pesanan', $kk), array_merge([
            'biaya_kelebihan_tanah_aktif' => false, 'biaya_tambahan' => [], 'promo_preset_id' => null,
            'program_all_in_preset_id' => $this->allIn->id,
        ], $ubah));
    }

    public function test_potongan_persen_pada_titipan_disimpan_bruto_dan_yang_ditagih_neto(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'persen', 'nilai' => 50]]);

        $this->assertEquals(11000000, $kk->titipan_biaya_akad_nominal);                      // bruto
        $this->assertEquals(5500000, $kk->titipan_biaya_akad_potongan);
        $this->assertSame('persen', $kk->titipan_biaya_akad_potongan_mode);
        $this->assertEquals(50, $kk->titipan_biaya_akad_potongan_nilai);
        $this->assertEquals(5500000, $kk->titipan_biaya_akad_ditagih);
        $this->assertEquals(166000000, $kk->harga_deal);                                      // titipan di luar harga deal

        $baris = $this->barisTitipan($kk);
        $this->assertEquals(5500000, $baris['nominal']);
        $this->assertEquals(11000000, $baris['bruto']);
        $this->assertEquals(5500000, $baris['potongan']);
        $this->assertEquals(5500000, $kk->fresh(['biayaTambahans', 'skemaDpPreset'])->kategoriPendapatan()['titipan_rincian']['titipan_biaya_akad']);
    }

    public function test_notaris_sendiri_potongan_nominal_dan_catatan(): void
    {
        $kk = $this->booking([
            'promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 3500000],
            'catatan' => 'Konsumen memakai notaris sendiri, biaya notaris Rp 3,5 jt dipotong dari titipan',
        ]);

        $this->assertEquals(3500000, $kk->titipan_biaya_akad_potongan);
        $this->assertEquals(7500000, $kk->titipan_biaya_akad_ditagih);
        $this->assertEquals(7500000, $this->barisTitipan($kk)['nominal']);
        $this->assertTrue($kk->catatans()->where('isi', 'like', '%notaris sendiri%')->exists());
    }

    public function test_potongan_dibatasi_sampai_bruto_dan_titipan_nol_dianggap_lunas(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 99000000]]);

        $this->assertEquals(11000000, $kk->titipan_biaya_akad_potongan);
        $this->assertEquals(0, $kk->titipan_biaya_akad_ditagih);
        $baris = $this->barisTitipan($kk);
        $this->assertEquals(0, $baris['nominal']);
        $this->assertSame('lunas', $baris['status']);
    }

    public function test_potongan_diabaikan_tanpa_promo_dengan_lawan_titipan_atau_tanpa_all_in(): void
    {
        $potongan = ['mode' => 'persen', 'nilai' => 100];

        $tanpaPromo = $this->booking(['potongan_titipan' => $potongan]);
        $this->assertEquals(0, $tanpaPromo->titipan_biaya_akad_potongan);
        $this->assertEquals(11000000, $tanpaPromo->titipan_biaya_akad_ditagih);

        $promoLain = $this->booking(['promo_preset_id' => $this->promoTanah->id, 'potongan_titipan' => $potongan]);
        $this->assertEquals(0, $promoLain->titipan_biaya_akad_potongan);

        $label = $this->booking(['promo_preset_id' => $this->promoLabel->id, 'potongan_titipan' => $potongan]);
        $this->assertEquals(0, $label->titipan_biaya_akad_potongan);

        $tanpaAllIn = $this->booking(['promo_preset_id' => $this->promoTitipan->id, 'program_all_in_preset_id' => null, 'potongan_titipan' => $potongan]);
        $this->assertEquals(0, $tanpaAllIn->titipan_biaya_akad_nominal);
        $this->assertEquals(0, $tanpaAllIn->titipan_biaya_akad_potongan);
        $this->assertNull($this->barisTitipan($tanpaAllIn));
    }

    public function test_bruto_titipan_mengikuti_all_in_yang_mencakup_dp_dan_persen_dihitung_dari_bruto(): void
    {
        $kk = $this->booking(['program_all_in_preset_id' => $this->allInDp->id, 'promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'persen', 'nilai' => 100]]);

        $this->assertEquals(9340000, $kk->titipan_biaya_akad_nominal);                        // 11 jt - DP 1,66 jt
        $this->assertEquals(9340000, $kk->titipan_biaya_akad_potongan);
        $this->assertEquals(0, $kk->titipan_biaya_akad_ditagih);
    }

    public function test_ubah_rincian_menambah_mengubah_dan_mencabut_potongan_titipan(): void
    {
        $kk = $this->booking();
        $this->assertEquals(0, $kk->titipan_biaya_akad_potongan);

        $this->ubahRincian($kk, ['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 2000000]])->assertSessionHas('success');
        $kk = $kk->fresh();
        $this->assertEquals(2000000, $kk->titipan_biaya_akad_potongan);
        $this->assertEquals(9000000, $this->barisTitipan($kk)['nominal']);

        $this->ubahRincian($kk, ['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'persen', 'nilai' => 10]]);
        $this->assertEquals(1100000, $kk->fresh()->titipan_biaya_akad_potongan);

        // Ganti ke promo tanpa lawan titipan: potongan dicabut, titipan kembali penuh.
        $this->ubahRincian($kk, ['promo_preset_id' => $this->promoLabel->id, 'potongan_titipan' => ['mode' => 'persen', 'nilai' => 10]]);
        $kk = $kk->fresh();
        $this->assertEquals(0, $kk->titipan_biaya_akad_potongan);
        $this->assertNull($kk->titipan_biaya_akad_potongan_mode);
        $this->assertEquals(11000000, $this->barisTitipan($kk)['nominal']);
    }

    public function test_titipan_yang_sudah_ada_pembayaran_terkunci_termasuk_potongannya(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'persen', 'nilai' => 50]]);
        PembayaranKonsumen::create(['kavling_konsumen_id' => $kk->id, 'jenis' => 'titipan_biaya_akad', 'jumlah' => 1000000, 'tanggal_bayar' => now()->toDateString(), 'keterangan' => 'Cicilan titipan']);

        $this->ubahRincian($kk, ['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'persen', 'nilai' => 100]]);

        $kk = $kk->fresh();
        $this->assertEquals(5500000, $kk->titipan_biaya_akad_potongan);                       // tetap 50%, tidak berubah jadi 100%
        $this->assertEquals(5500000, $kk->titipan_biaya_akad_ditagih);
    }

    public function test_pembayaran_titipan_dibatasi_oleh_nominal_neto(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 6000000]]);   // ditagih 5 jt
        $keuangan = $this->user('admin_keuangan');

        $this->actingAs($keuangan)->post(route('titipan-biaya-akad.cicilan.store', $kk), ['jumlah' => 5500000, 'tanggal_bayar' => now()->toDateString()])->assertSessionHasErrors();   // melebihi sisa neto
        $this->actingAs($keuangan)->post(route('titipan-biaya-akad.cicilan.store', $kk), ['jumlah' => 5000000, 'tanggal_bayar' => now()->toDateString()])->assertSessionHasNoErrors();

        $this->assertSame('lunas', $this->barisTitipan($kk)['status']);
    }

    public function test_pengaturan_menyimpan_lawan_titipan_biaya_akad_dan_form_memuatnya(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->put(route('pengaturan.promo.lawan', $this->promoLabel), ['titipan_biaya_akad' => true, 'tanah' => false, 'harga_jual' => false, 'biaya_tambahan_preset_ids' => []])
            ->assertSessionHas('success');

        $form = PromoPreset::untukForm()->firstWhere('id', $this->promoLabel->id);
        $this->assertTrue($form['lawan_titipan_biaya_akad']);
        $this->assertFalse($form['lawan_tanah']);
        $this->assertTrue(PotonganPromo::lawan($this->promoLabel->id)['titipan']);
        $this->assertFalse(PotonganPromo::lawan($this->promoTanah->id)['titipan']);

        $this->actingAs($admin)->put(route('pengaturan.promo.lawan', $this->promoLabel), ['titipan_biaya_akad' => false, 'tanah' => false, 'harga_jual' => false, 'biaya_tambahan_preset_ids' => []]);
        $this->assertFalse(PromoPreset::untukForm()->firstWhere('id', $this->promoLabel->id)['lawan_titipan_biaya_akad']);
    }

    public function test_dashboard_menghitung_nilai_promo_titipan_dan_nilai_transaksi_neto(): void
    {
        $this->booking(['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 3500000]]);
        $this->booking(['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'persen', 'nilai' => 10]]);   // 1,1 jt
        $this->booking();                                                                                                                   // tanpa potongan

        $html = $this->actingAs($this->user('superadmin'))->withSession(['current_project_id' => $this->project->id])->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $f = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props']['financials'];

        $this->assertEquals(4600000, $f['nilai_promo']['titipan_biaya_akad']);
        $this->assertEquals(4600000, $f['nilai_promo']['total']);
        $this->assertSame(2, $f['nilai_promo']['jumlah_transaksi']);
        $this->assertEquals(33000000 - 4600000, $f['nilai_transaksi_rincian']['titipan']['titipan_biaya_akad']);   // tiga titipan Rp 11 jt, neto setelah potongan
    }

    public function test_rekap_kekurangan_memakai_nominal_titipan_neto(): void
    {
        $kk = $this->booking(['promo_preset_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'persen', 'nilai' => 50]]);

        $html = $this->actingAs($this->user('superadmin'))->withSession(['current_project_id' => $this->project->id])
            ->get(route('keuangan.rekap-kekurangan', ['tampilan' => 'piutang_kpr', 'tahap' => ['pemberkasan'], 'mode' => 'semua']))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $baris = collect(json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props']['rows']['data'])->firstWhere('id', $kk->id);

        $this->assertEquals(5500000, $baris['sel']['k:titipan']['nominal']);
    }
}
