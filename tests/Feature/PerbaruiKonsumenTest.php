<?php

namespace Tests\Feature;

use App\Models\BankRekananPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\NotarisPreset;
use App\Models\Project;
use App\Models\SalesAgent;
use App\Models\StatusBangunStage;
use App\Models\SumberLead;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\KonsumenPerbaruiSpec;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Mode Perbarui Import Konsumen: hanya mengisi yang kosong, verifikasi nama, NIK, izin, dan transaksi terkunci. */
class PerbaruiKonsumenTest extends TestCase
{
    use RefreshDatabase;

    private const NIK_A = '3201010101900001';
    private const NIK_B = '3171024505850002';

    private User $admin;
    private Project $project;
    private TipeUnitPreset $tipe;
    private NotarisPreset $notaris;
    private array $tmp = [];
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Proyek Uji', 'kode' => 'PU1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->notaris = NotarisPreset::create(['nama' => 'Notaris Satu', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        parent::tearDown();
    }

    private function transaksi(array $konsumen = [], array $trx = []): KavlingKonsumen
    {
        $this->nomor++;
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'status_bangun_stage_id' => $stage->id,
            'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor, 'harga' => 100000000, 'status_jual' => 'booked']);
        $k = Konsumen::create($konsumen + ['nama' => 'Budi Santoso']);

        return KavlingKonsumen::create($trx + ['kavling_id' => $unit->id, 'konsumen_id' => $k->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
            'cara_bayar' => 'cash', 'tanggal_booking' => '2026-03-01', 'harga_dasar' => 100000000, 'harga_deal' => 100000000]);
    }

    /** Baris Perbarui: kunci unit dari transaksi + isian. */
    private function baris(KavlingKonsumen $kk, array $o = []): array
    {
        $m = $o + ['blok' => 'A1', 'unit' => $kk->kavling->nomor_kavling, 'nama' => $kk->konsumen->nama];

        return array_map(fn ($c) => $m[$c[0]] ?? '', KonsumenPerbaruiSpec::columns());
    }

    private function file(array $barisList, ?array $header = null): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(KonsumenPerbaruiSpec::SHEET);
        $header ??= array_map(fn ($c) => $c[1] . ($c[2] === 'w' ? ' *' : ''), KonsumenPerbaruiSpec::columns());
        $sheet->fromArray([$header], null, 'A1');
        foreach ($barisList as $i => $b) {
            foreach ($b as $kolom => $nilai) $sheet->setCellValueExplicit([$kolom + 1, $i + 2], (string) $nilai, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'pb') . '.xlsx';
        (new Xlsx($ss))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, 'perbarui.xlsx', null, null, true);
    }

    /** Cek dulu lalu import sungguhan; mengembalikan laporan terakhir. */
    private function perbarui(array $barisList, ?User $oleh = null, bool $simpan = true): array
    {
        $file = $this->file($barisList);
        $oleh ??= $this->admin;
        $cek = $this->actingAs($oleh)->post(route('konsumens.perbarui', $this->project), ['file' => $file, 'mode' => 'cek']);
        $laporan = $cek->getSession()->get('importLaporan');
        if (!$simpan) return $laporan;
        $r = $this->actingAs($oleh)->post(route('konsumens.perbarui', $this->project), ['file' => $file, 'mode' => 'import']);

        return $r->getSession()->get('importLaporan') ?? $laporan;
    }

