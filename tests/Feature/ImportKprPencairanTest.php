<?php

namespace Tests\Feature;

use App\Models\BankRekananPreset;
use App\Models\DajamSbumPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Project;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\KonsumenImportSpec;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Import KPR Subsidi: "Total Sudah Cair" hanya bagian netto (tanpa Dajam), dan peringatan bila plafon import berbeda dari hitungan aplikasi. */
class ImportKprPencairanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Proyek Uji', 'kode' => 'PU1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        foreach (['1', '2', '3'] as $no) {
            Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id, 'kluster' => '',
                'blok' => 'A1', 'nomor_kavling' => $no, 'harga' => 166000000, 'status_jual' => 'available']);
        }
        // Booking Fee tidak masuk harga jual; DP masuk -> plafon hitung = 166.000.000 - 1.660.000 - SBUM.
        SkemaDpPreset::create(['nama' => 'KPR Subsidi', 'cara_bayar' => 'kpr_subsidi',
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => false,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 1660000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto']);
        BankRekananPreset::create(['nama' => 'BTN', 'is_active' => true]);
        DajamSbumPreset::create(['nama' => 'SBUM Subsidi', 'kategori' => 'sbum', 'is_active' => true]);
        DajamSbumPreset::create(['nama' => 'Sertifikat', 'kategori' => 'dajam', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        parent::tearDown();
    }

    private function baris(array $o = []): array
    {
        $dasar = [
            'nama' => 'Andi Akad', 'nik' => '3201010101010201', 'blok' => 'A1', 'unit' => '1', 'tgl_booking' => '2026-08-01', 'tahap' => 'Akad', 'skema' => 'KPR Subsidi',
            'bank' => 'BTN', 'plafon' => 160340000, 'tgl_ajuan' => '2026-08-10', 'sp3k' => 'Approved', 'tgl_sp3k' => '2026-09-01', 'tgl_exp' => '2026-12-01',
            'tgl_rencana' => '2026-09-20', 'tgl_akad' => '2026-09-25',
            'sbum:SBUM Subsidi:n' => 4000000,
            'bf_target' => 1000000, 'bf_bayar' => 1000000, 'bf_tgl' => '2026-08-01', 'dp_target' => 1660000, 'dp_bayar' => 1660000, 'dp_tgl' => '2026-08-05',
        ];
        $m = array_merge($dasar, $o);

        return array_map(fn ($c) => $m[$c[0]] ?? '', KonsumenImportSpec::columns('kpr_subsidi'));
    }

    private function impor(array ...$barisList)
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('KPR Subsidi');
        $sheet->fromArray([array_map(fn ($c) => $c[1], KonsumenImportSpec::columns('kpr_subsidi'))], null, 'A1');
        foreach ($barisList as $i => $b) $sheet->fromArray([$b], null, 'A' . ($i + 2), true);
        $path = tempnam(sys_get_temp_dir(), 'kpr') . '.xlsx';
        (new Xlsx($ss))->save($path);
        $this->tmp[] = $path;

        return $this->actingAs($this->admin)->post(route('konsumens.import', $this->project), ['file' => new UploadedFile($path, 'kpr.xlsx', null, null, true)]);
    }

    public function test_total_sudah_cair_netto_diterima_dan_retensi_terhitung_otomatis(): void
    {
        $this->impor($this->baris(['cair_bayar' => 155340000, 'cair_tgl' => '2026-10-05', 'dajam:Sertifikat:n' => 5000000]))->assertSessionHas('success');

        $pk = KavlingKonsumen::firstOrFail()->ringkasPencairan();
        $this->assertEquals(160340000, $pk['plafon_disetujui']);
        $this->assertEquals(5000000, $pk['retensi']);
        $this->assertEquals(0, $pk['belum_dirinci']);
        $this->assertFalse($pk['plafon_beda']);
    }

    public function test_total_sudah_cair_yang_ikut_menghitung_dajam_ditolak_dengan_pesan_jelas(): void
    {
        $r = $this->impor($this->baris(['cair_bayar' => 160340000, 'cair_tgl' => '2026-10-05', 'dajam:Sertifikat:n' => 5000000, 'dajam:Sertifikat:d' => '2026-10-10']));

        $this->assertSame(0, KavlingKonsumen::count());
        $r->assertSessionHas('importErrors', fn ($e) => str_contains(implode(' ', $e), 'melebihi Plafon KPR') && str_contains(implode(' ', $e), 'sudah ikut menghitung Dajam'));
    }

    public function test_peringatan_bila_plafon_import_beda_dari_hitungan_karena_sbum_belum_diinput(): void
    {
        $r = $this->impor($this->baris(['sbum:SBUM Subsidi:n' => '', 'cair_bayar' => 100000000, 'cair_tgl' => '2026-10-05']));

        $r->assertSessionHas('warning');                                                           // diimpor + ada peringatan
        $r->assertSessionHas('importErrors', fn ($e) => str_contains(implode(' ', $e), '(peringatan)') && str_contains(implode(' ', $e), 'SBUM belum diinput'));
        $this->assertSame(1, KavlingKonsumen::count());                                         // tetap masuk
    }

    public function test_tanpa_peringatan_bila_plafon_import_sama_dengan_hitungan(): void
    {
        $r = $this->impor($this->baris(['cair_bayar' => 100000000, 'cair_tgl' => '2026-10-05']));

        $r->assertSessionHas('success');
        $r->assertSessionMissing('importErrors');
    }

    public function test_status_turun_plafon_tanpa_sbum_tidak_membuat_tambahan_uang_muka_palsu(): void
    {
        $this->impor($this->baris(['sp3k' => 'Turun Plafon', 'sbum:SBUM Subsidi:n' => '']))->assertSessionHas('warning');   // hanya peringatan selisih plafon

        $breakdown = KavlingKonsumen::firstOrFail()->fresh(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])->kartuPiutangBreakdown();
        $this->assertEquals(0, $breakdown['pencairan_kpr']['tambahan_um']);
        $this->assertSame([], $breakdown['kartu_piutang_static']->where('type', 'tambahan_um_group')->all());
    }
}
