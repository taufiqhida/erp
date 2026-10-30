<?php

namespace Tests\Feature;

use App\Models\DajamSbumPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PencairanKprTahap;
use App\Models\Project;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Penanda + filter tindak lanjut bank (SBUM belum diinput, Dajam belum dirinci), kartu Dashboard, dan perintah audit/perbaikan. */
class PencairanKprTindakLanjutTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $admin;
    private SkemaDpPreset $skema;
    private TipeUnitPreset $tipe;
    private DajamSbumPreset $dajamPreset;
    private DajamSbumPreset $sbumPreset;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->skema = SkemaDpPreset::create(['nama' => 'KPR', 'cara_bayar' => 'kpr_subsidi',
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => false,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 1660000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto']);
        $this->dajamPreset = DajamSbumPreset::create(['nama' => 'Sertifikat', 'kategori' => 'dajam', 'is_active' => true]);
        $this->sbumPreset = DajamSbumPreset::create(['nama' => 'SBUM Subsidi', 'kategori' => 'sbum', 'is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
    }

    /** Transaksi KPR Subsidi tahap akad dengan plafon 160.340.000 (SBUM 4 jt, DP 1,66 jt). */
    private function trx(array $o = [], bool $denganSbum = true): KavlingKonsumen
    {
        $this->n++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->n,
            'status_jual' => 'sold', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $trx = KavlingKonsumen::create($o + ['kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => 'K ' . $this->n])->id, 'status' => 'active', 'status_penjualan' => 'akad',
            'cara_bayar' => 'kpr_subsidi', 'tanggal_booking' => '2026-09-01', 'harga_dasar' => 166000000, 'harga_deal' => 166000000, 'booking_fee' => 1000000, 'skema_dp_preset_id' => $this->skema->id]);
        $trx->jadwalTagihans()->create(['jenis' => 'booking_fee', 'nomor_cicilan' => 1, 'jumlah' => 1000000, 'tanggal_jatuh_tempo' => '2026-09-01', 'status' => 'lunas']);
        $trx->jadwalTagihans()->create(['jenis' => 'dp', 'nomor_cicilan' => 1, 'jumlah' => 1660000, 'tanggal_jatuh_tempo' => '2026-09-01', 'status' => 'lunas']);
        if ($denganSbum) $trx->rincianBiayaAkad()->create(['dajam_sbum_preset_id' => $this->sbumPreset->id, 'nama' => 'SBUM Subsidi', 'kategori' => 'sbum', 'nominal' => 4000000, 'status' => 'belum_bayar']);

        return $trx;
    }

    private function tahap(KavlingKonsumen $trx, float $nominal, string $tgl = '2026-10-05'): PencairanKprTahap
    {
        return PencairanKprTahap::create(['kavling_konsumen_id' => $trx->id, 'nominal' => $nominal, 'tanggal_cair' => $tgl]);
    }

    private function dajam(KavlingKonsumen $trx, float $nominal, float $cair = 0): void
    {
        $item = $trx->rincianBiayaAkad()->create(['dajam_sbum_preset_id' => $this->dajamPreset->id, 'nama' => 'Sertifikat', 'kategori' => 'dajam', 'nominal' => $nominal, 'status' => 'belum_bayar']);
        if ($cair > 0) {
            $bayar = $trx->pembayarans()->create(['jenis' => 'dajam', 'jumlah' => $cair, 'tanggal_bayar' => '2026-10-10', 'keterangan' => 'Sertifikat']);
            $item->update(['status' => $cair >= $nominal ? 'lunas' : 'sebagian', 'pembayaran_konsumen_id' => $bayar->id]);
        }
    }

    private function props(string $route, array $query = []): array
    {
        $html = $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])->get(route($route, $query))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    public function test_daftar_pencairan_memberi_penanda_dan_filter_sbum_dan_dajam_belum_dirinci(): void
    {
        $tanpaSbum = $this->trx([], denganSbum: false);                                   // perlu SBUM
        $adaSisa = $this->trx();                                                          // retensi 5 jt, belum dirinci
        $this->tahap($adaSisa, 155340000);
        $bersih = $this->trx();
        $this->tahap($bersih, 155340000);
        $this->dajam($bersih, 5000000);                                                   // retensi terurai habis

        $semua = collect($this->props('keuangan.pencairan', ['tampil' => 'semua'])['rows']['data'])->keyBy('id');
        $this->assertTrue($semua[$tanpaSbum->id]['perlu_sbum']);
        $this->assertFalse($semua[$adaSisa->id]['perlu_sbum']);
        $this->assertEquals(5000000, $semua[$adaSisa->id]['dajam_belum_dirinci']);
        $this->assertEquals(0, $semua[$bersih->id]['dajam_belum_dirinci']);
        $this->assertEquals(96.9, $semua[$bersih->id]['persen_pencairan']);

        $sbum = $this->props('keuangan.pencairan', ['tindak_lanjut' => 'perlu_sbum']);
        $this->assertSame([$tanpaSbum->id], collect($sbum['rows']['data'])->pluck('id')->all());
        $this->assertSame('perlu_sbum', $sbum['filters']['tindak_lanjut']);

        $dajam = $this->props('keuangan.pencairan', ['tindak_lanjut' => 'dajam_belum_dirinci']);
        $this->assertSame([$adaSisa->id], collect($dajam['rows']['data'])->pluck('id')->all());
    }

    public function test_kolom_plafon_daftar_pencairan_memakai_hitungan_aplikasi_bila_tanpa_import(): void
    {
        $dariSistem = $this->trx();                                                       // plafon_kpr kosong -> hitungan: 166 jt - DP 1,66 jt - SBUM 4 jt
        $dariImport = $this->trx(['plafon_kpr' => 150000000]);                            // angka bank menang

        $semua = collect($this->props('keuangan.pencairan', ['tampil' => 'semua'])['rows']['data'])->keyBy('id');
        $this->assertEquals(160340000, $semua[$dariSistem->id]['plafon_kpr']);
        $this->assertEquals(150000000, $semua[$dariImport->id]['plafon_kpr']);
    }

    public function test_dashboard_menghitung_kartu_peringatan_bank(): void
    {
        $this->trx([], denganSbum: false);
        $a = $this->trx();
        $this->tahap($a, 155340000);
        $b = $this->trx();
        $this->tahap($b, 156340000);                                                      // retensi 4 jt
        $this->dajam($b, 1000000);                                                        // sisa 3 jt

        $t = $this->props('dashboard')['tindakLanjutBank'];

        $this->assertSame(1, $t['perlu_sbum']);
        $this->assertSame(2, $t['dajam_belum_dirinci']);
        $this->assertEquals(8000000, $t['dajam_belum_dirinci_rp']);                       // 5 jt + 3 jt
    }

    public function test_dashboard_menampilkan_selisih_bank_terpisah_dan_tidak_menghitungnya_sebagai_piutang(): void
    {
        $a = $this->trx();
        $this->tahap($a, 155340000);
        $this->dajam($a, 4995000, 4995000);
        $before = $this->props('dashboard')['financials'];
        $this->assertEquals(0, $before['penyesuaian_bank']);

        $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])
            ->post(route('dajam.tutup-selisih', $a), ['catatan' => 'Pembulatan bank'])->assertSessionHas('success');

        $after = $this->props('dashboard')['financials'];
        $this->assertEquals(5000, $after['penyesuaian_bank']);
        $this->assertEquals($before['piutang_bank'] - 5000, $after['piutang_bank']);
        $this->assertEquals(4000000, $after['sisa_piutang_bank']);                        // tinggal SBUM
    }

    public function test_transaksi_selesai_atau_belum_akad_tidak_memicu_peringatan(): void
    {
        $belumAkad = $this->trx(['status_penjualan' => 'pemberkasan'], denganSbum: false);
        $this->tahap($belumAkad, 100000000);
        $selesai = $this->trx(['status' => 'completed'], denganSbum: false);

        $t = $this->props('dashboard')['tindakLanjutBank'];

        $this->assertSame(0, $t['perlu_sbum']);
        $this->assertSame(0, $t['dajam_belum_dirinci']);
    }

    public function test_audit_mengelompokkan_transaksi(): void
    {
        $bersih = $this->trx(); $this->tahap($bersih, 155340000); $this->dajam($bersih, 5000000);
        $this->trx();                                                                      // belum ada pencairan
        $ganda = $this->trx(); $this->tahap($ganda, 160340000); $this->dajam($ganda, 5000000, 5000000);
        $sisa = $this->trx(); $this->tahap($sisa, 155340000);
        $this->trx(['status' => 'cancelled', 'status_penjualan' => 'batal']);                // transaksi batal tidak ikut diaudit

        $this->artisan('dajam:audit')
            ->expectsOutputToContain('A. Bersih')
            ->expectsOutputToContain('Diperiksa 4 transaksi KPR')
            ->assertSuccessful();
    }

    public function test_perbaikan_dajam_ganda_pratinjau_lalu_terapkan(): void
    {
        $ganda = $this->trx();
        $t1 = $this->tahap($ganda, 100000000, '2026-10-01');
        $t2 = $this->tahap($ganda, 60340000, '2026-10-05');                                // total 160.340.000 = ikut Dajam
        $this->dajam($ganda, 5000000, 5000000);
        $this->assertEquals(5000000, $ganda->ringkasPencairan()['dajam_melebihi']);

        $this->artisan('pencairan:perbaiki-dajam')->expectsOutputToContain('PRATINJAU: 1 transaksi')->assertSuccessful();
        $this->assertEquals(60340000, $t2->fresh()->nominal);                               // pratinjau tidak mengubah apa pun

        $this->artisan('pencairan:perbaiki-dajam', ['--terapkan' => true])->expectsOutputToContain('MENERAPKAN: 1 transaksi')->assertSuccessful();

        $this->assertEquals(55340000, $t2->fresh()->nominal);                               // tahap terakhir dikurangi sebesar Dajam
        $this->assertEquals(100000000, $t1->fresh()->nominal);
        $pk = $ganda->fresh()->ringkasPencairan();
        $this->assertEquals(0, $pk['dajam_melebihi']);
        $this->assertEquals(5000000, $pk['retensi']);
        $this->assertEquals(0, $pk['belum_dirinci']);
        $this->artisan('pencairan:perbaiki-dajam', ['--terapkan' => true])->expectsOutputToContain('0 transaksi akan diperbaiki')->assertSuccessful();   // aman diulang
    }

    public function test_perbaikan_melewati_pola_yang_tidak_jelas(): void
    {
        $aneh = $this->trx();
        $this->tahap($aneh, 160340000);
        $this->dajam($aneh, 5000000);                                                       // kelebihan 5 jt tetapi Dajam belum cair sama sekali

        $this->artisan('pencairan:perbaiki-dajam', ['--terapkan' => true])->expectsOutputToContain('0 transaksi akan diperbaiki, 1 dilewati')->assertSuccessful();
        $this->assertEquals(160340000, $aneh->pencairanKprTahaps()->first()->nominal);
    }
}
