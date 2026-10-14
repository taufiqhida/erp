<?php

namespace Tests\Feature;

use App\Models\CancellationRequest;
use App\Models\CatatanTransaksi;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Tukar unit ke unit berbeda harga: SPV memilih mempertahankan harga lama (default) atau mengikuti harga unit baru. */
class TukarUnitHargaTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;
    private User $spv;
    private Project $project;
    private Kavling $lama;
    private Kavling $baru;
    private KavlingKonsumen $trx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->lama = $this->unit($tipe, '1', 300000000, 'booked');
        $this->baru = $this->unit($tipe, '2', 320000000, 'available');
        $konsumen = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        // harga dasar 300 jt + biaya tambahan 10 jt = subtotal 310 jt; diskon 2% = 6,2 jt; harga deal 303,8 jt.
        $this->trx = KavlingKonsumen::create(['kavling_id' => $this->lama->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
            'cara_bayar' => 'cash', 'tanggal_booking' => now(), 'harga_dasar' => 300000000, 'total_biaya_tambahan' => 10000000,
            'diskon_mode' => 'persen', 'diskon_nilai' => 2, 'diskon_nominal' => 6200000, 'harga_deal' => 303800000]);
        $this->sales = $this->user('admin_sales');
        $this->spv = $this->user('spv');
    }

    private function unit(TipeUnitPreset $tipe, string $nomor, int $harga, string $status): Kavling
    {
        return Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => $nomor, 'status_jual' => $status,
            'harga' => $harga, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['name' => ucfirst($role) . ' Uji']);
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function ajukanTukar(): CancellationRequest
    {
        $this->actingAs($this->sales)->post(route('cancellation-requests.store'), ['type' => 'unit_swap', 'kavling_id' => $this->lama->id, 'kavling_konsumen_id' => $this->trx->id,
            'kavling_baru_id' => $this->baru->id, 'alasan' => 'Salah input blok saat booking'])->assertSessionHas('success');

        return CancellationRequest::where('type', 'unit_swap')->firstOrFail();
    }

    private function setujui(CancellationRequest $p, array $data = [])
    {
        return $this->actingAs($this->spv)->patch(route('cancellation-requests.approve', $p), $data);
    }

    public function test_tanpa_pilihan_harga_transaksi_tetap_seperti_sebelumnya(): void
    {
        $p = $this->ajukanTukar();
        $this->setujui($p, ['catatan_reviewer' => 'oke'])->assertSessionHas('success');

        $trx = $this->trx->fresh();
        $this->assertSame($this->baru->id, $trx->kavling_id);                                    // unit berpindah
        $this->assertEquals(300000000, $trx->harga_dasar);                                       // harga tidak berubah sendiri
        $this->assertEquals(303800000, $trx->harga_deal);
        $this->assertSame('available', $this->lama->fresh()->status_jual->value);
        $this->assertSame('booked', $this->baru->fresh()->status_jual->value);
    }

    public function test_mengikuti_harga_unit_baru_menghitung_ulang_harga_dasar_diskon_persen_dan_harga_deal(): void
    {
        $p = $this->ajukanTukar();
        $this->setujui($p, ['ikuti_harga_baru' => true])->assertSessionHas('success');

        $trx = $this->trx->fresh();
        // subtotal 320 jt + 10 jt = 330 jt; diskon 2% = 6,6 jt; harga deal 323,4 jt.
        $this->assertEquals(320000000, $trx->harga_dasar);
        $this->assertEquals(6600000, $trx->diskon_nominal);
        $this->assertEquals(323400000, $trx->harga_deal);
        $this->assertEquals(10000000, $trx->total_biaya_tambahan);                              // biaya tambahan tidak disentuh

        $this->assertTrue(Activity::where('log_name', 'transaksi')->where('description', 'like', 'Harga transaksi disesuaikan%')->exists());
        $catatan = CatatanTransaksi::where('tahap', 'Tukar unit')->firstOrFail();
        $this->assertStringContainsString('300.000.000', $catatan->isi);
        $this->assertStringContainsString('320.000.000', $catatan->isi);
    }

    public function test_diskon_nominal_tetap_dan_mengikuti_harga_unit_lebih_murah(): void
    {
        $this->trx->update(['diskon_mode' => 'nominal', 'diskon_nilai' => 5000000, 'diskon_nominal' => 5000000, 'harga_deal' => 305000000]);
        $this->baru->update(['harga' => 280000000]);
        $p = $this->ajukanTukar();

        $this->setujui($p, ['ikuti_harga_baru' => true])->assertSessionHas('success');

        $trx = $this->trx->fresh();
        $this->assertEquals(280000000, $trx->harga_dasar);
        $this->assertEquals(5000000, $trx->diskon_nominal);                                      // diskon nominal tidak berubah
        $this->assertEquals(285000000, $trx->harga_deal);                                        // 280 + 10 − 5
    }

    public function test_harga_unit_sama_tidak_mengubah_apa_pun_walau_dipilih_mengikuti(): void
    {
        $this->baru->update(['harga' => 300000000]);
        $p = $this->ajukanTukar();
        $this->setujui($p, ['ikuti_harga_baru' => true])->assertSessionHas('success');

        $this->assertEquals(303800000, $this->trx->fresh()->harga_deal);
        $this->assertSame(0, CatatanTransaksi::count());
        $this->assertFalse(Activity::where('description', 'like', 'Harga transaksi disesuaikan%')->exists());
    }

    public function test_unit_tujuan_tanpa_harga_tidak_bisa_diikuti(): void
    {
        $this->baru->update(['harga' => null]);
        $p = $this->ajukanTukar();

        $this->setujui($p, ['ikuti_harga_baru' => true])->assertStatus(422);

        $this->assertSame($this->lama->id, $this->trx->fresh()->kavling_id);                      // seluruh persetujuan dibatalkan
        $this->assertSame('pending', $p->fresh()->status->value);
    }

    public function test_pratinjau_harga_di_daftar_pembatalan_termasuk_peringatan_pembayaran(): void
    {
        $this->ajukanTukar();
        $ambil = fn () => collect($this->actingAs($this->spv)->get(route('cancellation-requests.index'))->assertOk()->viewData('page')['props']['requests']['data'])->firstWhere('type', 'unit_swap');

        $h = $ambil()['harga'];
        $this->assertEquals(300000000, $h['dasar_sekarang']);
        $this->assertEquals(320000000, $h['dasar_unit_baru']);
        $this->assertEquals(20000000, $h['selisih']);
        $this->assertEquals(303800000, $h['deal_sekarang']);
        $this->assertEquals(323400000, $h['deal_baru']);
        $this->assertFalse($h['sama']);
        $this->assertFalse($h['ada_pembayaran_harga']);

        PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => 'dp', 'jumlah' => 5000000, 'tanggal_bayar' => now()]);
        $this->assertTrue($ambil()['harga']['ada_pembayaran_harga']);
    }

    public function test_hanya_yang_berwenang_menyetujui_dan_pengajuan_lain_tidak_punya_pratinjau_harga(): void
    {
        $p = $this->ajukanTukar();
        $this->actingAs($this->sales)->patch(route('cancellation-requests.approve', $p), ['ikuti_harga_baru' => true])->assertStatus(403);
        $this->assertEquals(300000000, $this->trx->fresh()->harga_dasar);
    }
}
