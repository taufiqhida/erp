<?php

namespace Tests\Feature;

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

/**
 * Dashboard: Piutang Konsumen & Piutang Bank punya dua angka — potensi (semua transaksi aktif, sudah ada) dan "bisa dikejar" (yang realistis
 * ditagih sekarang): bank = sisa pencairan KPR sudah akad/BAST; konsumen KPR = sudah SP3K/rencana akad/akad/BAST; cash = cicilan lewat jatuh tempo atau ≤ 30 hari lagi (cicilan tanpa tanggal mengikuti rencana akad).
 */
class DashboardBisaDikejarTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $user;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->user = User::factory()->create();
        $this->user->assignRole('superadmin');
    }

    private function trx(string $tahap, string $cara = 'kpr_subsidi', array $o = []): KavlingKonsumen
    {
        $this->n++;
        $tipe = TipeUnitPreset::firstOrCreate(['project_id' => $this->project->id, 'nama' => '36/72'], ['is_active' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->n,
            'status_jual' => 'booked', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        return KavlingKonsumen::create($o + ['kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => 'K ' . $this->n])->id, 'status' => 'active',
            'status_penjualan' => $tahap, 'cara_bayar' => $cara, 'tanggal_booking' => '2026-09-01', 'harga_dasar' => 166000000, 'harga_deal' => 166000000]);
    }

    private function jadwal(KavlingKonsumen $trx, string $jenis, float $jumlah, ?string $tempo, bool $lunas = false): void
    {
        $data = ['jenis' => $jenis, 'nomor_cicilan' => $trx->jadwalTagihans()->where('jenis', $jenis)->count() + 1, 'jumlah' => $jumlah, 'tanggal_jatuh_tempo' => $tempo, 'status' => $lunas ? 'lunas' : 'belum_bayar'];
        if ($lunas) {
            $data['pembayaran_konsumen_id'] = PembayaranKonsumen::create(['kavling_konsumen_id' => $trx->id, 'jenis' => $jenis, 'jumlah' => $jumlah, 'tanggal_bayar' => '2026-09-02'])->id;
        }
        $trx->jadwalTagihans()->create($data);
    }

    private function dash(): array
    {
        return $this->props('dashboard')['financials'];
    }

    private function props(string $route, array $query = []): array
    {
        $html = $this->actingAs($this->user)->withSession(['current_project_id' => $this->project->id])->get(route($route, $query))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    public function test_bank_bisa_dikejar_hanya_kpr_akad_atau_bast_dan_cocok_dengan_tab_pencairan(): void
    {
        $this->trx('akad');                                                              // sisa penuh 166 jt
        $bast = $this->trx('bast');
        PencairanKprTahap::create(['kavling_konsumen_id' => $bast->id, 'nominal' => 100000000, 'tanggal_cair' => '2026-10-05']);   // sisa 66 jt
        $this->trx('pemberkasan');                                                       // belum akad: potensi saja
        $this->trx('rencana_akad');                                                      // belum akad: potensi saja
        $this->trx('akad', 'cash');                                                      // cash tidak punya piutang bank
        $this->trx('akad', 'kpr_subsidi', ['status' => 'cancelled', 'status_penjualan' => 'batal']);   // batal tidak ikut

        $f = $this->dash();

        $this->assertEquals(166000000 + 66000000, $f['bisa_dikejar']['bank']['nominal']);
        $this->assertSame(2, $f['bisa_dikejar']['bank']['jumlah']);
        $this->assertEquals(166000000 * 4 - 100000000, $f['sisa_piutang_bank']);          // potensi (semua KPR aktif) tidak berubah
        $this->assertGreaterThan($f['bisa_dikejar']['bank']['nominal'], $f['sisa_piutang_bank']);

        $pencairan = $this->props('keuangan.pencairan')['summary'];
        $this->assertEquals($pencairan['total_sisa'], $f['bisa_dikejar']['bank']['nominal']);   // satu kebenaran dengan tab Pencairan KPR
    }

    public function test_konsumen_kpr_bisa_dikejar_mulai_sp3k_dan_cocok_dengan_rekap_kekurangan(): void
    {
        foreach (['booking', 'pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad', 'akad', 'bast'] as $tahap) {
            $t = $this->trx($tahap);
            $this->jadwal($t, 'booking_fee', 1000000, '2026-09-01', true);
            $this->jadwal($t, 'dp', 1660000, now()->addDays(20)->toDateString());          // belum jatuh tempo pun tetap piutang bisa dikejar untuk KPR
        }

        $f = $this->dash();

        $this->assertEquals(4 * 1660000, $f['bisa_dikejar']['konsumen']['nominal']);      // sp3k, rencana_akad, akad, bast
        $this->assertSame(4, $f['bisa_dikejar']['konsumen']['jumlah']);
        $this->assertEquals(7 * 1660000, $f['sisa_piutang_konsumen']);                    // potensi: ketujuh transaksi

        $rekap = $this->props('keuangan.rekap-kekurangan', ['tampilan' => 'piutang_kpr'])['summary'];
        $this->assertEquals($rekap['total'], $f['bisa_dikejar']['konsumen']['nominal']);   // sama dengan Rekap Kekurangan (tahap bawaan)
    }

    public function test_cash_cicilan_lewat_jatuh_tempo_atau_dalam_30_hari_termasuk_yang_mengikuti_rencana_akad(): void
    {
        $a = $this->trx('booking', 'cash_bertahap');
        $this->jadwal($a, 'pelunasan', 80000000, now()->subDays(3)->toDateString());           // lewat -> bisa dikejar
        $this->jadwal($a, 'pelunasan', 80000000, now()->addDays(27)->toDateString());          // ≤ 30 hari -> bisa dikejar
        $this->jadwal($a, 'dp', 5000000, now()->addDays(5)->toDateString());                   // ≤ 30 hari -> bisa dikejar
        $this->jadwal($a, 'pelunasan', 10000000, now()->addDays(45)->toDateString());          // masih jauh -> potensi saja
        $jauh = $this->trx('pemberkasan', 'cash');
        $this->jadwal($jauh, 'pelunasan', 160000000, now()->addDays(45)->toDateString());      // jauh -> tidak ikut

        // Tanpa tanggal sendiri: mengikuti rencana akad (20 hari lagi) -> ikut, begitu juga Titipan yang tidak punya jadwal.
        $akadDekat = $this->trx('pemberkasan', 'cash', ['tanggal_rencana_akad' => now()->addDays(20)->toDateString(), 'titipan_biaya_akad_nominal' => 3000000]);
        $this->jadwal($akadDekat, 'pelunasan', 100000000, null);
        // Rencana akad masih 90 hari lagi, atau belum ada: menunggu.
        $akadJauh = $this->trx('pemberkasan', 'cash', ['tanggal_rencana_akad' => now()->addDays(90)->toDateString(), 'titipan_biaya_akad_nominal' => 3000000]);
        $this->jadwal($akadJauh, 'pelunasan', 100000000, null);
        $tanpaRencana = $this->trx('pemberkasan', 'cash');
        $this->jadwal($tanpaRencana, 'pelunasan', 100000000, null);

        $f = $this->dash();

        $this->assertEquals(165000000 + 103000000, $f['bisa_dikejar']['konsumen']['nominal']);
        $this->assertSame(2, $f['bisa_dikejar']['konsumen']['jumlah']);
        $this->assertGreaterThan(268000000, $f['sisa_piutang_konsumen']);
    }

    public function test_cash_tagihan_tanpa_jadwal_ikut_bila_sudah_rencana_akad_atau_akad(): void
    {
        $this->trx('akad', 'cash', ['titipan_biaya_akad_nominal' => 2000000]);                // Titipan 2 jt belum dibayar, sudah tahap akad
        $this->trx('pemberkasan', 'cash', ['titipan_biaya_akad_nominal' => 2000000]);         // belum tahap itu, tanpa rencana akad: tidak ikut

        $this->assertEquals(2000000, $this->dash()['bisa_dikejar']['konsumen']['nominal']);
    }

    public function test_kosong_bila_tidak_ada_transaksi_yang_memenuhi(): void
    {
        $this->trx('booking');

        $f = $this->dash();

        $this->assertEquals(0, $f['bisa_dikejar']['bank']['nominal']);
        $this->assertSame(0, $f['bisa_dikejar']['bank']['jumlah']);
        $this->assertEquals(0, $f['bisa_dikejar']['konsumen']['nominal']);
        $this->assertSame(0, $f['bisa_dikejar']['konsumen']['jumlah']);
    }
}
