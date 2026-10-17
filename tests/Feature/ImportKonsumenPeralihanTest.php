<?php

namespace Tests\Feature;

use App\Models\AlihPembeliRiwayat;
use App\Models\CancellationRequest;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
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

/** Import Konsumen — kolom Pembeli Sebelumnya (peralihan) dan peringatan kemungkinan ganda di sheet cara bayar. */
class ImportKonsumenPeralihanTest extends TestCase
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
        foreach (['1', '2'] as $no) {
            Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id, 'kluster' => 'Melati',
                'blok' => 'A1', 'nomor_kavling' => $no, 'harga' => 300000000, 'status_jual' => 'available']);
        }
        SkemaDpPreset::create(['nama' => 'Cash Keras', 'cara_bayar' => 'cash']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        parent::tearDown();
    }

    private function baris(array $override = []): array
    {
        $dasar = [
            'nama' => 'Siti B', 'nik' => '3201010101010101', 'hp' => '081200000002', 'kluster' => 'Melati', 'blok' => 'A1', 'unit' => '1',
            'tgl_booking' => '2026-03-01', 'tahap' => 'Booking', 'skema' => 'Cash Keras',
        ];
        $m = array_merge($dasar, $override);

        return array_map(fn ($c) => $m[$c[0]] ?? '', KonsumenImportSpec::columns('cash'));
    }

    private function file(array ...$barisList): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Cash');
        $sheet->fromArray([array_map(fn ($c) => $c[1], KonsumenImportSpec::columns('cash'))], null, 'A1');
        foreach ($barisList as $i => $b) $sheet->fromArray([$b], null, 'A' . ($i + 2), true);
        $path = tempnam(sys_get_temp_dir(), 'cash') . '.xlsx';
        (new Xlsx($ss))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, 'cash.xlsx', null, null, true);
    }

    private function impor(UploadedFile $file)
    {
        return $this->actingAs($this->admin)->post(route('konsumens.import', $this->project), ['file' => $file]);
    }

    public function test_baris_cash_dengan_pembeli_sebelumnya_membuat_riwayat_peralihan_disetujui(): void
    {
        $this->impor($this->file($this->baris([
            'alih_nama' => 'Andi A', 'alih_nik' => '3201010101010102', 'alih_hubungan' => 'Saudara kandung', 'alih_alasan' => 'A tidak lolos BI Checking',
        ])))->assertSessionHas('success');

        $b = Konsumen::where('nik', '3201010101010101')->firstOrFail();
        $a = Konsumen::where('nik', '3201010101010102')->firstOrFail();
        $trx = KavlingKonsumen::where('konsumen_id', $b->id)->firstOrFail();

        $this->assertSame('active', $trx->status);   // transaksi tetap berjalan; hanya pembelinya hasil peralihan
        $riwayat = AlihPembeliRiwayat::where('kavling_konsumen_id', $trx->id)->firstOrFail();
        $this->assertSame($a->id, $riwayat->dari_konsumen_id);
        $this->assertSame($b->id, $riwayat->ke_konsumen_id);
        $this->assertSame('saudara', $riwayat->hubungan);
        $this->assertSame('2026-03-01', $riwayat->disetujui_at->toDateString());    // tanggal kosong = tanggal booking

        $p = CancellationRequest::where('kavling_konsumen_id', $trx->id)->where('type', 'buyer_transfer')->firstOrFail();
        $this->assertTrue($p->isApproved());
        $this->assertSame($a->id, $p->konsumen_id);
        $this->assertSame($b->id, $p->konsumen_baru_id);
    }

    public function test_pembeli_sebelumnya_dengan_nik_yang_sudah_ada_memakai_konsumen_itu(): void
    {
        $a = Konsumen::create(['nama' => 'Andi A', 'nik' => '3201010101010102']);

        $this->impor($this->file($this->baris(['alih_nama' => 'Andi A', 'alih_nik' => '3201010101010102'])))->assertSessionHas('success');

        $this->assertSame(1, Konsumen::where('nik', '3201010101010102')->count());
        $this->assertSame($a->id, AlihPembeliRiwayat::firstOrFail()->dari_konsumen_id);
    }

    public function test_peralihan_tidak_valid_menolak_baris_tanpa_membuat_apa_apa(): void
    {
        $r = $this->impor($this->file($this->baris(['alih_nama' => 'Andi A', 'alih_nik' => '3201010101010101', 'alih_tgl' => '2026-01-01'])));

        $this->assertSame(0, KavlingKonsumen::count());
        $this->assertSame(0, AlihPembeliRiwayat::count());
        $r->assertSessionHas('importErrors', fn ($e) => str_contains(implode(' ', $e), 'NIK Pembeli Sebelumnya') && str_contains(implode(' ', $e), 'lebih awal dari Tanggal Booking'));
    }

    public function test_baris_tanpa_kolom_peralihan_tidak_membuat_riwayat(): void
    {
        $this->impor($this->file($this->baris()))->assertSessionHas('success');

        $this->assertSame(1, KavlingKonsumen::count());
        $this->assertSame(0, AlihPembeliRiwayat::count());
        $this->assertSame(0, CancellationRequest::count());
    }

    public function test_peringatan_ganda_di_sheet_cara_bayar_tidak_memblokir(): void
    {
        Konsumen::create(['nama' => 'Siti B', 'nik' => '3201010101010199']);

        $r = $this->impor($this->file($this->baris(['nik' => ''])));

        $r->assertSessionHas('warning');
        $r->assertSessionHas('importErrors', fn ($e) => str_contains(implode(' ', $e), '(peringatan)') && str_contains(implode(' ', $e), 'tanpa NIK'));
        $this->assertSame(1, KavlingKonsumen::count());
    }
}
