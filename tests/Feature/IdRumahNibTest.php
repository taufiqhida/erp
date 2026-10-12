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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/** Siapa boleh mengisi ID Rumah (izin khusus untuk Admin Sales) dan NIB (kolom hgb_no) yang tetap hanya untuk pengubah data unit. */
class IdRumahNibTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Project $lain;
    private Kavling $unit;
    private Kavling $unitLain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->lain = Project::create(['nama' => 'Lain', 'kode' => 'L1', 'kota' => 'X', 'is_active' => true]);
        $this->unit = $this->buatUnit($this->project);
        $this->unitLain = $this->buatUnit($this->lain);
    }

    private function buatUnit(Project $p): Kavling
    {
        $tipe = TipeUnitPreset::create(['project_id' => $p->id, 'nama' => '36/72', 'is_active' => true]);

        return Kavling::create(['project_id' => $p->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
    }

    private function user(string $role, bool $ditugaskan = true): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($ditugaskan) $u->projects()->attach($this->project->id);

        return $u;
    }

    public function test_admin_sales_bisa_mengisi_id_rumah_tetapi_tidak_nib_atau_data_unit_lain(): void
    {
        $sales = $this->user('admin_sales');

        $this->actingAs($sales)->patch(route('kavlings.id-rumah', $this->unit), ['id_rumah' => 'DMK-001'])->assertSessionHas('success');
        $this->assertSame('DMK-001', $this->unit->fresh()->id_rumah);

        $this->actingAs($sales)->patch(route('kavlings.hgb-no', $this->unit), ['hgb_no' => '1234567890123'])->assertStatus(403);
        $this->actingAs($sales)->put(route('kavlings.update', $this->unit), ['nomor_kavling' => '1', 'blok' => 'A1', 'tipe_unit_preset_id' => $this->unit->tipe_unit_preset_id, 'harga' => 1])->assertStatus(403);
        $this->assertNull($this->unit->fresh()->hgb_no);
        $this->assertEquals(300000000, $this->unit->fresh()->harga);
    }

    public function test_izin_id_rumah_hanya_berlaku_di_proyek_yang_ditugaskan(): void
    {
        $this->actingAs($this->user('admin_sales'))->patch(route('kavlings.id-rumah', $this->unitLain), ['id_rumah' => 'X'])->assertStatus(403);
        $this->assertNull($this->unitLain->fresh()->id_rumah);
    }

    public function test_peran_lain_tanpa_izin_tetap_ditolak_dan_admin_proyek_tetap_bisa(): void
    {
        foreach (['spv', 'admin_pemberkasan', 'admin_keuangan', 'pelaksana_lapangan', 'direktur'] as $role) {
            $this->actingAs($this->user($role))->patch(route('kavlings.id-rumah', $this->unit), ['id_rumah' => 'X'])->assertStatus(403);
        }
        $this->assertNull($this->unit->fresh()->id_rumah);

        $proyek = $this->user('admin_proyek');
        $this->actingAs($proyek)->patch(route('kavlings.id-rumah', $this->unit), ['id_rumah' => 'DMK-002'])->assertSessionHas('success');
        $this->actingAs($proyek)->patch(route('kavlings.hgb-no', $this->unit), ['hgb_no' => '9999'])->assertSessionHas('success', 'NIB berhasil disimpan.');
        $this->assertSame('9999', $this->unit->fresh()->hgb_no);
    }

    public function test_id_rumah_tetap_unik(): void
    {
        $lain = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->unit->tipe_unit_preset_id, 'blok' => 'A1', 'nomor_kavling' => '2',
            'id_rumah' => 'DMK-777', 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        $this->actingAs($this->user('admin_sales'))->patch(route('kavlings.id-rumah', $this->unit), ['id_rumah' => 'DMK-777'])->assertSessionHasErrors('id_rumah');
        $this->assertNull($this->unit->fresh()->id_rumah);
    }

    public function test_tabel_menampilkan_label_nib_dan_membawa_izin_id_rumah_ke_admin_sales(): void
    {
        $sales = $this->user('admin_sales');
        $this->assertTrue($sales->can('isi id rumah'));
        $this->assertFalse($sales->can('edit kavlings'));
        $this->assertTrue($this->user('superadmin', false)->can('isi id rumah'));

        $html = $this->actingAs($sales)->get(route('projects.show', ['project' => $this->project, 'tampilan' => 'tabel']))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $this->assertContains('isi id rumah', json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props']['auth']['user']['permissions']);
    }

    public function test_impor_menerima_kolom_nib_dan_nama_lama_hgb_no(): void
    {
        foreach (['nib' => '1111111111111', 'hgb_no' => 'HGB-LAMA'] as $kolom => $nilai) {
            $project = Project::create(['nama' => "P {$kolom}", 'kode' => strtoupper(substr($kolom, 0, 2)) . '9', 'kota' => 'X', 'is_active' => true]);
            TipeUnitPreset::create(['project_id' => $project->id, 'nama' => '36/72', 'is_active' => true]);

            $book = new Spreadsheet();
            $sheet = $book->getActiveSheet()->setTitle('Kavling');
            $sheet->fromArray([['kluster', 'blok', 'nomor_kavling', 'tipe_unit', 'harga', $kolom], ['', 'B1', '1', '36/72', 250000000, $nilai]]);
            $path = tempnam(sys_get_temp_dir(), 'nib') . '.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

            $import = new KavlingImport($project);
            Excel::import($import, $path);
            @unlink($path);

            $this->assertSame([], $import->errors);
            $this->assertSame($nilai, Kavling::where('project_id', $project->id)->firstOrFail()->hgb_no, "kolom {$kolom}");
        }
    }

    public function test_placeholder_surat_nib_dan_hgb_no_berisi_nilai_yang_sama(): void
    {
        $this->unit->update(['hgb_no' => '1234567890123']);
        $konsumen = \App\Models\Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        $transaksi = \App\Models\KavlingKonsumen::create(['kavling_id' => $this->unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'tanggal_booking' => now()]);

        $p = $transaksi->fresh()->suratPlaceholders();
        $this->assertSame('1234567890123', $p['nib']);
        $this->assertSame('1234567890123', $p['hgb_no']);      // nama lama: template surat yang sudah ada tetap jalan
    }
}
