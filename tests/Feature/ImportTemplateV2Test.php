<?php

namespace Tests\Feature;

use App\Enums\StatusJual;
use App\Models\BiayaTambahanPreset;
use App\Models\BonusPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Project;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\PromoPresetLawan;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\KonsumenImportSpec;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Template Import v2: Promo + Potongan (Rp) per biaya (tanah, titipan, tiap biaya tambahan; Target = bruto), kolom Bonus dipisah koma,
 * diskon harus berasal dari promo berlawan Harga Jual, dan promo lewat import hanya untuk pemegang izin override.
 * Memakai sheet Cash (tanpa bank/SBUM) sebagai wadah.
 */
class ImportTemplateV2Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private SkemaDpPreset $skema;
    private BiayaTambahanPreset $hook;
    private PromoPreset $promoTanah;
    private PromoPreset $promoGabungan;
    private PromoPreset $promoTitipan;
    private PromoPreset $promoDiskon;
    private PromoPreset $promoLabel;
    private ProgramAllInPreset $allIn;
    private array $tmp = [];
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Proyek Uji', 'kode' => 'PU1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        foreach (range(1, 12) as $no) {
            Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id, 'kluster' => 'Melati', 'blok' => 'A1',
                'nomor_kavling' => (string) $no, 'harga' => 166000000, 'status_jual' => StatusJual::Available]);
        }
        $this->skema = SkemaDpPreset::create(['nama' => 'Cash Uji', 'cara_bayar' => 'cash',
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => false,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 10000000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto']);
        $this->hook = BiayaTambahanPreset::create(['nama' => 'Kavling Hook', 'is_active' => true]);
        $lawan = fn (string $nama, array $jenis, ?int $btId = null) => tap(PromoPreset::create(['nama' => $nama, 'is_active' => true]), function ($p) use ($jenis, $btId) {
            foreach ($jenis as $j) $p->lawan()->create(['jenis' => $j, 'biaya_tambahan_preset_id' => $j === PromoPresetLawan::BIAYA_TAMBAHAN ? $btId : null]);
        });
        $this->promoTanah = $lawan('Free Tanah', [PromoPresetLawan::TANAH]);
        $this->promoGabungan = $lawan('Free Tanah dan Hook', [PromoPresetLawan::TANAH, PromoPresetLawan::BIAYA_TAMBAHAN], $this->hook->id);
        $this->promoTitipan = $lawan('Diskon Biaya Akad', [PromoPresetLawan::TITIPAN_BIAYA_AKAD]);
        $this->promoDiskon = $lawan('Diskon Akhir Tahun', [PromoPresetLawan::HARGA_JUAL]);
        $this->promoLabel = $lawan('Free Kanopi', []);
        BonusPreset::create(['nama' => 'Tandon Air', 'is_active' => true]);
        BonusPreset::create(['nama' => 'Kipas Angin', 'is_active' => true]);
        $this->allIn = ProgramAllInPreset::create(['nama' => 'All In 10,5jt', 'nominal' => 10500000, 'include_booking_fee' => false, 'include_dp' => true, 'dp_nominal_tetap' => 1660000, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        parent::tearDown();
    }

    /** Baris sheet Cash: nilai default kosong, diurutkan persis seperti kolom template. */
    private function baris(array $o = []): array
    {
        $this->nomor++;
        $dasar = ['nama' => 'Budi ' . $this->nomor, 'nik' => '32010100000000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT), 'blok' => 'A1', 'kluster' => 'Melati', 'unit' => (string) $this->nomor,
            'tgl_booking' => '2026-03-01', 'tahap' => 'Pemberkasan', 'skema' => 'Cash Uji'];
        $m = array_merge($dasar, $o);

        return array_map(fn ($c) => $m[$c[0]] ?? '', KonsumenImportSpec::columns('cash'));
    }

    private function file(array $barisList, ?array $header = null): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Cash');
        $header ??= array_map(fn ($c) => $c[1] . ($c[2] === 'w' ? ' *' : ''), KonsumenImportSpec::columns('cash'));
        $sheet->fromArray([$header], null, 'A1');
        foreach ($barisList as $i => $b) $sheet->fromArray([$b], null, 'A' . ($i + 2), true);
        $path = tempnam(sys_get_temp_dir(), 'v2') . '.xlsx';
        (new Xlsx($ss))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, 'v2.xlsx', null, null, true);
    }

    /** Cek dulu lalu import sungguhan; mengembalikan laporan import. */
    private function impor(array $barisList, ?User $oleh = null): array
    {
        $file = $this->file($barisList);
        $oleh ??= $this->admin;
        $this->actingAs($oleh)->post(route('konsumens.import', $this->project), ['file' => $file, 'mode' => 'cek']);
        $r = $this->actingAs($oleh)->post(route('konsumens.import', $this->project), ['file' => $file, 'mode' => 'import']);

        $this->assertTrue($r->isRedirect(), 'Respons import bukan redirect: status ' . $r->getStatusCode() . ' ' . substr(strip_tags((string) $r->getContent()), 0, 400));

        return session('importLaporan');
    }

    private function trx(): KavlingKonsumen
    {
        return KavlingKonsumen::with(['biayaTambahans', 'bonuses'])->latest('id')->firstOrFail();
    }

    private function kartu(KavlingKonsumen $kk): \Illuminate\Support\Collection
    {
        return collect($kk->fresh(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
            ->kartuPiutangBreakdown()['kartu_piutang_static'])->keyBy('nama');
    }

    // ── Template ──

    public function test_template_memuat_kolom_baru_dan_daftar_dropdown_yang_disaring(): void
    {
        $kunci = collect(KonsumenImportSpec::columns('cash'))->pluck(0)->all();
        foreach (['bonus', 'tanah_promo', 'tanah_potongan', 'titipan_promo', 'titipan_potongan', 'bt:Kavling Hook:p', 'bt:Kavling Hook:x'] as $k) {
            $this->assertContains($k, $kunci);
        }
        // Urutan biaya tambahan: Target, Promo, Potongan, Terbayar, Tgl Terakhir.
        $i = array_search('bt:Kavling Hook:t', $kunci);
        $this->assertSame(['bt:Kavling Hook:t', 'bt:Kavling Hook:p', 'bt:Kavling Hook:x', 'bt:Kavling Hook:b', 'bt:Kavling Hook:d'], array_slice($kunci, $i, 5));

        $lists = KonsumenImportSpec::lists();
        $this->assertEqualsCanonicalizing(['Free Tanah', 'Free Tanah dan Hook'], $lists['Promo Tanah']);
        $this->assertSame(['Diskon Biaya Akad'], $lists['Promo Titipan']);
        $this->assertSame(['Free Tanah dan Hook'], $lists['Promo Biaya Tambahan']);
        $this->assertEqualsCanonicalizing(['Tandon Air', 'Kipas Angin'], $lists['Bonus']);
        $this->assertSame('Promo Tanah', KonsumenImportSpec::dropdownListFor('tanah_promo', 'cash'));
        $this->assertSame('Promo Biaya Tambahan', KonsumenImportSpec::dropdownListFor('bt:Kavling Hook:p', 'cash'));
    }

    public function test_template_yang_diunduh_lolos_validasi_header_dan_template_lama_ditolak(): void
    {
        $r = $this->actingAs($this->admin)->get(route('konsumens.import-template', $this->project));
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        $this->tmp[] = $path;
        file_put_contents($path, $r->streamedContent());
        $this->assertSame([], KonsumenImportSpec::validasiHeader($path));

        // Template versi lama (tanpa kolom Bonus, Promo, dan Potongan) ditolak seluruhnya dengan pesan yang jelas.
        $baru = ['bonus', 'tanah_promo', 'tanah_potongan', 'titipan_promo', 'titipan_potongan'];
        $lama = collect(KonsumenImportSpec::columns('cash'))->reject(fn ($c) => in_array($c[0], $baru, true) || preg_match('/^bt:.+:[px]$/', $c[0]))->map(fn ($c) => $c[1])->all();
        $masalah = KonsumenImportSpec::validasiHeader($this->file([], $lama)->getRealPath());
        $this->assertStringContainsString('[Cash] Header tidak sesuai template', implode(' ', $masalah));
    }

    // ── Promo + potongan per biaya ──

    public function test_tanah_gratis_lewat_promo_menagih_nol_dan_menyimpan_nilai_promo(): void
    {
        $l = $this->impor([$this->baris(['tanah_mode' => 'Nominal', 'tanah_target' => 8000000, 'tanah_promo' => 'Free Tanah', 'tanah_potongan' => 8000000])]);

        $this->assertSame(1, $l['imported']);
        $kk = $this->trx();
        $this->assertEquals(8000000, $kk->biaya_kelebihan_tanah_nominal);
        $this->assertEquals(8000000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertSame('nominal', $kk->biaya_kelebihan_tanah_potongan_mode);
        $this->assertSame($this->promoTanah->id, $kk->biaya_kelebihan_tanah_promo_id);
        $this->assertEquals(0, $kk->total_biaya_tambahan);                                           // neto
        $this->assertEquals(166000000, $kk->harga_deal);
        $tanah = $this->kartu($kk)['Biaya Penambahan Tanah'];
        $this->assertEquals(0, $tanah['nominal']);
        $this->assertSame(8000000.0, (float) $tanah['bruto']);
        $this->assertSame(8000000.0, (float) $tanah['potongan']);
    }

    public function test_potongan_sebagian_dan_terbayar_dibandingkan_dengan_yang_ditagih(): void
    {
        $l = $this->impor([$this->baris([
            'tanah_mode' => 'Nominal', 'tanah_target' => 8000000, 'tanah_promo' => 'Free Tanah dan Hook', 'tanah_potongan' => 3000000, 'tanah_bayar' => 5000000, 'tanah_tgl' => '2026-04-01',
            'bt:Kavling Hook:t' => 5000000, 'bt:Kavling Hook:p' => 'Free Tanah dan Hook', 'bt:Kavling Hook:x' => 1500000, 'bt:Kavling Hook:b' => 3500000, 'bt:Kavling Hook:d' => '2026-04-02',
        ])]);

        $this->assertSame(1, $l['imported'], implode(' | ', $l['errors']));
        $kk = $this->trx();
        $this->assertEquals((8000000 - 3000000) + (5000000 - 1500000), $kk->total_biaya_tambahan);
        $this->assertEquals(166000000 + 8500000, $kk->harga_deal);
        $hook = $kk->biayaTambahans->first();
        $this->assertEquals(1500000, $hook->potongan);
        $this->assertEquals($this->promoGabungan->id, $hook->promo_preset_id);
        $this->assertSame('lunas', $this->kartu($kk)['Kavling Hook']['status']);                  // terbayar 3,5 jt = yang ditagih
        $this->assertSame('lunas', $this->kartu($kk)['Biaya Penambahan Tanah']['status']);        // terbayar 5 jt = yang ditagih
    }

    public function test_titipan_dengan_potongan_promo(): void
    {
        $l = $this->impor([$this->baris(['all_in' => 'All In 10,5jt', 'titipan_promo' => 'Diskon Biaya Akad', 'titipan_potongan' => 1000000])]);

        $this->assertSame(1, $l['imported'], implode(' | ', $l['errors']));
        $kk = $this->trx();
        $this->assertEquals(8840000, $kk->titipan_biaya_akad_nominal);                              // 10,5 jt - DP tetap 1,66 jt
        $this->assertEquals(1000000, $kk->titipan_biaya_akad_potongan);
        $this->assertSame($this->promoTitipan->id, $kk->titipan_biaya_akad_promo_id);
        $this->assertEquals(7840000, $this->kartu($kk)['Titipan Biaya Akad']['nominal']);
    }

    public function test_diskon_dari_promo_dengan_lawan_harga_jual_dan_pelunasan_cash_tanpa_komponen(): void
    {
        $l = $this->impor([$this->baris(['promo' => 'Diskon Akhir Tahun', 'diskon_mode' => 'Nominal', 'diskon_nilai' => 3000000])]);

        $this->assertSame(1, $l['imported'], implode(' | ', $l['errors']));
        $kk = $this->trx();
        $this->assertEquals(3000000, $kk->diskon_nominal);
        $this->assertSame($this->promoDiskon->id, $kk->diskon_promo_id);
        $this->assertEquals(166000000 - 3000000 - 10000000, $kk->jadwalTagihans()->where('jenis', 'pelunasan')->sum('jumlah'));   // harga dasar - diskon - DP
    }

    // ── Penolakan ──

    public function test_pasangan_promo_dan_potongan_wajib_berdua_dan_harus_masuk_akal(): void
    {
        $tanah = ['tanah_mode' => 'Nominal', 'tanah_target' => 8000000];
        $kasus = [
            'Promo terisi tetapi Potongan'   => [$tanah + ['tanah_promo' => 'Free Tanah'], 'Promo terisi tetapi Potongan (Rp) kosong'],
            'Potongan terisi tetapi Promo'   => [$tanah + ['tanah_potongan' => 1000000], 'Potongan terisi tetapi Promo kosong'],
            'promo tanpa lawan tanah'        => [$tanah + ['tanah_promo' => 'Diskon Biaya Akad', 'tanah_potongan' => 1000000], "tidak punya lawan Kelebihan Tanah"],
            'promo tak dikenal'              => [$tanah + ['tanah_promo' => 'Promo Siluman', 'tanah_potongan' => 1000000], "promo 'Promo Siluman' tidak dikenal"],
            'potongan melebihi biaya'        => [$tanah + ['tanah_promo' => 'Free Tanah', 'tanah_potongan' => 9000000], 'melebihi nilai biayanya'],
            'tanah tidak aktif'              => [['tanah_promo' => 'Free Tanah', 'tanah_potongan' => 1000000], 'tidak ada biaya yang dipotong'],
            'biaya tambahan tanpa target'    => [['bt:Kavling Hook:p' => 'Free Tanah dan Hook', 'bt:Kavling Hook:x' => 1000000], 'Target kosong'],
            'promo salah untuk biaya tambahan' => [['bt:Kavling Hook:t' => 5000000, 'bt:Kavling Hook:p' => 'Free Tanah', 'bt:Kavling Hook:x' => 1000000], 'tidak punya lawan Biaya Tambahan Kavling Hook'],
            'titipan tanpa all in'           => [['titipan_promo' => 'Diskon Biaya Akad', 'titipan_potongan' => 1000000], 'tidak ada biaya yang dipotong'],
            'diskon tanpa promo'             => [['diskon_mode' => 'Nominal', 'diskon_nilai' => 1000000], 'Diskon terisi tetapi Nama Promo kosong'],
            'diskon dari promo tanpa lawan harga jual' => [['promo' => 'Free Tanah', 'diskon_mode' => 'Nominal', 'diskon_nilai' => 1000000], 'tidak punya lawan Harga Jual'],
            'bonus tak dikenal'              => [['bonus' => 'Tandon Air, Mobil Mewah'], "Bonus 'Mobil Mewah' tidak dikenal"],
        ];

        // Semua kasus dalam satu file (rute import dibatasi laju): tiap baris harus dilewati dengan pesannya sendiri.
        $l = $this->impor(array_map(fn ($k) => $this->baris($k[0]), array_values($kasus)));
        $this->assertSame(0, $l['imported']);
        $this->assertSame(count($kasus), $l['skipped']);
        foreach (array_values($kasus) as $i => [$baris, $pesan]) {
            $barisKe = $i + 2;
            $pesanBaris = collect($l['errors'])->first(fn ($e) => str_contains($e, "Baris {$barisKe}"));
            $this->assertNotNull($pesanBaris, 'baris ' . $barisKe . ' tidak dilaporkan');
            $this->assertStringContainsString($pesan, $pesanBaris, array_keys($kasus)[$i]);
        }
        $this->assertSame(0, KavlingKonsumen::count());
    }

    // ── Bonus ──

    public function test_bonus_banyak_dipisah_koma_tidak_peduli_huruf_dan_spasi(): void
    {
        $l = $this->impor([$this->baris(['bonus' => 'tandon air ,  Kipas Angin,, TANDON AIR'])]);

        $this->assertSame(1, $l['imported'], implode(' | ', $l['errors']));
        $kk = $this->trx();
        $this->assertEqualsCanonicalizing(['Tandon Air', 'Kipas Angin'], $kk->bonuses->pluck('nama')->all());           // tanpa duplikat
        $this->assertEquals(166000000, $kk->harga_deal);                                              // bonus tidak mengubah harga
    }

    // ── Izin ──

    public function test_promo_lewat_import_hanya_untuk_pemegang_izin_override(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);

        $l = $this->impor([
            $this->baris(['bonus' => 'Tandon Air']),
            $this->baris(['promo' => 'Free Kanopi']),
            $this->baris(['tanah_mode' => 'Nominal', 'tanah_target' => 8000000, 'tanah_promo' => 'Free Tanah', 'tanah_potongan' => 8000000]),
            $this->baris(),                                                                            // tanpa promo: lolos
        ], $sales);

        $this->assertSame(1, $l['imported']);
        $this->assertSame(3, $l['skipped']);
        $this->assertStringContainsString('hanya boleh diisi oleh pemegang izin override', $l['errors'][0]);
        $this->assertSame(1, KavlingKonsumen::count());
    }
}
