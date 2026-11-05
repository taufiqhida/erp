<?php

namespace Tests\Feature;

use App\Models\BankRekananPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tab Rekap Kekurangan Pencairan: satu baris per konsumen KPR sudah akad, kolom per komponen dana bank. Mode (belum cair / sudah cair / semua)
 * dan kolom terpilih menentukan baris, isi sel, dan Total.
 */
class RekapKekuranganTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SkemaDpPreset $skema;
    private BankRekananPreset $bri;
    private BankRekananPreset $btn;
    private User $admin;
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
        $this->bri = BankRekananPreset::create(['nama' => 'BRI', 'is_active' => true]);
        $this->btn = BankRekananPreset::create(['nama' => 'BTN', 'is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
    }

    private function trx(string $nama, BankRekananPreset $bank, array $o = []): KavlingKonsumen
    {
        $this->n++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->n,
            'status_jual' => 'sold', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        return KavlingKonsumen::create($o + ['kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => $nama])->id, 'status' => 'active', 'status_penjualan' => 'akad',
            'cara_bayar' => 'kpr_subsidi', 'tanggal_booking' => '2026-09-01', 'tanggal_akad' => '2026-10-01', 'harga_dasar' => 166000000, 'harga_deal' => 166000000,
            'booking_fee' => 1000000, 'skema_dp_preset_id' => $this->skema->id, 'bank_rekanan_preset_id' => $bank->id]);
    }

    private function item(KavlingKonsumen $trx, string $nama, float $nominal, float $cair = 0, string $kategori = 'dajam'): void
    {
        $item = $trx->rincianBiayaAkad()->create(['dajam_sbum_preset_id' => null, 'nama' => $nama, 'kategori' => $kategori, 'nominal' => $nominal, 'status' => 'belum_bayar']);
        if ($cair > 0) {
            $bayar = $trx->pembayarans()->create(['jenis' => 'dajam', 'jumlah' => $cair, 'tanggal_bayar' => '2026-10-10', 'keterangan' => $nama]);
            $item->update(['status' => $cair >= $nominal ? 'lunas' : 'sebagian', 'pembayaran_konsumen_id' => $bayar->id]);
        }
    }

    private function props(array $query = []): array
    {
        $html = $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])->get(route('keuangan.rekap-kekurangan', $query))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    /** A (BRI): Sertifikat lunas 8 jt, Air sebagian (sisa 1 jt), SBUM 4 jt belum. B (BTN): Sertifikat 8 jt belum. C (BRI): Sertifikat lunas. D (BTN): tanpa item Dajam. */
    private function skenario(): array
    {
        $a = $this->trx('Andi', $this->bri);
        $this->item($a, 'Sertifikat', 8000000, 8000000);
        $this->item($a, 'Air', 2000000, 1000000);
        $this->item($a, 'SBUM Subsidi', 4000000, 0, 'sbum');
        $b = $this->trx('Budi', $this->btn);
        $this->item($b, 'Sertifikat', 8000000);
        $c = $this->trx('Cici', $this->bri);
        $this->item($c, 'Sertifikat', 8000000, 8000000);
        $d = $this->trx('Dodi', $this->btn);

        return [$a, $b, $c, $d];
    }

    private function namaBaris(array $props): array
    {
        return collect($props['rows']['data'])->pluck('nama')->all();
    }

    public function test_mode_belum_cair_hanya_konsumen_dengan_tagihan_di_kolom_terpilih(): void
    {
        [$a, $b] = $this->skenario();

        $p = $this->props(['mode' => 'belum_cair', 'kolom' => ['item:sertifikat']]);
        $this->assertSame(['Budi'], $this->namaBaris($p));
        $this->assertEquals(8000000, $p['summary']['total']);

        $p = $this->props(['mode' => 'belum_cair', 'kolom' => ['item:sertifikat', 'item:air']]);
        $baris = collect($p['rows']['data'])->keyBy('nama');
        $this->assertEqualsCanonicalizing(['Andi', 'Budi'], array_keys($baris->all()));
        $this->assertNull($baris['Andi']['sel']['item:sertifikat']);                  // sudah cair: dikosongkan
        $this->assertEquals(1000000, $baris['Andi']['sel']['item:air']['sisa']);       // sebagian: sisa
        $this->assertEquals(1000000, $baris['Andi']['sel']['item:air']['cair']);
        $this->assertEquals(1000000, $baris['Andi']['total']);
        $this->assertNull($baris['Budi']['sel']['item:air']);                          // tidak punya item itu
        $this->assertEquals(9000000, $p['summary']['total']);
        $this->assertEquals(8000000, $p['summary']['total_kolom']['item:sertifikat']);
        $this->assertEquals(1000000, $p['summary']['total_kolom']['item:air']);
    }

    public function test_mode_sudah_cair_hanya_yang_lunas_dan_sebagian_tidak_masuk(): void
    {
        $this->skenario();

        $p = $this->props(['mode' => 'sudah_cair', 'kolom' => ['item:sertifikat']]);
        $this->assertEqualsCanonicalizing(['Andi', 'Cici'], $this->namaBaris($p));
        $this->assertEquals(16000000, $p['summary']['total']);
        $this->assertSame('lunas', $p['rows']['data'][0]['sel']['item:sertifikat']['status']);

        $this->assertSame([], $this->namaBaris($this->props(['mode' => 'sudah_cair', 'kolom' => ['item:air']])));   // Air baru sebagian
    }

    public function test_mode_semua_menampilkan_semua_konsumen_dan_total_adalah_sisa_belum_cair(): void
    {
        $this->skenario();

        $p = $this->props(['mode' => 'semua', 'kolom' => ['item:sertifikat', 'item:air']]);
        $baris = collect($p['rows']['data'])->keyBy('nama');
        $this->assertCount(4, $baris);                                                  // Dodi tanpa item pun tampil
        $this->assertSame('lunas', $baris['Andi']['sel']['item:sertifikat']['status']);
        $this->assertSame('sebagian', $baris['Andi']['sel']['item:air']['status']);
        $this->assertEquals(1000000, $baris['Andi']['total']);                          // lunas tidak menambah sisa
        $this->assertEquals(0, $baris['Cici']['total']);
        $this->assertEquals(8000000, $baris['Budi']['total']);
        $this->assertEquals(9000000, $p['summary']['total']);
    }

    public function test_beberapa_kolom_dicentang_bersifat_atau(): void
    {
        $this->skenario();

        $p = $this->props(['mode' => 'belum_cair', 'kolom' => ['item:sertifikat', 'item:air']]);
        $this->assertEqualsCanonicalizing(['Andi', 'Budi'], $this->namaBaris($p));      // Andi lewat Air, Budi lewat Sertifikat
    }

    public function test_tanpa_parameter_kolom_semua_kolom_dan_penanda_strip_artinya_tidak_ada(): void
    {
        $this->skenario();

        $p = $this->props();
        $keys = collect($p['kolom'])->pluck('key')->all();
        $this->assertEqualsCanonicalizing($keys, $p['terpilih']);
        $this->assertContains('item:sertifikat', $keys);
        $this->assertContains('sbum', $keys);
        $this->assertContains('pencairan', $keys);

        $kosong = $this->props(['kolom' => ['-']]);
        $this->assertSame([], $kosong['terpilih']);
        $this->assertSame([], $this->namaBaris($kosong));
    }

    public function test_sbum_dan_pencairan_netto_ikut_sebagai_kolom_pilihan(): void
    {
        [$a] = $this->skenario();
        $this->actingAs($this->admin);

        $p = $this->props(['mode' => 'belum_cair', 'kolom' => ['sbum']]);
        $this->assertSame(['Andi'], $this->namaBaris($p));
        $this->assertEquals(4000000, $p['rows']['data'][0]['sel']['sbum']['sisa']);

        $p = $this->props(['mode' => 'semua', 'kolom' => ['pencairan']]);
        $cell = collect($p['rows']['data'])->firstWhere('nama', 'Andi')['sel']['pencairan'];
        $this->assertSame('belum', $cell['status']);                                    // belum ada tahap pencairan tercatat
        $this->assertGreaterThan(0, $cell['nominal']);
    }

    public function test_item_selisih_penyesuaian_bank_tidak_muncul_sebagai_kolom(): void
    {
        [$a] = $this->skenario();
        $this->item($a, 'Selisih / penyesuaian bank', 1000, 0, 'penyesuaian');

        $keys = collect($this->props()['kolom'])->pluck('key')->all();
        $this->assertNotContains('item:selisih / penyesuaian bank', $keys);
    }

    public function test_filter_bank_cara_bayar_tahap_dan_rentang_akad(): void
    {
        [$a, $b, $c, $d] = $this->skenario();
        $d->update(['status_penjualan' => 'bast', 'tanggal_akad' => '2026-08-01']);
        $this->item($d, 'Sertifikat', 8000000);

        $semua = ['mode' => 'semua', 'kolom' => ['item:sertifikat']];
        $this->assertEqualsCanonicalizing(['Andi', 'Cici'], $this->namaBaris($this->props($semua + ['bank' => $this->bri->id])));
        $this->assertSame(['Dodi'], $this->namaBaris($this->props($semua + ['tahap' => 'bast'])));
        $this->assertEqualsCanonicalizing(['Andi', 'Budi', 'Cici'], $this->namaBaris($this->props($semua + ['tahap' => 'akad'])));
        $this->assertSame(['Dodi'], $this->namaBaris($this->props($semua + ['akad_sampai' => '2026-09-30'])));
        $this->assertSame([], $this->namaBaris($this->props($semua + ['cara_bayar' => 'kpr_komersil'])));
        $this->assertSame(['Dodi'], $this->namaBaris($this->props($semua + ['umur' => 90])));     // akad > 90 hari lalu hanya Dodi
    }

    public function test_kasus_bank_bri_sertifikat_masih_ditahan(): void
    {
        $a = $this->trx('Andi', $this->bri);
        $this->item($a, 'Sertifikat', 8000000);
        $c = $this->trx('Cici', $this->bri);
        $this->item($c, 'Sertifikat', 8000000, 8000000);
        $b = $this->trx('Budi', $this->btn);
        $this->item($b, 'Sertifikat', 8000000);

        $p = $this->props(['mode' => 'belum_cair', 'kolom' => ['item:sertifikat'], 'bank' => $this->bri->id]);
        $this->assertSame(['Andi'], $this->namaBaris($p));
        $this->assertSame('BRI', $p['rows']['data'][0]['bank']);
    }

    public function test_sort_berdasarkan_total_nama_dan_kolom_dengan_item_kosong_selalu_di_bawah(): void
    {
        $this->skenario();
        $q = ['mode' => 'semua', 'kolom' => ['item:sertifikat', 'item:air']];

        $this->assertSame('Budi', $this->namaBaris($this->props($q + ['sort' => 'total', 'dir' => 'desc']))[0]);
        $this->assertSame(['Andi', 'Budi', 'Cici', 'Dodi'], $this->namaBaris($this->props($q + ['sort' => 'nama', 'dir' => 'asc'])));
        $this->assertSame(['Dodi', 'Cici', 'Budi', 'Andi'], $this->namaBaris($this->props($q + ['sort' => 'nama', 'dir' => 'desc'])));

        // Air hanya dimiliki Andi: Andi pertama (desc) & tetap bukan di bawah (asc), konsumen tanpa Air selalu di bawah.
        $this->assertSame('Andi', $this->namaBaris($this->props($q + ['sort' => 'item:air', 'dir' => 'desc']))[0]);
        $this->assertSame('Andi', $this->namaBaris($this->props($q + ['sort' => 'item:air', 'dir' => 'asc']))[0]);

        // kunci sort tidak dikenal jatuh ke default (total desc), tidak error
        $this->assertSame('Budi', $this->namaBaris($this->props($q + ['sort' => 'asal; drop table']))[0]);
    }

    public function test_paginasi_total_dan_footer_mencakup_seluruh_hasil_bukan_hanya_halaman(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $t = $this->trx("Konsumen $i", $this->bri);
            $this->item($t, 'Sertifikat', 1000000);
        }

        $p = $this->props(['mode' => 'belum_cair', 'kolom' => ['item:sertifikat'], 'per_page' => 20]);
        $this->assertSame(25, $p['rows']['total']);
        $this->assertCount(20, $p['rows']['data']);
        $this->assertEquals(25000000, $p['summary']['total']);
        $this->assertEquals(25, $p['summary']['jumlah']);

        $hal2 = $this->props(['mode' => 'belum_cair', 'kolom' => ['item:sertifikat'], 'per_page' => 20, 'page' => 2]);
        $this->assertCount(5, $hal2['rows']['data']);
    }

    public function test_hasil_ikut_berubah_setelah_pembayaran_dajam_dicatat(): void
    {
        $b = $this->trx('Budi', $this->btn);
        $this->item($b, 'Sertifikat', 8000000);
        $this->assertSame(['Budi'], $this->namaBaris($this->props(['mode' => 'belum_cair', 'kolom' => ['item:sertifikat']])));

        $item = $b->rincianBiayaAkad()->firstOrFail();
        $this->actingAs($this->admin)->post(route('rincian-biaya-akad.bayar', $item), ['jumlah' => 8000000, 'tanggal_bayar' => '2026-10-20'])->assertSessionHasNoErrors();

        $this->assertSame([], $this->namaBaris($this->props(['mode' => 'belum_cair', 'kolom' => ['item:sertifikat']])));
        $this->assertSame(['Budi'], $this->namaBaris($this->props(['mode' => 'sudah_cair', 'kolom' => ['item:sertifikat']])));
    }

    public function test_transaksi_belum_akad_atau_batal_tidak_ikut(): void
    {
        $x = $this->trx('Belum Akad', $this->bri, ['status_penjualan' => 'sp3k', 'tanggal_akad' => null]);
        $this->item($x, 'Sertifikat', 8000000);
        $y = $this->trx('Batal', $this->bri, ['status' => 'cancelled', 'status_penjualan' => 'batal']);
        $this->item($y, 'Sertifikat', 8000000);

        $this->assertSame([], $this->namaBaris($this->props(['mode' => 'semua'])));
    }

    public function test_tanpa_izin_view_keuangan_ditolak_dan_export_mengikuti_filter(): void
    {
        $this->skenario();

        $tamu = User::factory()->create();
        $this->actingAs($tamu)->get(route('keuangan.rekap-kekurangan'))->assertForbidden();
        $this->actingAs($tamu)->get(route('keuangan.rekap-kekurangan.export'))->assertForbidden();

        $r = $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])
            ->get(route('keuangan.rekap-kekurangan.export', ['mode' => 'belum_cair', 'kolom' => ['item:sertifikat'], 'bank' => $this->btn->id]));
        $r->assertOk();
        $this->assertStringContainsString('rekap-kekurangan-pencairan.xlsx', $r->headers->get('content-disposition'));
    }
}
