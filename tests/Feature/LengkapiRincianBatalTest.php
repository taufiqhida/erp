<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\RincianDariSkema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Lengkapi rincian dari skema" untuk transaksi batal hasil import: harga dasar, booking fee, dan jadwal tagihan. */
class LengkapiRincianBatalTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Kavling $unit;
    private SkemaDpPreset $skemaKpr;
    private SkemaDpPreset $skemaCash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'available', 'harga' => 170000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        // UTJ Rp 1 jt + DP 1% dari harga dasar (= Rp 1,66 jt untuk harga 166 jt).
        $skema = fn ($cara, $nama) => SkemaDpPreset::create([
            'nama' => $nama, 'cara_bayar' => $cara,
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => true,
            'dp_aktif' => true, 'dp_tipe' => 'persen', 'dp_nilai' => 1, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_dasar',
        ]);
        $this->skemaKpr = $skema('kpr_subsidi', 'KPR Subsidi UTJ 1jt');
        $this->skemaCash = $skema('cash', 'Cash UTJ 1jt');
    }

    private function batal(array $o = []): KavlingKonsumen
    {
        $konsumen = Konsumen::create(['nama' => 'Budi Batal']);
        $trx = KavlingKonsumen::create($o + [
            'kavling_id' => $this->unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'cancelled', 'status_penjualan' => 'batal',
            'cara_bayar' => 'kpr_subsidi', 'skema_dp_preset_id' => $this->skemaKpr->id, 'tanggal_booking' => '2026-03-01', 'harga_deal' => 166000000,
        ]);
        PembayaranKonsumen::create(['kavling_konsumen_id' => $trx->id, 'jenis' => 'uang_masuk_batal', 'jumlah' => 500000, 'tanggal_bayar' => '2026-03-02', 'keterangan' => 'Saldo Awal (Import)']);

        return $trx;
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    public function test_membangun_harga_dasar_booking_fee_dan_jadwal_dari_skema_tanpa_menyentuh_pembayaran(): void
    {
        $trx = $this->batal();

        $this->assertSame(2, RincianDariSkema::terapkan($trx));

        $trx->refresh();
        $this->assertEquals(166000000, $trx->harga_dasar);
        $this->assertEquals(1000000, $trx->booking_fee);
        $jadwal = $trx->jadwalTagihans()->orderBy('jenis')->get();
        $this->assertCount(2, $jadwal);
        $this->assertEquals(1000000, $jadwal->firstWhere('jenis', 'booking_fee')->jumlah);
        $this->assertEquals(1660000, $jadwal->firstWhere('jenis', 'dp')->jumlah);
        $this->assertTrue($jadwal->every(fn ($j) => $j->status === 'belum_bayar' && $j->pembayaran_konsumen_id === null));
        // Uang yang diterima tetap satu pembayaran, tidak dibagi ke tagihan.
        $this->assertSame(1, $trx->pembayarans()->count());
        $this->assertEquals(500000, $trx->pembayarans()->first()->jumlah);
    }

    public function test_cash_membuat_pelunasan_sebagai_sisa_harga(): void
    {
        $trx = $this->batal(['cara_bayar' => 'cash', 'skema_dp_preset_id' => $this->skemaCash->id]);

        RincianDariSkema::terapkan($trx);

        $pelunasan = $trx->jadwalTagihans()->where('jenis', 'pelunasan')->get();
        $this->assertCount(1, $pelunasan);
        $this->assertEquals(166000000 - 1000000 - 1660000, $pelunasan->first()->jumlah);
    }

    public function test_aman_diulang_dan_tidak_menggandakan_jadwal(): void
    {
        $trx = $this->batal();

        RincianDariSkema::terapkan($trx);
        $this->assertNull(RincianDariSkema::terapkan($trx->fresh()));
        $this->assertSame(2, $trx->jadwalTagihans()->count());
    }

    public function test_alasan_dilewati(): void
    {
        $tanpaSkema = $this->batal(['skema_dp_preset_id' => null]);
        $this->assertStringContainsString('Skema', RincianDariSkema::alasanTidakBisa($tanpaSkema));

        $aktif = $this->batal(['status' => 'active', 'status_penjualan' => 'pemberkasan']);
        $this->assertStringContainsString('Bukan transaksi batal', RincianDariSkema::alasanTidakBisa($aktif));
        $this->assertNull(RincianDariSkema::terapkan($aktif));
        $this->assertSame(0, $aktif->jadwalTagihans()->count());
    }

    public function test_pratinjau_massal_tidak_mengubah_data_lalu_penerapan_melengkapi(): void
    {
        $a = $this->batal();
        $b = $this->batal(['skema_dp_preset_id' => null]);
        $spv = $this->user('spv');

        $r = $this->actingAs($spv)->postJson(route('konsumens.lengkapi-rincian', $this->project), ['simulasi' => true])->assertOk();
        $r->assertJson(['bisa' => 1, 'lewati' => 1]);
        $this->assertSame(0, $a->jadwalTagihans()->count());

        $this->actingAs($spv)->post(route('konsumens.lengkapi-rincian', $this->project))->assertSessionHas('success');
        $this->assertSame(2, $a->jadwalTagihans()->count());
        $this->assertSame(0, $b->jadwalTagihans()->count());
    }

    public function test_satu_transaksi_lewat_tombol(): void
    {
        $trx = $this->batal();

        $this->actingAs($this->user('spv'))->post(route('konsumens.lengkapi-rincian-satu', $trx))->assertSessionHas('success');
        $this->assertSame(2, $trx->jadwalTagihans()->count());

        // Kedua kalinya ditolak (sudah punya jadwal).
        $this->actingAs($this->user('spv'))->postJson(route('konsumens.lengkapi-rincian-satu', $trx))->assertStatus(422);
    }

    public function test_admin_sales_tidak_boleh(): void
    {
        $trx = $this->batal();
        $sales = $this->user('admin_sales');

        $this->actingAs($sales)->post(route('konsumens.lengkapi-rincian', $this->project))->assertForbidden();
        $this->actingAs($sales)->post(route('konsumens.lengkapi-rincian-satu', $trx))->assertForbidden();
        $this->assertSame(0, $trx->jadwalTagihans()->count());
    }

    public function test_profil_menampilkan_sales_dan_tombol_lengkapi_lalu_halaman_keuangan_tetap_terbuka(): void
    {
        $agen = SalesAgent::create(['nama' => 'Agen Satu', 'tipe' => 'sales', 'is_active' => true]);
        $trx = $this->batal(['sales_agent_id' => $agen->id]);
        $spv = $this->user('spv');

        $html = $this->actingAs($spv)->withSession(['current_project_id' => $this->project->id])->get(route('konsumens.show', $trx->konsumen_id))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $t = collect(json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props']['transaksis'])->firstWhere('id', $trx->id);
        $this->assertSame('Agen Satu', $t['sales_nama']);
        $this->assertTrue($t['bisa_lengkapi_rincian']);

        RincianDariSkema::terapkan($trx);

        $this->actingAs($spv)->withSession(['current_project_id' => $this->project->id])->get(route('keuangan.detail', $trx))->assertOk();
        $html = $this->actingAs($spv)->get(route('konsumens.show', $trx->konsumen_id))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $t = collect(json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props']['transaksis'])->firstWhere('id', $trx->id);
        $this->assertFalse($t['bisa_lengkapi_rincian']);
    }
}