    public function test_mengisi_yang_kosong_dan_menolak_menimpa_yang_sudah_ada(): void
    {
        $kk = $this->transaksi(['no_hp' => '081111111111', 'nik' => self::NIK_A]);

        $lap = $this->perbarui([$this->baris($kk, ['hp' => '082222222222', 'email' => 'budi@contoh.com', 'alamat' => 'Jl. Mawar 1', 'notaris' => 'Notaris Satu'])]);

        $kk->refresh()->load('konsumen');
        $this->assertSame('081111111111', $kk->konsumen->no_hp, 'HP yang sudah ada tidak ditimpa');
        $this->assertSame('budi@contoh.com', $kk->konsumen->email);
        $this->assertSame('Jl. Mawar 1', $kk->konsumen->alamat);
        $this->assertSame($this->notaris->id, $kk->notaris_preset_id);
        $this->assertSame(1, $lap['imported']);
        $this->assertCount(1, $lap['warnings']);
        $this->assertStringContainsString('tidak ditimpa', $lap['warnings'][0]);
        $this->assertNotEmpty($lap['perubahan']);
    }

    public function test_cek_dulu_tidak_menyimpan_apa_pun(): void
    {
        $kk = $this->transaksi();
        $lap = $this->perbarui([$this->baris($kk, ['notaris' => 'Notaris Satu', 'email' => 'a@b.co'])], null, false);

        $this->assertSame('cek', $lap['mode']);
        $this->assertSame(1, $lap['imported']);
        $this->assertNull($kk->fresh()->notaris_preset_id);
        $this->assertNull($kk->konsumen->fresh()->email);
    }

    public function test_import_sungguhan_wajib_cek_dulu(): void
    {
        $kk = $this->transaksi();
        $r = $this->actingAs($this->admin)->post(route('konsumens.perbarui', $this->project), ['file' => $this->file([$this->baris($kk, ['email' => 'a@b.co'])]), 'mode' => 'import']);
        $r->assertSessionHas('error');
        $this->assertNull($kk->konsumen->fresh()->email);
    }

    public function test_nama_beda_jauh_dilewati_supaya_tidak_masuk_ke_orang_lain(): void
    {
        $kk = $this->transaksi();
        $lap = $this->perbarui([$this->baris($kk, ['nama' => 'Xyz Qwerty', 'email' => 'salah@orang.co'])]);

        $this->assertSame(0, $lap['imported']);
        $this->assertSame(1, $lap['skipped']);
        $this->assertStringContainsString('berbeda dengan konsumen', $lap['errors'][0]);
        $this->assertNull($kk->konsumen->fresh()->email);
    }

