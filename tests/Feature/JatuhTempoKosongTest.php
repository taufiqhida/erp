<?php

namespace Tests\Feature;

use App\Enums\StatusJual;
use App\Models\JadwalTagihan;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\KoreksiTanggalBooking;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persiapan R2b: jatuh tempo cicilan boleh KOSONG (= menunggu tanggal rencana akad). Tes asap: setiap tempat yang membaca jatuh tempo
 * tidak boleh error 500, kosong tidak dianggap terlambat, tidak ikut digeser saat tanggal booking dikoreksi, dan tidak masuk daftar
 * piutang jatuh tempo.
 */
class JatuhTempoKosongTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private KavlingKonsumen $kk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Proyek Uji', 'kode' => 'PU1', 'kota' => 'X', 'is_active' => true]);
        $this->admin->projects()->attach($this->project->id);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id,
            'blok' => 'A1', 'nomor_kavling' => '1', 'harga' => 100000000, 'status_jual' => StatusJual::Booked]);
        $this->kk = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812'])->id, 'status' => 'active',
            'status_penjualan' => 'pemberkasan', 'cara_bayar' => 'cash_bertahap', 'tanggal_booking' => '2026-03-01', 'harga_dasar' => 100000000, 'harga_deal' => 100000000, 'booking_fee' => 1000000]);
    }

    private function jadwal(string $jenis, int $nomor, float $jumlah, ?string $tanggal, string $status = 'belum_bayar'): JadwalTagihan
    {
        return $this->kk->jadwalTagihans()->create(['jenis' => $jenis, 'nomor_cicilan' => $nomor, 'jumlah' => $jumlah, 'tanggal_jatuh_tempo' => $tanggal, 'status' => $status]);
    }

    private function halaman(string $route, mixed $param = [])
    {
        return $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])->get(route($route, $param))->assertOk();
    }

    public function test_jadwal_dengan_jatuh_tempo_kosong_bisa_disimpan_dan_tidak_dianggap_terlambat(): void
    {
        $kosong = $this->jadwal('dp', 1, 5000000, null);
        $lewat = $this->jadwal('pelunasan', 1, 5000000, '2026-01-01');

        $this->assertNull($kosong->fresh()->tanggal_jatuh_tempo);
        $this->assertFalse($kosong->fresh()->is_terlambat);
        $this->assertTrue($lewat->fresh()->is_terlambat);
    }

    public function test_semua_halaman_pembaca_jatuh_tempo_tetap_terbuka_dengan_tanggal_kosong(): void
    {
        $this->jadwal('booking_fee', 1, 1000000, '2026-03-01', 'lunas');
        $this->jadwal('dp', 1, 5000000, null);
        $this->jadwal('pelunasan', 1, 94000000, null);
        $this->kk->refreshFinance();

        $this->assertNull($this->kk->fresh()->fin_jatuh_tempo_berikutnya);                 // tidak ada tanggal terdekat

        $this->halaman('keuangan.index', ['tampil' => 'semua']);
        $detail = $this->halaman('keuangan.detail', $this->kk);
        $detail->assertInertia(fn ($p) => $p->where('transaksi.jadwal_tagihan', fn ($j) => collect($j)->where('jenis', 'dp')->first()['tanggal_jatuh_tempo'] === null));
        $this->halaman('konsumens.show', $this->kk->konsumen_id)
            ->assertInertia(fn ($p) => $p->where('transaksis.0.jadwal_tagihan', fn ($j) => collect($j)->where('jenis', 'pelunasan')->first()['tanggal_jatuh_tempo_raw'] === null));
        $this->halaman('dashboard');
        $this->halaman('keuangan.rekap-kekurangan', ['tampilan' => 'piutang_cash', 'mode' => 'semua']);
        $this->halaman('penjualan.project', ['project' => $this->project->id]);
    }

    public function test_dashboard_piutang_jatuh_tempo_hanya_menghitung_cicilan_bertanggal(): void
    {
        $this->jadwal('dp', 1, 5000000, null);                                    // kosong: tidak dihitung
        $this->jadwal('pelunasan', 1, 7000000, '2026-01-01');                     // bertanggal dan lewat: dihitung

        $this->halaman('dashboard')->assertInertia(fn ($p) => $p->where('piutangJatuhTempo.total_count', 1)->where('piutangJatuhTempo.total_nominal', fn ($n) => (float) $n === 7000000.0));
    }

    public function test_koreksi_tanggal_booking_tidak_menggeser_atau_menyentuh_jatuh_tempo_kosong(): void
    {
        $this->jadwal('booking_fee', 1, 1000000, '2026-03-01');
        $kosong = $this->jadwal('dp', 1, 5000000, null);
        $bertanggal = $this->jadwal('pelunasan', 1, 94000000, '2026-04-01');

        $rencana = KoreksiTanggalBooking::rencana($this->kk, Carbon::parse('2026-03-11'));
        $this->assertNotContains($kosong->id, $rencana['jadwal_ids']);
        $this->assertSame(0, $rencana['terkunci']);

        KoreksiTanggalBooking::terapkan($this->kk->fresh(), Carbon::parse('2026-03-11'), 'koreksi uji', $this->admin);

        $this->assertNull($kosong->fresh()->tanggal_jatuh_tempo);                                      // tetap kosong
        $this->assertSame('2026-04-11', $bertanggal->fresh()->tanggal_jatuh_tempo->format('Y-m-d'));    // bergeser 10 hari
    }

    public function test_cicilan_kosong_yang_sudah_dibayar_tidak_merusak_ringkasan_pembayaran(): void
    {
        $bayar = PembayaranKonsumen::create(['kavling_konsumen_id' => $this->kk->id, 'jenis' => 'dp', 'jumlah' => 5000000, 'tanggal_bayar' => '2026-03-05', 'keterangan' => 'uji']);
        $j = $this->jadwal('dp', 1, 5000000, null, 'lunas');
        $j->update(['pembayaran_konsumen_id' => $bayar->id]);
        $this->kk->refreshFinance();

        $this->halaman('keuangan.detail', $this->kk);
        $this->assertEquals(5000000, $this->kk->fresh()->fin_terbayar_konsumen);
    }
}
