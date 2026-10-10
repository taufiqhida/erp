<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mengganti nama Tipe Unit: unit yang memakai tipe itu ikut menampilkan nama baru (terhubung lewat id, bukan teks). */
class TipeUnitNamaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private TipeUnitPreset $tipe;
    private Kavling $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => 'Sakura', 'luas_tanah' => 72, 'luas_bangunan' => 36, 'is_active' => true]);
        $this->unit = Kavling::create([
            'project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id,
        ]);
    }

    private function ubah(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->put(route('tipe-unit.update', $this->tipe), array_merge([
            'nama' => 'Sakura Baru', 'luas_tanah' => 72, 'luas_bangunan' => 36, 'is_active' => 1,
        ], $data));
    }

    public function test_nama_tipe_bisa_diganti_dan_unit_yang_memakainya_ikut_menampilkan_nama_baru(): void
    {
        $this->ubah([])->assertSessionDoesntHaveErrors()->assertSessionHas('success');

        $this->assertSame('Sakura Baru', $this->tipe->fresh()->nama);
        $this->assertSame($this->tipe->id, $this->unit->fresh()->tipe_unit_preset_id);   // unit tetap terhubung ke tipe yang sama
        $this->assertSame('Sakura Baru', $this->unit->fresh()->tipeUnitPreset->nama);   // dan menampilkan nama baru
    }

    public function test_nama_tipe_tidak_boleh_sama_dengan_tipe_lain_di_proyek_yang_sama(): void
    {
        TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => 'Mawar', 'is_active' => true]);

        $this->ubah(['nama' => 'Mawar'])->assertSessionHasErrors('nama');
        $this->assertSame('Sakura', $this->tipe->fresh()->nama);
    }

    public function test_menyimpan_tanpa_mengubah_nama_tidak_dianggap_duplikat(): void
    {
        $this->ubah(['nama' => 'Sakura', 'luas_tanah' => 80])->assertSessionDoesntHaveErrors();
        $this->assertEquals(80, $this->tipe->fresh()->luas_tanah);
    }

    public function test_peran_tanpa_izin_edit_kavling_tidak_bisa_mengganti_nama_tipe(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);

        $this->ubah([], $sales)->assertStatus(403);
        $this->assertSame('Sakura', $this->tipe->fresh()->nama);
    }

    public function test_tipe_yang_belum_dipakai_dihapus_dan_yang_sudah_dipakai_hanya_dinonaktifkan(): void
    {
        $kosong = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => 'Kosong', 'is_active' => true]);

        $this->actingAs($this->admin)->delete(route('tipe-unit.destroy', $kosong))->assertSessionHas('success');
        $this->assertNull(TipeUnitPreset::find($kosong->id));

        $this->actingAs($this->admin)->delete(route('tipe-unit.destroy', $this->tipe))->assertSessionHas('success');
        $this->assertNotNull($this->tipe->fresh());           // masih ada karena dipakai unit
        $this->assertFalse($this->tipe->fresh()->is_active);  // hanya dinonaktifkan
        $this->assertSame($this->tipe->id, $this->unit->fresh()->tipe_unit_preset_id);
    }
}

