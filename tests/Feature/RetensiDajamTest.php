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

/**
 * Retensi Dajam: bagian plafon yang ditahan bank = plafon disetujui - total pencairan (otomatis, sejak ada pencairan tercatat).
 * Retensi TIDAK berkurang saat item dirinci/dicairkan; yang berkurang adalah "belum dirinci" dan "belum cair".
 */
class RetensiDajamTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private KavlingKonsumen $trx;
    private DajamSbumPreset $sertifikat;
    private DajamSbumPreset $air;
    private DajamSbumPreset $sbum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'sold', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        // Booking Fee TIDAK masuk harga jual (umumnya), DP masuk: plafon = 166.000.000 - 1.660.000 - SBUM 4.000.000 = 160.340.000.
        $skema = SkemaDpPreset::create(['nama' => 'KPR', 'cara_bayar' => 'kpr_subsidi',
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => false,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 1660000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto']);
        $this->trx = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => 'Budi'])->id, 'status' => 'active', 'status_penjualan' => 'akad',
            'cara_bayar' => 'kpr_subsidi', 'tanggal_booking' => '2026-09-01', 'harga_dasar' => 166000000, 'harga_deal' => 166000000, 'booking_fee' => 1000000, 'skema_dp_preset_id' => $skema->id]);
        $this->trx->jadwalTagihans()->create(['jenis' => 'booking_fee', 'nomor_cicilan' => 1, 'jumlah' => 1000000, 'tanggal_jatuh_tempo' => '2026-09-01', 'status' => 'lunas']);
        $this->trx->jadwalTagihans()->create(['jenis' => 'dp', 'nomor_cicilan' => 1, 'jumlah' => 1660000, 'tanggal_jatuh_tempo' => '2026-09-01', 'status' => 'lunas']);
        $this->sertifikat = DajamSbumPreset::create(['nama' => 'Sertifikat', 'kategori' => 'dajam', 'is_active' => true]);
        $this->air = DajamSbumPreset::create(['nama' => 'Air', 'kategori' => 'dajam', 'is_active' => true]);
        $this->sbum = DajamSbumPreset::create(['nama' => 'SBUM Subsidi', 'kategori' => 'sbum', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function sbum(float $nominal = 4000000): void
    {
        $this->trx->rincianBiayaAkad()->create(['dajam_sbum_preset_id' => $this->sbum->id, 'nama' => 'SBUM Subsidi', 'kategori' => 'sbum', 'nominal' => $nominal, 'status' => 'belum_bayar']);
    }

    private function dajam(DajamSbumPreset $p, float $nominal, float $cair = 0): void
    {
        $item = $this->trx->rincianBiayaAkad()->create(['dajam_sbum_preset_id' => $p->id, 'nama' => $p->nama, 'kategori' => 'dajam', 'nominal' => $nominal, 'status' => 'belum_bayar']);
        if ($cair > 0) {
            $bayar = $this->trx->pembayarans()->create(['jenis' => 'dajam', 'jumlah' => $cair, 'tanggal_bayar' => '2026-10-10', 'keterangan' => $p->nama]);
            $item->update(['status' => $cair >= $nominal ? 'lunas' : 'sebagian', 'pembayaran_konsumen_id' => $bayar->id]);
        }
    }

    private function tahap(float $nominal): void
    {
        PencairanKprTahap::create(['kavling_konsumen_id' => $this->trx->id, 'nominal' => $nominal, 'tanggal_cair' => '2026-10-05']);
    }

    private function pk(): array
    {
        return $this->trx->fresh()->ringkasPencairan();
    }

    public function test_plafon_mengikuti_aturan_skema_booking_fee_tidak_masuk_harga_jual(): void
    {
        $this->sbum();

        $pk = $this->pk();

        $this->assertEquals(160340000, $pk['plafon_hitung']);     // 166.000.000 - DP 1.660.000 - SBUM 4.000.000 (Booking Fee tidak dikurangkan)
        $this->assertFalse($pk['sbum_kosong']);
    }

    public function test_retensi_otomatis_dari_plafon_dikurangi_pencairan_dan_ikut_berubah_tiap_tahap(): void
    {
        $this->sbum();
        $this->dajam($this->sertifikat, 3000000);

        $this->tahap(100000000);
        $a = $this->pk();
        $this->assertTrue($a['retensi_aktif']);
        $this->assertEquals(60340000, $a['retensi']);                  // sisa plafon sementara
        $this->assertEquals(57340000, $a['belum_dirinci']);

        $this->tahap(50000000);
        $b = $this->pk();
        $this->assertEquals(10340000, $b['retensi']);                  // rekalkulasi: 160.340.000 - 150.000.000
        $this->assertEquals(7340000, $b['belum_dirinci']);
        $this->assertEquals(93.6, $b['persen_pencairan']);
    }

    public function test_retensi_tidak_berkurang_saat_item_dirinci_dan_dicairkan(): void
    {
        $this->sbum();
        $this->tahap(155340000);                                        // retensi = 5.000.000

        $this->assertEquals(5000000, $this->pk()['retensi']);
        $this->assertEquals(5000000, $this->pk()['belum_dirinci']);

        $this->dajam($this->sertifikat, 3000000, 2000000);              // dirinci 3 jt, cair 2 jt
        $this->dajam($this->air, 1000000, 1000000);                     // dirinci 1 jt, cair 1 jt

        $pk = $this->pk();
        $this->assertEquals(5000000, $pk['retensi']);                   // tetap
        $this->assertEquals(4000000, $pk['dajam_dirinci']);
        $this->assertEquals(3000000, $pk['dajam_cair']);
        $this->assertEquals(1000000, $pk['belum_dirinci']);             // berkurang saat item dirinci
        $this->assertEquals(2000000, $pk['belum_cair']);                // berkurang saat item dicairkan
        $this->assertTrue($pk['perlu_rincian_dajam']);                  // sudah akad + masih ada yang belum dirinci
        $this->assertSame('sebagian', $pk['pencairan_status']);
    }

    public function test_pencairan_lunas_bila_retensi_sudah_terurai_habis_ke_item(): void
    {
        $this->sbum();
        $this->tahap(155340000);
        $this->dajam($this->sertifikat, 3000000);
        $this->dajam($this->air, 2000000);

        $pk = $this->pk();

        $this->assertEquals(0, $pk['belum_dirinci']);
        $this->assertFalse($pk['perlu_rincian_dajam']);
        $this->assertSame('lunas', $pk['pencairan_status']);
    }

    public function test_aturan_peralihan_tanpa_pencairan_retensi_sama_dengan_jumlah_item_seperti_dulu(): void
    {
        $this->sbum();
        $this->dajam($this->sertifikat, 5000000);

        $pk = $this->pk();

        $this->assertFalse($pk['retensi_aktif']);
        $this->assertEquals(5000000, $pk['retensi']);
        $this->assertEquals(0, $pk['belum_dirinci']);                   // tidak ada peringatan palsu untuk transaksi tanpa pencairan
        $this->assertFalse($pk['perlu_rincian_dajam']);
        $this->assertEquals(155340000, $pk['pencairan_nominal']);       // perilaku lama: plafon - Dajam
        $this->assertSame('belum_bayar', $pk['pencairan_status']);
    }

    public function test_total_piutang_bank_tetap_sbum_ditambah_plafon_dengan_atau_tanpa_rincian(): void
    {
        $this->sbum();
        $this->tahap(155340000);

        $tanpaRincian = $this->trx->fresh(['rincianBiayaAkad'])->ringkasPencairan();
        $b1 = $this->trx->kartuPiutangBreakdown();
        $this->dajam($this->sertifikat, 5000000);
        $this->trx->ringkasPencairan();
        $b2 = $this->trx->kartuPiutangBreakdown();

        $this->assertEquals(164340000, $b1['total_piutang_bank']);      // SBUM 4 jt + plafon 160,34 jt
        $this->assertEquals($b1['total_piutang_bank'], $b2['total_piutang_bank']);
    }

    public function test_dajam_terhitung_dua_kali_terlihat_sebagai_kelebihan_bukan_error(): void
    {
        $this->sbum();
        $this->tahap(160340000);                                        // Total Sudah Cair ikut Dajam (kesalahan import)
        $this->dajam($this->sertifikat, 5000000, 5000000);

        $pk = $this->pk();
        $b = $this->trx->kartuPiutangBreakdown();

        $this->assertEquals(5000000, $pk['dajam_melebihi']);
        $this->assertGreaterThan($b['total_piutang_bank'], $b['total_terbayar_bank']);   // kelebihan terlihat jelas
    }

    public function test_tambahan_um_tidak_disimpulkan_untuk_kpr_subsidi_tanpa_sbum(): void
    {
        $this->trx->update(['plafon_kpr' => 160340000, 'status_sp3k' => 'turun_plafon']);

        $tanpaSbum = $this->pk();
        $this->assertTrue($tanpaSbum['sbum_kosong']);
        $this->assertEquals(0, $tanpaSbum['tambahan_um']);               // hitungan belum final: bukan "turun plafon"

        $this->sbum(4000000);
        $this->assertEquals(0, $this->pk()['tambahan_um']);              // plafon hitung = plafon bank: memang tidak ada selisih

        $this->trx->update(['plafon_kpr' => 158000000]);
        $this->assertEquals(2340000, $this->pk()['tambahan_um']);        // turun plafon sungguhan
    }

    public function test_peringatan_sbum_hanya_bila_kpr_subsidi_sudah_akad_dan_belum_ada_sbum(): void
    {
        $this->assertTrue($this->pk()['perlu_sbum']);

        $this->trx->update(['status_penjualan' => 'pemberkasan']);
        $this->assertFalse($this->pk()['perlu_sbum']);                   // belum akad

        $this->trx->update(['status_penjualan' => 'akad']);
        $this->sbum();
        $this->assertFalse($this->pk()['perlu_sbum']);
    }

    public function test_item_dajam_ditolak_bila_melebihi_sisa_retensi_setelah_ada_pencairan(): void
    {
        $this->sbum();
        $this->tahap(155340000);                                        // retensi 5.000.000
        $sales = $this->user('admin_sales');

        $this->actingAs($sales)->post(route('konsumens.biaya-akad.store', $this->trx), ['dajam_sbum_preset_id' => $this->sertifikat->id, 'nominal' => 3000000])->assertSessionHasNoErrors();
        $r = $this->actingAs($sales)->post(route('konsumens.biaya-akad.store', $this->trx), ['dajam_sbum_preset_id' => $this->air->id, 'nominal' => 2500000]);

        $r->assertStatus(422);
        $this->assertSame(1, $this->trx->rincianBiayaAkad()->where('kategori', 'dajam')->count());

        $this->actingAs($sales)->post(route('konsumens.biaya-akad.store', $this->trx), ['dajam_sbum_preset_id' => $this->air->id, 'nominal' => 2000000])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $this->pk()['belum_dirinci']);
    }

    public function test_item_dajam_bebas_sebelum_ada_pencairan(): void
    {
        $this->sbum();

        $this->actingAs($this->user('admin_sales'))->post(route('konsumens.biaya-akad.store', $this->trx), ['dajam_sbum_preset_id' => $this->sertifikat->id, 'nominal' => 999000000])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->trx->rincianBiayaAkad()->count() - 1);
    }

    public function test_tutup_selisih_membuat_item_penyesuaian_dan_menutup_sisa(): void
    {
        $this->sbum();
        $this->tahap(155340000);
        $this->dajam($this->sertifikat, 4999000);                       // sisa Rp 1.000

        $this->actingAs($this->user('admin_sales'))->post(route('dajam.tutup-selisih', $this->trx), ['catatan' => 'Biaya administrasi bank'])->assertSessionHas('success');

        $item = $this->trx->rincianBiayaAkad()->where('nama', 'Selisih / penyesuaian bank')->firstOrFail();
        $this->assertEquals(1000, $item->nominal);
        $this->assertNull($item->dajam_sbum_preset_id);                  // tanpa kolom/preset baru
        $pk = $this->pk();
        $this->assertEquals(0, $pk['belum_dirinci']);
        $this->assertEquals(5000000, $pk['retensi']);
        $this->assertSame('lunas', $pk['pencairan_status']);
    }

    public function test_tutup_selisih_wajib_catatan_dan_ditolak_bila_tidak_ada_sisa(): void
    {
        $this->sbum();
        $this->tahap(155340000);
        $this->dajam($this->sertifikat, 5000000);
        $sales = $this->user('admin_sales');

        $this->actingAs($sales)->post(route('dajam.tutup-selisih', $this->trx), ['catatan' => 'abc'])->assertSessionHasErrors('catatan');
        $this->actingAs($sales)->post(route('dajam.tutup-selisih', $this->trx), ['catatan' => 'Tidak ada sisa'])->assertStatus(422);
        $this->assertSame(0, $this->trx->rincianBiayaAkad()->where('nama', 'Selisih / penyesuaian bank')->count());
    }
}