    public function test_unit_tanpa_transaksi_atau_tidak_ada_dilewati(): void
    {
        $kk = $this->transaksi();
        $kosong = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'status_bangun_stage_id' => $kk->kavling->status_bangun_stage_id, 'blok' => 'A1', 'nomor_kavling' => '99', 'harga' => 1, 'status_jual' => 'available']);

        $lap = $this->perbarui([
            $this->baris($kk, ['unit' => '99', 'nama' => 'Siapa Saja']),
            $this->baris($kk, ['unit' => '404']),
        ], null, false);

        $this->assertSame(2, $lap['skipped']);
        $this->assertStringContainsString('belum punya transaksi', $lap['errors'][0]);
        $this->assertStringContainsString('tidak ditemukan', $lap['errors'][1]);
    }

    public function test_transaksi_selesai_atau_terkunci_dilewati(): void
    {
        $kk = $this->transaksi();
        \DB::table('kavling_konsumen')->where('id', $kk->id)->update(['status' => 'completed']);
        $lap = $this->perbarui([$this->baris($kk, ['email' => 'a@b.co'])], null, false);

        $this->assertSame(1, $lap['skipped']);
        $this->assertStringContainsString('Selesai/terkunci', $lap['errors'][0]);
    }

    public function test_nik_kosong_diisi_nik_valid_dibiarkan_nik_tidak_valid_diganti(): void
    {
        $kosong = $this->transaksi();
        $valid = $this->transaksi(['nama' => 'Siti Aminah', 'nik' => self::NIK_A]);
        $rusak = $this->transaksi(['nama' => 'Andi Wijaya', 'nik' => '12345']);

        $lap = $this->perbarui([
            $this->baris($kosong, ['nik' => self::NIK_B]),
            $this->baris($valid, ['nik' => self::NIK_B]),
            $this->baris($rusak, ['nik' => '3174011502880003']),
        ]);

        $this->assertSame(self::NIK_B, $kosong->konsumen->fresh()->nik);
        $this->assertSame(self::NIK_A, $valid->konsumen->fresh()->nik, 'NIK valid tidak pernah diganti');
        $this->assertSame('3174011502880003', $rusak->konsumen->fresh()->nik, 'NIK tidak valid boleh diganti');
        $this->assertCount(1, $lap['warnings']);
        $this->assertStringContainsString('tidak diganti', $lap['warnings'][0]);
    }

    public function test_nik_yang_dipakai_konsumen_lain_atau_tidak_valid_ditolak(): void
    {
        $a = $this->transaksi(['nama' => 'Siti Aminah', 'nik' => self::NIK_A]);
        $b = $this->transaksi(['nama' => 'Andi Wijaya']);

        $lap = $this->perbarui([
            $this->baris($b, ['nik' => self::NIK_A]),
            $this->baris($b, ['nik' => '1234567890123456']),
        ], null, false);

        $this->assertSame(2, $lap['skipped']);
        $this->assertStringContainsString('sudah dipakai konsumen lain', $lap['errors'][0]);
        $this->assertNull($b->konsumen->fresh()->nik);
    }

    public function test_sales_notaris_bank_dan_lead_hanya_mengisi_kosong(): void
    {
        $sales = SalesAgent::create(['nama' => 'Sales Satu', 'is_active' => true, 'tipe' => 'inhouse']);
        $lead = SumberLead::create(['nama' => 'Instagram', 'is_active' => true]);
        $kpr = $this->transaksi([], ['cara_bayar' => 'kpr_subsidi']);
        $bank = BankRekananPreset::create(['nama' => 'Bank Satu', 'is_active' => true]);

        $this->perbarui([$this->baris($kpr, ['sales' => 'Sales Satu', 'bank' => 'Bank Satu', 'lead' => 'Instagram'])]);

        $kpr->refresh();
        $this->assertSame($sales->id, $kpr->sales_agent_id);
        $this->assertSame($bank->id, $kpr->bank_rekanan_preset_id);
        $this->assertSame($lead->id, $kpr->konsumen->fresh()->sumber_lead_id);
    }

    public function test_bank_diabaikan_untuk_transaksi_cash(): void
    {
        BankRekananPreset::create(['nama' => 'Bank Satu', 'is_active' => true]);
        $kk = $this->transaksi();
        $lap = $this->perbarui([$this->baris($kk, ['bank' => 'Bank Satu', 'email' => 'a@b.co'])]);

        $this->assertNull($kk->fresh()->bank_rekanan_preset_id);
        $this->assertStringContainsString('bukan KPR', $lap['warnings'][0]);
    }

    public function test_tanpa_izin_pipeline_notaris_diabaikan_dengan_peringatan(): void
    {
        $pemberkasan = User::factory()->create();
        $pemberkasan->assignRole('admin_pemberkasan');
        $pemberkasan->projects()->attach($this->project->id);
        $kk = $this->transaksi();

        $lap = $this->perbarui([$this->baris($kk, ['notaris' => 'Notaris Satu', 'email' => 'a@b.co'])], $pemberkasan);

        $this->assertNull($kk->fresh()->notaris_preset_id);
        $this->assertSame('a@b.co', $kk->konsumen->fresh()->email);
        $this->assertStringContainsString('tidak punya izin', $lap['warnings'][0]);
    }

    public function test_header_lama_atau_template_lain_ditolak(): void
    {
        $kk = $this->transaksi();
        $header = array_map(fn ($c) => $c[1], KonsumenPerbaruiSpec::columns());
        $header[5] = 'Kolom Aneh';
        $file = $this->file([$this->baris($kk, ['email' => 'a@b.co'])], $header);

        $r = $this->actingAs($this->admin)->post(route('konsumens.perbarui', $this->project), ['file' => $file, 'mode' => 'cek']);
        $this->assertSame('ditolak', $r->getSession()->get('importLaporan')['mode']);
    }

    public function test_template_perbarui_dapat_diunduh_dan_berisi_kolom_tanpa_keuangan(): void
    {
        $r = $this->actingAs($this->admin)->get(route('konsumens.perbarui-template', $this->project));
        $r->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tp') . '.xlsx';
        $this->tmp[] = $path;
        file_put_contents($path, $r->streamedContent());
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $this->assertSame(['Perbarui', 'Petunjuk', 'Daftar'], $ss->getSheetNames());
        $this->assertSame([], KonsumenPerbaruiSpec::validasiHeader($path));
    }

    public function test_pengguna_tanpa_izin_edit_konsumen_ditolak(): void
    {
        $u = User::factory()->create();
        $u->assignRole('pelaksana_lapangan');
        $u->projects()->attach($this->project->id);

        $this->actingAs($u)->get(route('konsumens.perbarui-template', $this->project))->assertForbidden();
    }

    private function wilayahContoh(): void
    {
        foreach ([['32', 'Jawa Barat', null], ['32.73', 'Kota Bandung', '32'], ['32.73.01', 'Sukasari', '32.73'], ['32.73.01.1001', 'Sukarasa', '32.73.01'], ['32.04', 'Kabupaten Bandung', '32']] as [$kode, $nama, $induk]) {
            \App\Models\Wilayah::create(['kode' => $kode, 'level' => substr_count($kode, '.') + 1, 'induk' => $induk, 'nama' => $nama]);
        }
    }

    public function test_wilayah_diisi_dilengkapi_lebih_dalam_dan_tidak_menimpa_yang_bertentangan(): void
    {
        $this->wilayahContoh();
        $kosong = $this->transaksi(['nama' => 'Siti Aminah']);
        $kabSaja = $this->transaksi(['nama' => 'Andi Wijaya'] + \App\Support\Wilayah::atribut('32.73'));
        $beda = $this->transaksi(['nama' => 'Dewi Lestari'] + \App\Support\Wilayah::atribut('32.04'));

        $lap = $this->perbarui([
            $this->baris($kosong, ['prov' => 'Jawa Barat', 'kab' => 'Kota Bandung', 'kec' => 'Sukasari', 'kel' => 'Sukarasa']),
            $this->baris($kabSaja, ['prov' => 'Jawa Barat', 'kab' => 'Kota Bandung', 'kec' => 'Sukasari']),
            $this->baris($beda, ['prov' => 'Jawa Barat', 'kab' => 'Kota Bandung']),
        ]);

        $this->assertSame('Sukarasa', $kosong->konsumen->fresh()->kelurahan_nama);
        $this->assertSame('Sukasari', $kabSaja->konsumen->fresh()->kecamatan_nama, 'melengkapi tingkat lebih dalam');
        $this->assertSame('Kabupaten Bandung', $beda->konsumen->fresh()->kabupaten_nama, 'wilayah bertentangan tidak ditimpa');
        $this->assertCount(1, $lap['warnings']);
        $this->assertStringContainsString('Wilayah sudah terisi', $lap['warnings'][0]);
    }

    public function test_wilayah_tidak_dikenal_atau_urutan_salah_membuat_baris_dilewati(): void
    {
        $this->wilayahContoh();
        $kk = $this->transaksi();

        $lap = $this->perbarui([
            $this->baris($kk, ['prov' => 'Jawa Barat', 'kab' => 'Kota Mars']),
            $this->baris($kk, ['kab' => 'Kota Bandung']),
        ], null, false);

        $this->assertSame(2, $lap['skipped']);
        $this->assertStringContainsString('tidak dikenal', $lap['errors'][0]);
        $this->assertStringContainsString('Provinsi kosong', $lap['errors'][1]);
    }
}
