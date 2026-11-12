<?php

namespace Tests\Feature;

use App\Enums\StatusJual;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
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
 * Import Aman (R6): header file harus sama dengan template (kolom bergeser menolak seluruh file), "Cek dulu" menjalankan importer yang
 * sama lalu membatalkannya, import sungguhan hanya untuk file yang sama dengan yang baru dicek, dan NIK yang cocok dengan konsumen lain
 * tetapi nama jelas beda ditolak. Memakai sheet Batal sebagai wadah karena kolomnya paling ringkas.
 */
class ImportAmanTest extends TestCase
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
            Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id, 'kluster' => 'Melati', 'blok' => 'A1',
                'nomor_kavling' => $no, 'harga' => 300000000, 'status_jual' => StatusJual::Available]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        parent::tearDown();
    }

    private function baris(array $override = []): array
    {
        $dasar = [
            'nama' => 'Budi Santoso', 'nik' => '', 'hp' => '081200000001', 'kluster' => 'Melati', 'blok' => 'A1', 'unit' => '1',
            'tgl_booking' => '2026-03-01', 'tgl_batal' => '2026-04-15', 'cara_bayar' => '', 'skema' => '', 'harga_deal' => '',
            'total_bayar' => 10000000, 'tgl_bayar' => '2026-03-20', 'dikembalikan' => '', 'alasan' => '', 'rincian' => '',
            'sales' => '', 'bank' => '', 'tahap_batal' => '',
            'alih_nama' => '', 'alih_nik' => '', 'alih_hp' => '', 'alih_hubungan' => '', 'alih_tgl' => '', 'alih_alasan' => '',
        ];
        $m = array_merge($dasar, $override);

        return array_map(fn ($c) => $m[$c[0]], KonsumenImportSpec::batalColumns());
    }

    /** @param string[]|null $header header sheet Batal; null = persis seperti template (dengan tanda * untuk kolom wajib). */
    private function file(array $barisList, ?array $header = null, string $judul = 'Batal'): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle($judul);
        $header ??= array_map(fn ($c) => $c[1] . ($c[2] === 'w' ? ' *' : ''), KonsumenImportSpec::batalColumns());
        $sheet->fromArray([$header], null, 'A1');
        foreach ($barisList as $i => $b) $sheet->fromArray([$b], null, 'A' . ($i + 2), true);
        $path = tempnam(sys_get_temp_dir(), 'aman') . '.xlsx';
        (new Xlsx($ss))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, 'aman.xlsx', null, null, true);
    }

    private function kirim(UploadedFile $file, string $mode)
    {
        return $this->actingAs($this->admin)->post(route('konsumens.import', $this->project), ['file' => $file, 'mode' => $mode]);
    }

    // ── Header ────────────────────────────────────────────────────────────

    public function test_template_yang_diunduh_lolos_validasi_header(): void
    {
        $r = $this->actingAs($this->admin)->get(route('konsumens.import-template', $this->project));
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        $this->tmp[] = $path;
        file_put_contents($path, $r->streamedContent());

        $this->assertSame([], KonsumenImportSpec::validasiHeader($path));
    }

    public function test_header_dengan_tanda_wajib_atau_tanpa_tanda_sama_sama_lolos(): void
    {
        $tanpaTanda = array_map(fn ($c) => $c[1], KonsumenImportSpec::batalColumns());
        $this->assertSame([], KonsumenImportSpec::validasiHeader($this->file([], $tanpaTanda)->getRealPath()));
        $this->assertSame([], KonsumenImportSpec::validasiHeader($this->file([])->getRealPath()));
    }

    public function test_kolom_bergeser_menolak_seluruh_file_dan_tidak_ada_baris_masuk(): void
    {
        $header = array_map(fn ($c) => $c[1], KonsumenImportSpec::batalColumns());
        [$header[0], $header[1]] = [$header[1], $header[0]];                       // Nama Konsumen dan NIK tertukar
        $file = $this->file([$this->baris(), $this->baris(['unit' => '2', 'nama' => 'Siti'])], $header);

        $r = $this->kirim($file, 'cek');
        $r->assertSessionHas('error');
        $r->assertSessionHas('importLaporan', fn ($l) => $l['mode'] === 'ditolak' && str_contains(implode(' ', $l['errors']), '[Batal] Header tidak sesuai template')
            && str_contains(implode(' ', $l['errors']), 'kolom A'));
        $this->assertSame(0, KavlingKonsumen::count());

        // Import sungguhan pun tidak mungkin: file ditolak lebih dulu daripada pemeriksaan "Cek dulu".
        $this->kirim($file, 'import')->assertSessionHas('importLaporan', fn ($l) => $l['mode'] === 'ditolak');
        $this->assertSame(0, KavlingKonsumen::count());
    }

    public function test_kolom_kurang_atau_lebih_dilaporkan_beserta_jumlahnya(): void
    {
        $header = array_map(fn ($c) => $c[1], KonsumenImportSpec::batalColumns());
        $kurang = array_slice($header, 0, -2);
        $masalah = KonsumenImportSpec::validasiHeader($this->file([], $kurang)->getRealPath());
        $this->assertStringContainsString('Jumlah kolom file ' . count($kurang) . ', seharusnya ' . count($header), implode(' ', $masalah));

        $lebih = [...$header, 'Kolom Selundupan'];
        $masalah = KonsumenImportSpec::validasiHeader($this->file([], $lebih)->getRealPath());
        $this->assertStringContainsString('(tidak ada kolom)', implode(' ', $masalah));
    }

    public function test_file_tanpa_sheet_yang_dikenali_ditolak(): void
    {
        $masalah = KonsumenImportSpec::validasiHeader($this->file([$this->baris()], null, 'Sheet1')->getRealPath());
        $this->assertStringContainsString('Tidak ada sheet yang dikenali', implode(' ', $masalah));
    }

    // ── Cek dulu ──────────────────────────────────────────────────────────

    public function test_cek_dulu_menjalankan_importer_lalu_membatalkan_semuanya(): void
    {
        $file = $this->file([$this->baris(), $this->baris(['unit' => '2', 'nama' => 'Siti Aminah', 'nik' => '3201010101010077'])]);

        $r = $this->kirim($file, 'cek');
        $r->assertSessionHas('importLaporan', fn ($l) => $l['mode'] === 'cek' && $l['importedBatal'] === 2 && $l['skipped'] === 0);
        $this->assertSame(0, KavlingKonsumen::count());
        $this->assertSame(0, Konsumen::count());
    }

    public function test_cek_dulu_melaporkan_baris_yang_akan_dilewati_tanpa_menyimpan_apa_pun(): void
    {
        $file = $this->file([$this->baris(), $this->baris(['unit' => '2', 'tgl_batal' => '2026-01-01'])]);     // baris 2: batal sebelum booking

        $r = $this->kirim($file, 'cek');
        $r->assertSessionHas('importLaporan', fn ($l) => $l['importedBatal'] === 1 && $l['skipped'] === 1 && str_contains($l['errors'][0], 'Baris 3'));
        $this->assertSame(0, KavlingKonsumen::count());
    }

    public function test_import_tanpa_cek_dulu_ditolak(): void
    {
        $file = $this->file([$this->baris()]);

        $this->kirim($file, 'import')->assertSessionHas('error', fn ($m) => str_contains($m, 'Cek dulu'));
        $this->assertSame(0, KavlingKonsumen::count());
    }

    public function test_import_hanya_untuk_file_yang_sama_dengan_yang_dicek_dan_sekali_pakai(): void
    {
        $dicek = $this->file([$this->baris()]);
        $lain = $this->file([$this->baris(['nama' => 'Orang Lain', 'unit' => '2'])]);

        $this->kirim($dicek, 'cek');
        $this->kirim($lain, 'import')->assertSessionHas('error');                  // file berbeda dari yang dicek
        $this->assertSame(0, KavlingKonsumen::count());

        $this->kirim($dicek, 'import')->assertSessionHas('importLaporan', fn ($l) => $l['mode'] === 'import' && $l['importedBatal'] === 1);
        $this->assertSame(1, KavlingKonsumen::count());

        $this->kirim($dicek, 'import')->assertSessionHas('error');                  // izin cek sudah terpakai
        $this->assertSame(1, KavlingKonsumen::count());
    }

    // ── NIK ───────────────────────────────────────────────────────────────

    public function test_nik_milik_orang_lain_ditolak_dan_nama_mirip_tetap_dipakai_ulang(): void
    {
        Konsumen::create(['nama' => 'Dicky Pratama', 'nik' => '3374150701000001']);

        $file = $this->file([
            $this->baris(['nama' => 'Depi Kurniawan', 'nik' => '3374150701000001']),                              // nama beda jauh: ditolak
            $this->baris(['nama' => 'Dicky Pratama S.', 'nik' => '3374150701000001', 'unit' => '2']),             // nama mirip: konsumen yang sama
        ]);
        $this->kirim($file, 'cek');
        $r = $this->kirim($file, 'import');

        $r->assertSessionHas('importLaporan', fn ($l) => $l['importedBatal'] === 1 && $l['skipped'] === 1 && str_contains($l['errors'][0], 'sudah terdaftar atas nama "Dicky Pratama"'));
        $this->assertSame(1, KavlingKonsumen::count());
        $this->assertSame(0, Konsumen::where('nama', 'Depi Kurniawan')->count());
        $this->assertSame(1, Konsumen::where('nik', '3374150701000001')->count());                              // tidak dobel
    }

    public function test_nik_pembeli_sebelumnya_milik_orang_lain_juga_ditolak(): void
    {
        Konsumen::create(['nama' => 'Dicky Pratama', 'nik' => '3374150701000001']);

        $file = $this->file([$this->baris(['alih_nama' => 'Depi Kurniawan', 'alih_nik' => '3374150701000001'])]);
        $this->kirim($file, 'cek');
        $r = $this->kirim($file, 'import');

        $r->assertSessionHas('importLaporan', fn ($l) => $l['importedBatal'] === 0 && $l['skipped'] === 1 && str_contains($l['errors'][0], 'Pembeli Sebelumnya'));
        $this->assertSame(0, KavlingKonsumen::count());
    }
}
