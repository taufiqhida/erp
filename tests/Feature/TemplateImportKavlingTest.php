<?php

namespace Tests\Feature;

use App\Imports\KavlingImport;
use App\Models\Kavling;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Template import kavling: urutan kolom intuitif (kluster, blok, nomor, ... status_bangun, persen_tahap, ..., keterangan)
 * dan tetap bisa diimpor — importer membaca lewat NAMA header, bukan posisi.
 */
class TemplateImportKavlingTest extends TestCase
{
    use RefreshDatabase;

    private const URUTAN = ['kluster', 'blok', 'nomor_kavling', 'tipe_unit', 'harga', 'status', 'status_bangun', 'persen_tahap', 'id_rumah', 'hgb_no', 'keterangan'];

    public function test_template_berurutan_intuitif_dan_bisa_diimpor(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        $project = Project::create(['nama' => 'T', 'kode' => 'T1', 'kota' => 'X', 'is_active' => true]);
        TipeUnitPreset::create(['project_id' => $project->id, 'nama' => '36/72', 'is_active' => true]);
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 50, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        $stage2 = StatusBangunStage::where('is_default', false)->first()
            ?? StatusBangunStage::create(['nama' => 'Pondasi', 'bobot' => 50, 'urutan' => 2, 'warna' => 'blue', 'is_default' => false]);

        $response = $this->actingAs($user)->get(route('projects.kavling-template', $project));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        $sheet = IOFactory::load($path)->getSheetByName('Kavling');
        $headers = array_map(fn ($c) => $sheet->getCell($c . '1')->getValue(), range('A', 'K'));
        $this->assertSame(self::URUTAN, $headers);

        // Isi satu baris mengikuti urutan template (menimpa baris contoh), lalu impor.
        $isi = ['Melati', 'A1', '7', '36/72', 300000000, 'available', $stage2->nama, 40, '', 'HGB-777', 'catatan uji'];
        $sheet->fromArray([$isi], null, 'A2');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet->getParent()))->save($path);

        $import = new KavlingImport($project);
        Excel::import($import, $path);

        $this->assertSame([], $import->errors);
        $this->assertSame(1, $import->imported);
        $k = Kavling::where('project_id', $project->id)->firstOrFail();
        $this->assertSame('Melati', $k->kluster);
        $this->assertSame('A1', $k->blok);
        $this->assertSame('7', $k->nomor_kavling);
        $this->assertEquals(40, $k->status_bangun_persen);
        $this->assertSame($stage2->id, $k->status_bangun_stage_id);
        $this->assertSame('catatan uji', $k->keterangan);
        $this->assertSame('HGB-777', $k->hgb_no);

        @unlink($path);
    }
}
