<?php

namespace Tests\Feature;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Models\CancellationRequest;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\PencairanKprTahap;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tahap terakhir saat batal, peringatan di modal persetujuan, dan kartu pembatalan di profil konsumen. */
class TahapBatalTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Kavling $unit;
    private KavlingKonsumen $trx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $konsumen = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        $this->trx = KavlingKonsumen::create(['kavling_id' => $this->unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'status_penjualan' => 'sp3k',
            'cara_bayar' => 'kpr_subsidi', 'tanggal_booking' => '2026-10-10', 'harga_dasar' => 300000000, 'harga_deal' => 300000000]);
        PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => 'booking_fee', 'jumlah' => 5000000, 'tanggal_bayar' => '2026-10-10', 'keterangan' => 'UTJ']);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['name' => ucfirst($role) . ' Uji']);
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function ajukanBatal(): CancellationRequest
    {
        return CancellationRequest::create([
            'type' => CancellationRequestType::Cancellation, 'kavling_id' => $this->unit->id, 'kavling_konsumen_id' => $this->trx->id,
            'konsumen_id' => $this->trx->konsumen_id, 'alasan' => 'Konsumen mundur sendiri', 'requested_by' => $this->user('admin_sales')->id,
            'status' => CancellationStatus::Pending, 'kavling_status_before' => 'booked',
        ]);
    }

    private function props(string $route, $params = [])
    {
        $html = $this->actingAs($this->user('spv'))->withSession(['current_project_id' => $this->project->id])->get(route($route, $params))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    public function test_persetujuan_menyimpan_tahap_terakhir_sebelum_status_menjadi_batal(): void
    {
        $p = $this->ajukanBatal();

        $this->actingAs($this->user('spv'))->patch(route('cancellation-requests.approve', $p), ['nominal_dikembalikan' => 1000000, 'catatan_reviewer' => 'oke'])
            ->assertSessionHas('success');

        $p->refresh();
        $this->assertSame('sp3k', $p->tahap_batal);
        $this->assertSame('SP3K', $p->tahap_batal_label);
        $this->assertSame('batal', $this->trx->fresh()->status_penjualan);
    }

    public function test_modal_persetujuan_memuat_tahap_sekarang_dan_tanpa_peringatan_di_tahap_awal(): void
    {
        $this->ajukanBatal();

        $baris = $this->props('cancellation-requests.index')['requests']['data'][0];

        $this->assertSame('SP3K', $baris['tahap_sekarang']);
        $this->assertSame([], $baris['peringatan']);
        $this->assertNull($baris['tahap_batal']);
    }

    public function test_peringatan_muncul_bila_sudah_akad_dan_ada_pencairan_bank(): void
    {
        $this->trx->update(['status_penjualan' => 'akad']);
        PencairanKprTahap::create(['kavling_konsumen_id' => $this->trx->id, 'nominal' => 150000000, 'tanggal_cair' => '2026-10-20', 'keterangan' => 'Tahap 1']);
        $this->ajukanBatal();

        $baris = $this->props('cancellation-requests.index')['requests']['data'][0];

        $this->assertCount(2, $baris['peringatan']);
        $this->assertStringContainsString('Akad', $baris['peringatan'][0]);
        $this->assertStringContainsString('150.000.000', $baris['peringatan'][1]);
    }

    public function test_profil_konsumen_menampilkan_tanggal_batal_alasan_dan_tahap(): void
    {
        $p = $this->ajukanBatal();
        $this->actingAs($this->user('spv'))->patch(route('cancellation-requests.approve', $p), ['nominal_dikembalikan' => 2000000, 'catatan_reviewer' => 'kembali 2jt']);

        $trx = collect($this->props('konsumens.show', $this->trx->konsumen_id)['transaksis'])->firstWhere('id', $this->trx->id);

        $this->assertNotNull($trx['pembatalan']);
        $this->assertSame('Konsumen mundur sendiri', $trx['pembatalan']['alasan']);
        $this->assertSame('SP3K', $trx['pembatalan']['tahap']);
        $this->assertSame(now()->format('d M Y'), $trx['pembatalan']['tanggal']);
        $this->assertEquals(5000000, $trx['pembatalan']['diterima']);
        $this->assertEquals(2000000, $trx['pembatalan']['dikembalikan']);
        $this->assertEquals(3000000, $trx['pembatalan']['hangus']);
        $this->assertSame('kembali 2jt', $trx['pembatalan']['catatan']);
    }

    public function test_transaksi_aktif_tidak_punya_kartu_pembatalan(): void
    {
        $trx = collect($this->props('konsumens.show', $this->trx->konsumen_id)['transaksis'])->firstWhere('id', $this->trx->id);

        $this->assertNull($trx['pembatalan']);
    }
}
