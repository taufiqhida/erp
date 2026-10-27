<?php

namespace Tests\Feature;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Models\CancellationRequest;
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

/** Ringkasan Finansial Dashboard: kolom Nilai Promo (transaksi aktif saja), Nilai Transaksi tetap bersih, hangus terpisah. */
class DashboardNilaiPromoTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $user;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->user = User::factory()->create();
        $this->user->assignRole('superadmin');
    }

    private function transaksi(array $o = []): KavlingKonsumen
    {
        $this->nomor++;
        $tipe = TipeUnitPreset::firstOrCreate(['project_id' => $this->project->id, 'nama' => '36/72'], ['is_active' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor,
            'status_jual' => 'booked', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        return KavlingKonsumen::create($o + [
            'kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => 'K ' . $this->nomor])->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
            'cara_bayar' => 'kpr_subsidi', 'tanggal_booking' => '2026-10-10', 'harga_dasar' => 166000000, 'harga_deal' => 166000000,
        ]);
    }

    private function financials(): array
    {
        $html = $this->actingAs($this->user)->withSession(['current_project_id' => $this->project->id])->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props']['financials'];
    }

    public function test_nilai_promo_dijumlah_per_jenis_dari_transaksi_aktif_saja(): void
    {
        $aktif = $this->transaksi([
            'diskon_mode' => 'nominal', 'diskon_nilai' => 2000000, 'diskon_nominal' => 2000000,
            'biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_nominal' => 8400000, 'biaya_kelebihan_tanah_potongan' => 4200000,
        ]);
        $aktif->biayaTambahans()->create(['nama' => 'Kavling Hook', 'nominal' => 5000000, 'potongan' => 1500000]);
        $this->transaksi();                                                                                // aktif tanpa promo
        // Transaksi BATAL yang punya promo: tidak boleh ikut (tidak ada biaya promo untuk transaksi yang tidak ada).
        $this->transaksi(['status' => 'cancelled', 'status_penjualan' => 'batal', 'diskon_nominal' => 9000000,
            'biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_nominal' => 8400000, 'biaya_kelebihan_tanah_potongan' => 8400000]);

        $p = $this->financials()['nilai_promo'];

        $this->assertEquals(2000000, $p['harga_jual']);
        $this->assertEquals(4200000, $p['tanah']);
        $this->assertEquals(1500000, $p['biaya_tambahan']);
        $this->assertEquals(7700000, $p['total']);
        $this->assertSame(1, $p['jumlah_transaksi']);
    }

    public function test_kolom_promo_hanya_keterangan_dan_tidak_mengubah_nilai_transaksi(): void
    {
        $this->transaksi(['biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_nominal' => 8400000, 'biaya_kelebihan_tanah_potongan' => 4200000, 'total_biaya_tambahan' => 4200000, 'harga_deal' => 170200000]);

        $f = $this->financials();

        $tanahTitipan = $f['nilai_transaksi_rincian']['titipan']['biaya_tanah'];
        $this->assertEquals(4200000, $tanahTitipan);                                   // nilai transaksi memakai angka bersih
        $this->assertEquals(4200000, $f['nilai_promo']['tanah']);                      // promo yang diberikan tercatat terpisah
        $this->assertEquals($f['total_pendapatan'], $f['nilai_transaksi_berjalan']);   // tanpa hangus: berjalan = total
    }

    public function test_hangus_tetap_terpisah_dari_nilai_transaksi_berjalan(): void
    {
        $this->transaksi();
        $batal = $this->transaksi(['status' => 'cancelled', 'status_penjualan' => 'batal']);
        CancellationRequest::create([
            'type' => CancellationRequestType::Cancellation, 'kavling_id' => $batal->kavling_id, 'kavling_konsumen_id' => $batal->id, 'konsumen_id' => $batal->konsumen_id,
            'requested_by' => $this->user->id, 'reviewed_by' => $this->user->id, 'reviewed_at' => now(), 'status' => CancellationStatus::Approved,
            'alasan' => 'Mundur sendiri', 'nominal_diterima' => 1000000, 'nominal_dikembalikan' => 0, 'nominal_hangus' => 1000000,
        ]);

        $f = $this->financials();

        $this->assertEquals(1000000, $f['hangus_dari_pembatalan']);
        $this->assertEquals($f['total_pendapatan'] - 1000000, $f['nilai_transaksi_berjalan']);
        $this->assertArrayHasKey('piutang_konsumen', $f);       // kolom piutang tidak berubah isinya
        $this->assertArrayHasKey('piutang_bank_rincian', $f);
    }
}
