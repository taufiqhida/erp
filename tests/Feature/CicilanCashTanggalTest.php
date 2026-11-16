<?php

namespace Tests\Feature;

use App\Enums\StatusJual;
use App\Models\JadwalTagihan;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
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
 * Rilis 3 / R2b: cicilan cash boleh tanpa tanggal sendiri (jatuh tempo mengikuti tanggal rencana akad, dinamis), cicilan Pelunasan
 * bisa dipecah dari cicilan terakhir, dan DP cash berlabel "DP / Pengikatan".
 */
class CicilanCashTanggalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private TipeUnitPreset $tipe;
    private SalesAgent $agen;
    private SumberLead $lead;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->admin->projects()->attach($this->project->id);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->agen = SalesAgent::create(['nama' => 'Agen 1', 'tipe' => 'sales', 'is_active' => true]);
        $this->lead = SumberLead::create(['nama' => 'Instagram', 'is_referral' => false, 'is_active' => true]);
    }

    private function skema(string $cara = 'cash', int $dpTenor = 1): SkemaDpPreset
    {
        return SkemaDpPreset::create([
            'nama' => "{$cara} " . ++$this->nomor, 'cara_bayar' => $cara,
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => true,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 10000000, 'dp_tenor' => $dpTenor, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto',
        ]);
    }

    private function booking(string $cara = 'cash', int $dpTenor = 1, array $ubah = []): KavlingKonsumen
    {
        $this->nomor++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor,
            'status_jual' => 'available', 'harga' => 200000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);
        $this->actingAs($sales)->post(route('bookings.store', $unit), array_merge([
            'tanggal_booking' => '2026-03-01', 'konsumen_nama' => 'Budi ' . $this->nomor, 'konsumen_no_hp' => '0812', 'konsumen_nik' => '32010000000000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT),
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agen->id, 'cara_bayar' => $cara, 'cicilan_kali' => $cara === 'cash_bertahap' ? 3 : null,
            'skema_dp_preset_id' => $this->skema($cara === 'cash_bertahap' ? 'cash_bertahap' : ($cara === 'cash' ? 'cash' : 'kpr_subsidi'), $dpTenor)->id,
        ], $ubah))->assertSessionHasNoErrors();

        return KavlingKonsumen::where('kavling_id', $unit->id)->firstOrFail();
    }

    private function jadwal(KavlingKonsumen $kk, string $jenis)
    {
        return $kk->jadwalTagihans()->where('jenis', $jenis)->orderBy('nomor_cicilan')->get();
    }

    private function ke(string $route, $param, array $data = [], string $method = 'post')
    {
        return $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])->{$method}(route($route, $param), $data);
    }

    public function test_booking_cash_membuat_dp_dan_pelunasan_tunggal_tanpa_tanggal_sendiri(): void
    {
        $kk = $this->booking('cash');

        $this->assertNotNull($this->jadwal($kk, 'booking_fee')->first()->tanggal_jatuh_tempo);          // Booking Fee tetap bertanggal
        $this->assertNull($this->jadwal($kk, 'dp')->first()->tanggal_jatuh_tempo);
        $this->assertNull($this->jadwal($kk, 'pelunasan')->first()->tanggal_jatuh_tempo);
    }

    public function test_cash_bertahap_dan_dp_bertenor_tetap_memakai_jadwal_bulanan(): void
    {
        $bertahap = $this->booking('cash_bertahap');
        $this->assertCount(3, $this->jadwal($bertahap, 'pelunasan'));
        $this->assertSame('2026-03-01', $this->jadwal($bertahap, 'pelunasan')[0]->tanggal_jatuh_tempo->format('Y-m-d'));

        $dpTenor = $this->booking('cash', 2);
        $this->assertSame('2026-04-01', $this->jadwal($dpTenor, 'dp')[1]->tanggal_jatuh_tempo->format('Y-m-d'));
    }

    public function test_kpr_tidak_berubah_dp_tetap_bertanggal(): void
    {
        $kpr = $this->booking('kpr_subsidi');

        $this->assertSame('2026-03-01', $this->jadwal($kpr, 'dp')->first()->tanggal_jatuh_tempo->format('Y-m-d'));
    }

    public function test_jatuh_tempo_efektif_mengikuti_rencana_akad_dan_ikut_bergeser(): void
    {
        $kk = $this->booking('cash');
        $dp = $this->jadwal($kk, 'dp')->first();

        $this->assertNull($dp->fresh()->tanggal_tempo);                                              // rencana akad belum ada: menunggu
        $this->assertFalse($dp->fresh()->is_terlambat);
        $this->assertTrue($dp->fresh()->mengikuti_rencana_akad);

        $kk->update(['tanggal_rencana_akad' => '2026-04-10']);
        $this->assertSame('2026-04-10', $dp->fresh()->tanggal_tempo->format('Y-m-d'));
        $this->assertTrue($dp->fresh()->is_terlambat);                                               // rencana akad sudah lewat (hari ini jauh sesudahnya)
        $this->assertSame('2026-04-10', $kk->fresh()->fin_jatuh_tempo_berikutnya);                   // jatuh tempo terdekat ikut rencana akad (Booking Fee sudah lunas)

        $kk->update(['tanggal_rencana_akad' => '2099-01-20']);                                       // rencana akad diundur
        $this->assertSame('2099-01-20', $dp->fresh()->tanggal_tempo->format('Y-m-d'));
        $this->assertFalse($dp->fresh()->is_terlambat);
        $this->assertSame('2099-01-20', $kk->fresh()->fin_jatuh_tempo_berikutnya);
    }

    public function test_dashboard_menghitung_cicilan_tanpa_tanggal_bila_rencana_akadnya_sudah_lewat(): void
    {
        $kk = $this->booking('cash');
        $kk->update(['tanggal_rencana_akad' => '2026-04-10']);

        $this->ke('dashboard', [], [], 'get')->assertOk()
            ->assertInertia(fn ($p) => $p->where('piutangJatuhTempo.total_count', fn ($n) => $n >= 2));       // DP + Pelunasan

        $kk->update(['tanggal_rencana_akad' => '2099-01-20']);
        $this->ke('dashboard', [], [], 'get')->assertOk()->assertInertia(fn ($p) => $p->where('piutangJatuhTempo.total_count', 0));
    }

    public function test_halaman_menampilkan_tanggal_efektif_dan_penanda_mengikuti_rencana_akad(): void
    {
        $kk = $this->booking('cash');
        $kk->update(['tanggal_rencana_akad' => '2099-01-20']);

        $this->ke('keuangan.detail', $kk, [], 'get')->assertOk()->assertInertia(fn ($p) => $p->where('transaksi.jadwal_tagihan', function ($j) {
            $dp = collect($j)->firstWhere('jenis', 'dp');

            return $dp['mengikuti_rencana_akad'] === true && $dp['tanggal_jatuh_tempo'] === '20 Jan 2099' && $dp['tanggal_jatuh_tempo_raw'] === null && $dp['jenis_label'] === 'DP / Pengikatan';
        }));
        $this->ke('keuangan.detail', $kk, [], 'get')->assertInertia(fn ($p) => $p->where('transaksi.kartu_piutang_static', fn ($k) => collect($k)->firstWhere('type', 'dp_group')['label'] === 'DP / Pengikatan'));
    }

    public function test_tambah_cicilan_pecah_dari_cicilan_terakhir_menjaga_total_pelunasan(): void
    {
        $kk = $this->booking('cash');                                                                // Pelunasan 189 jt, satu cicilan
        $this->ke('pelunasan.cicilan.store', $kk, ['jumlah' => 18900000, 'tanggal_jatuh_tempo' => null, 'dari_cicilan_terakhir' => true])->assertSessionHasNoErrors();

        $rows = $this->jadwal($kk, 'pelunasan');
        $this->assertCount(2, $rows);
        $this->assertEquals(189000000 - 18900000, $rows[0]->jumlah);
        $this->assertEquals(18900000, $rows[1]->jumlah);
        $this->assertNull($rows[1]->tanggal_jatuh_tempo);                                            // mengikuti rencana akad
        $this->assertEquals(189000000, $rows->sum('jumlah'));
        $this->assertEquals(0, collect($kk->fresh(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])->kartuPiutangBreakdown()['kartu_piutang_static'])->firstWhere('type', 'pelunasan_group')['selisih_jadwal']);
    }

    public function test_pecah_ditolak_bila_nominal_tidak_lebih_kecil_dari_cicilan_terakhir_belum_dibayar(): void
    {
        $kk = $this->booking('cash');

        $this->ke('pelunasan.cicilan.store', $kk, ['jumlah' => 189000000, 'dari_cicilan_terakhir' => true])->assertSessionHasErrors('jumlah');
        $this->assertCount(1, $this->jadwal($kk, 'pelunasan'));
    }

    public function test_tambah_cicilan_tanpa_pecah_menambah_total_dan_selisihnya_ditandai(): void
    {
        $kk = $this->booking('cash');
        $this->ke('pelunasan.cicilan.store', $kk, ['jumlah' => 5000000, 'tanggal_jatuh_tempo' => '2026-06-01', 'dari_cicilan_terakhir' => false])->assertSessionHasNoErrors();

        $pelunasan = collect($kk->fresh(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])->kartuPiutangBreakdown()['kartu_piutang_static'])->firstWhere('type', 'pelunasan_group');
        $this->assertEquals(194000000, $pelunasan['nominal']);
        $this->assertEquals(189000000, $pelunasan['nominal_seharusnya']);
        $this->assertEquals(5000000, $pelunasan['selisih_jadwal']);
    }

    public function test_cicilan_yang_sudah_dibayar_tidak_ikut_dipecah(): void
    {
        $kk = $this->booking('cash_bertahap');                                                       // 3 cicilan 63 jt
        $pertama = $this->jadwal($kk, 'pelunasan')[0];
        $pertama->update(['status' => 'lunas', 'pembayaran_konsumen_id' => $kk->pembayarans()->create(['jenis' => 'pelunasan', 'jumlah' => 63000000, 'tanggal_bayar' => now()])->id]);

        $this->ke('pelunasan.cicilan.store', $kk, ['jumlah' => 10000000, 'dari_cicilan_terakhir' => true])->assertSessionHasNoErrors();

        $rows = $this->jadwal($kk, 'pelunasan');
        $this->assertEquals(63000000, $rows[0]->jumlah);
        $this->assertEquals(63000000, $rows[1]->jumlah);
        $this->assertEquals(53000000, $rows[2]->jumlah);                                             // yang terakhir belum dibayar yang dipotong
        $this->assertEquals(10000000, $rows[3]->jumlah);
    }

    public function test_ikuti_rencana_akad_menghapus_tanggal_sendiri_hanya_untuk_dp_pelunasan_cash(): void
    {
        $bertahap = $this->booking('cash_bertahap');
        $j = $this->jadwal($bertahap, 'pelunasan')[0];
        $this->assertNotNull($j->tanggal_jatuh_tempo);
        $this->ke('jadwal-tagihan.update-tanggal', $j, ['ikuti_rencana_akad' => true], 'patch')->assertSessionHas('success');
        $this->assertNull($j->fresh()->tanggal_jatuh_tempo);

        $this->ke('jadwal-tagihan.update-tanggal', $this->jadwal($bertahap, 'booking_fee')->first(), ['ikuti_rencana_akad' => true], 'patch')->assertStatus(422);

        $kpr = $this->booking('kpr_subsidi');
        $this->ke('jadwal-tagihan.update-tanggal', $this->jadwal($kpr, 'dp')->first(), ['ikuti_rencana_akad' => true], 'patch')->assertStatus(422);
        $this->assertNotNull($this->jadwal($kpr, 'dp')->first()->tanggal_jatuh_tempo);
    }
}
