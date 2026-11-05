<?php

namespace Tests\Feature;

use App\Models\BankRekananPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rekap Kekurangan, tampilan Piutang Konsumen KPR dan Piutang Konsumen Cash: kolom Booking Fee/DP/biaya tanah/biaya tambahan/Tambahan UM/
 * Titipan/Pelunasan, jatuh tempo di sel, tahap per tampilan, urutan awal, dan pemisahan dari tampilan Pencairan Bank.
 */
class RekapPiutangKonsumenTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SkemaDpPreset $skema;
    private BankRekananPreset $bank;
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
        $this->bank = BankRekananPreset::create(['nama' => 'BRI', 'is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
    }

    /** KPR: Booking Fee 1 jt lunas, DP 1,66 jt jatuh tempo $dpTempo (belum dibayar). */
    private function kpr(string $nama, string $tahap = 'sp3k', ?string $dpTempo = null, array $o = []): KavlingKonsumen
    {
        $trx = $this->buat($nama, 'kpr_subsidi', $tahap, $o + ['skema_dp_preset_id' => $this->skema->id, 'bank_rekanan_preset_id' => $this->bank->id]);
        $this->jadwal($trx, 'booking_fee', 1000000, '2026-09-01', true);
        $this->jadwal($trx, 'dp', 1660000, $dpTempo ?? now()->addDays(10)->toDateString(), false);

        return $trx;
    }

    /** Cash: Booking Fee lunas, DP belum, Pelunasan 2 cicilan. */
    private function cash(string $nama, string $tahap = 'booking', string $cara = 'cash_bertahap', array $o = []): KavlingKonsumen
    {
        $trx = $this->buat($nama, $cara, $tahap, $o);
        $this->jadwal($trx, 'booking_fee', 1000000, '2026-09-01', true);
        $this->jadwal($trx, 'dp', 5000000, now()->addDays(3)->toDateString(), false);
        $this->jadwal($trx, 'pelunasan', 80000000, now()->addDays(30)->toDateString(), false);
        $this->jadwal($trx, 'pelunasan', 80000000, now()->addDays(60)->toDateString(), false);

        return $trx;
    }

    private function buat(string $nama, string $cara, string $tahap, array $o): KavlingKonsumen
    {
        $this->n++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->n,
            'status_jual' => 'sold', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        return KavlingKonsumen::create($o + ['kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => $nama])->id, 'status' => 'active', 'status_penjualan' => $tahap,
            'cara_bayar' => $cara, 'tanggal_booking' => '2026-09-01', 'harga_dasar' => 166000000, 'harga_deal' => 166000000, 'booking_fee' => 1000000]);
    }

    private function jadwal(KavlingKonsumen $trx, string $jenis, float $jumlah, string $tempo, bool $lunas): void
    {
        $data = ['jenis' => $jenis, 'nomor_cicilan' => $trx->jadwalTagihans()->where('jenis', $jenis)->count() + 1, 'jumlah' => $jumlah, 'tanggal_jatuh_tempo' => $tempo, 'status' => $lunas ? 'lunas' : 'belum_bayar'];
        if ($lunas) {
            $data['pembayaran_konsumen_id'] = PembayaranKonsumen::create(['kavling_konsumen_id' => $trx->id, 'jenis' => $jenis, 'jumlah' => $jumlah, 'tanggal_bayar' => '2026-09-02'])->id;
        }
        $trx->jadwalTagihans()->create($data);
    }

    private function props(array $query = []): array
    {
        $html = $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])->get(route('keuangan.rekap-kekurangan', $query))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    private function nama(array $props): array
    {
        return collect($props['rows']['data'])->pluck('nama')->all();
    }

    private function baris(array $props, string $nama): array
    {
        return collect($props['rows']['data'])->firstWhere('nama', $nama);
    }

    public function test_dp_belum_dibayar_menampilkan_jatuh_tempo_dan_terlambat_dihitung_saat_ini(): void
    {
        $this->kpr('Telat', 'sp3k', now()->subDays(5)->toDateString());
        $this->kpr('Belum Tempo', 'sp3k', now()->addDays(10)->toDateString());

        $p = $this->props(['tampilan' => 'piutang_kpr', 'kolom' => ['k:dp', 'k:booking_fee']]);
        $telat = $this->baris($p, 'Telat')['sel']['k:dp'];
        $this->assertEquals(1660000, $telat['sisa']);
        $this->assertSame(5, $telat['terlambat_hari']);
        $this->assertNotEmpty($telat['jatuh_tempo_label']);

        $belum = $this->baris($p, 'Belum Tempo')['sel']['k:dp'];
        $this->assertNull($belum['terlambat_hari']);
        $this->assertNotEmpty($belum['jatuh_tempo_label']);

        $this->assertNull($this->baris($p, 'Telat')['sel']['k:booking_fee']);       // Booking Fee sudah lunas: dikosongkan pada status Belum masuk
    }

    public function test_status_sudah_masuk_dan_semua_pada_piutang_konsumen(): void
    {
        $this->kpr('Andi');

        $sudah = $this->props(['tampilan' => 'piutang_kpr', 'mode' => 'sudah_cair', 'kolom' => ['k:booking_fee', 'k:dp']]);
        $this->assertSame(['Andi'], $this->nama($sudah));
        $sel = $this->baris($sudah, 'Andi')['sel'];
        $this->assertSame('lunas', $sel['k:booking_fee']['status']);
        $this->assertNull($sel['k:dp']);
        $this->assertEquals(1000000, $sudah['summary']['total']);

        $semua = $this->props(['tampilan' => 'piutang_kpr', 'mode' => 'semua', 'kolom' => ['k:booking_fee', 'k:dp']]);
        $this->assertEquals(1660000, $semua['summary']['total']);                    // total = sisa belum masuk saja
        $this->assertNull($this->baris($semua, 'Andi')['sel']['k:booking_fee']['jatuh_tempo'] ?? null);
    }

    public function test_kolom_tanah_biaya_tambahan_tambahan_um_dan_titipan_memakai_nominal_neto(): void
    {
        $trx = $this->kpr('Lengkap', 'rencana_akad', null, [
            'biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_nominal' => 8400000, 'biaya_kelebihan_tanah_potongan' => 4200000,
            'titipan_biaya_akad_nominal' => 500000, 'status_sp3k' => 'turun_plafon', 'plafon_kpr' => 150000000,
        ]);
        $trx->rincianBiayaAkad()->create(['dajam_sbum_preset_id' => null, 'nama' => 'SBUM Subsidi', 'kategori' => 'sbum', 'nominal' => 4000000, 'status' => 'belum_bayar']);
        $trx->biayaTambahans()->create(['biaya_tambahan_preset_id' => null, 'nama' => 'Hook', 'nominal' => 5000000, 'potongan' => 1500000]);
        $trx->biayaTambahans()->create(['biaya_tambahan_preset_id' => null, 'nama' => 'Strategis', 'nominal' => 2000000, 'potongan' => 0]);

        $p = $this->props(['tampilan' => 'piutang_kpr', 'mode' => 'semua']);
        $sel = $this->baris($p, 'Lengkap')['sel'];
        $this->assertEquals(4200000, $sel['k:biaya_tanah']['nominal']);                // neto setelah potongan promo
        $this->assertEquals(3500000, $sel['k:bt:hook']['nominal']);
        $this->assertEquals(2000000, $sel['k:bt:strategis']['nominal']);
        $this->assertEquals(500000, $sel['k:titipan']['nominal']);
        $this->assertEquals(10340000, $sel['k:tum']['nominal']);                       // 160.340.000 - 150.000.000

        $keys = collect($p['kolom'])->pluck('key')->all();
        $this->assertSame(['k:booking_fee', 'k:dp', 'k:biaya_tanah', 'k:bt:hook', 'k:bt:strategis', 'k:tum', 'k:titipan'], $keys);
        $this->assertSame(['konsumen'], collect($p['kolom'])->pluck('grup')->unique()->values()->all());
    }

    public function test_biaya_tambahan_bernama_sama_dijumlah_dalam_satu_kolom(): void
    {
        $trx = $this->kpr('Ganda');
        $trx->biayaTambahans()->create(['biaya_tambahan_preset_id' => null, 'nama' => 'Hook', 'nominal' => 3000000, 'potongan' => 0]);
        $trx->biayaTambahans()->create(['biaya_tambahan_preset_id' => null, 'nama' => ' hook ', 'nominal' => 2000000, 'potongan' => 0]);

        $p = $this->props(['tampilan' => 'piutang_kpr', 'mode' => 'semua']);
        $this->assertEquals(5000000, $this->baris($p, 'Ganda')['sel']['k:bt:hook']['nominal']);
        $this->assertCount(1, array_filter($p['kolom'], fn ($k) => str_starts_with($k['key'], 'k:bt:')));
    }

    public function test_tahap_bawaan_per_tampilan_dan_pilihan_banyak(): void
    {
        foreach (['booking', 'pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad', 'akad', 'bast'] as $tahap) {
            $this->kpr("K-$tahap", $tahap);
        }

        $bawaan = $this->props(['tampilan' => 'piutang_kpr']);
        $this->assertEqualsCanonicalizing(['K-sp3k', 'K-rencana_akad', 'K-akad', 'K-bast'], $this->nama($bawaan));
        $this->assertSame(['sp3k', 'rencana_akad', 'akad', 'bast'], $bawaan['filters']['tahap']);

        $dipilih = $this->props(['tampilan' => 'piutang_kpr', 'tahap' => ['booking', 'proses_bank']]);
        $this->assertEqualsCanonicalizing(['K-booking', 'K-proses_bank'], $this->nama($dipilih));

        $this->assertSame([], $this->nama($this->props(['tampilan' => 'piutang_kpr', 'tahap' => ['-']])));
        $tidakDikenal = $this->props(['tampilan' => 'piutang_kpr', 'tahap' => ['asal', 'batal']]);
        $this->assertSame([], $this->nama($tidakDikenal));                               // nilai di luar pipeline dibuang
    }

    public function test_tampilan_cash_hanya_cash_dengan_kolom_booking_fee_dp_pelunasan_titipan(): void
    {
        $this->kpr('Kpr');
        $c = $this->cash('Cash Bertahap', 'booking', 'cash_bertahap', ['biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_nominal' => 8400000, 'titipan_biaya_akad_nominal' => 700000]);
        $this->cash('Cash', 'akad', 'cash');

        $p = $this->props(['tampilan' => 'piutang_cash', 'mode' => 'semua']);
        $this->assertEqualsCanonicalizing(['Cash Bertahap', 'Cash'], $this->nama($p));          // semua tahap, KPR tidak ikut
        $this->assertSame(['k:booking_fee', 'k:dp', 'k:pelunasan', 'k:titipan'], collect($p['kolom'])->pluck('key')->all());

        $sel = $this->baris($p, 'Cash Bertahap')['sel'];
        $this->assertEquals(160000000, $sel['k:pelunasan']['nominal']);
        $this->assertSame('belum', $sel['k:pelunasan']['status']);
        $this->assertNotEmpty($sel['k:pelunasan']['jatuh_tempo_label']);                       // cicilan terdekat
        $this->assertEquals(700000, $sel['k:titipan']['nominal']);
        $this->assertArrayNotHasKey('k:biaya_tanah', $sel);                                    // sudah termasuk dalam Pelunasan

        $this->assertSame(['cash'], collect($this->props(['tampilan' => 'piutang_cash', 'cara_bayar' => 'cash'])['rows']['data'])->pluck('cara_bayar_label')->map(fn ($l) => strtolower($l))->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['Cash Bertahap', 'Cash'], $this->nama($this->props(['tampilan' => 'piutang_cash', 'cara_bayar' => 'kpr_subsidi'])));   // cara bayar di luar tampilan diabaikan
    }

    public function test_tampilan_pencairan_bank_tidak_tercampur_data_konsumen_dan_hanya_akad_bast(): void
    {
        $a = $this->kpr('Sudah Akad', 'akad');
        $a->rincianBiayaAkad()->create(['dajam_sbum_preset_id' => null, 'nama' => 'Sertifikat', 'kategori' => 'dajam', 'nominal' => 8000000, 'status' => 'belum_bayar']);
        $b = $this->kpr('Baru SP3K', 'sp3k');
        $b->rincianBiayaAkad()->create(['dajam_sbum_preset_id' => null, 'nama' => 'Sertifikat', 'kategori' => 'dajam', 'nominal' => 8000000, 'status' => 'belum_bayar']);

        $p = $this->props(['tampilan' => 'pencairan_bank', 'mode' => 'semua']);
        $this->assertSame(['Sudah Akad'], $this->nama($p));
        $keys = collect($p['kolom'])->pluck('key')->all();
        $this->assertContains('item:sertifikat', $keys);
        $this->assertEmpty(array_filter($keys, fn ($k) => str_starts_with($k, 'k:')));

        $kpr = $this->props(['tampilan' => 'piutang_kpr']);
        $this->assertEmpty(array_filter(collect($kpr['kolom'])->pluck('key')->all(), fn ($k) => str_starts_with($k, 'item:')));
    }

    public function test_urutan_awal_piutang_kpr_akad_atau_rencana_akad_paling_awal_dan_tanpa_tanggal_di_bawah(): void
    {
        $this->kpr('Akad Lama', 'akad', null, ['tanggal_akad' => '2026-08-01']);
        $this->kpr('Rencana Dekat', 'rencana_akad', null, ['tanggal_rencana_akad' => '2026-12-05']);
        $this->kpr('Rencana Jauh', 'rencana_akad', null, ['tanggal_rencana_akad' => '2027-02-01']);
        $this->kpr('Tanpa Tanggal', 'sp3k');

        $p = $this->props(['tampilan' => 'piutang_kpr']);
        $this->assertSame(['Akad Lama', 'Rencana Dekat', 'Rencana Jauh', 'Tanpa Tanggal'], $this->nama($p));
        $this->assertSame('akad', $p['filters']['sort']);
        $this->assertSame('asc', $p['filters']['dir']);

        $balik = $this->props(['tampilan' => 'piutang_kpr', 'sort' => 'akad', 'dir' => 'desc']);
        $this->assertSame(['Rencana Jauh', 'Rencana Dekat', 'Akad Lama', 'Tanpa Tanggal'], $this->nama($balik));

        $this->assertSame('total', $this->props(['tampilan' => 'piutang_cash'])['filters']['sort']);
        $this->assertSame('total', $this->props(['tampilan' => 'pencairan_bank'])['filters']['sort']);
    }

    public function test_pembayaran_dp_mengubah_hasil_dan_total_mengikuti_satu_tampilan(): void
    {
        $trx = $this->kpr('Bayar');
        $q = ['tampilan' => 'piutang_kpr', 'kolom' => ['k:dp']];
        $this->assertSame(['Bayar'], $this->nama($this->props($q)));

        $jadwal = $trx->jadwalTagihans()->where('jenis', 'dp')->firstOrFail();
        $this->actingAs($this->admin)->post(route('jadwal-tagihan.bayar', $jadwal), ['jumlah' => 1660000, 'tanggal_bayar' => '2026-10-20'])->assertSessionHasNoErrors();

        $this->assertSame([], $this->nama($this->props($q)));
        $this->assertSame(['Bayar'], $this->nama($this->props($q + ['mode' => 'sudah_cair'])));
    }

    public function test_export_per_tampilan_dan_tanpa_izin_ditolak(): void
    {
        $this->kpr('K');
        $this->cash('C');

        foreach (['piutang_kpr', 'pencairan_bank', 'piutang_cash'] as $tampilan) {
            $r = $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])
                ->get(route('keuangan.rekap-kekurangan.export', ['tampilan' => $tampilan, 'mode' => 'semua']));
            $r->assertOk();
            $this->assertStringContainsString('rekap-kekurangan.xlsx', $r->headers->get('content-disposition'));
        }

        $this->actingAs(User::factory()->create())->get(route('keuangan.rekap-kekurangan', ['tampilan' => 'piutang_cash']))->assertForbidden();
    }
}
