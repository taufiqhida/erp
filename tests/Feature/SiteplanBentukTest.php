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

/** Bentuk (poligon) kavling di siteplan: simpan/hapus, validasi, izin, dan ikut terkirim ke halaman. */
class SiteplanBentukTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private Project $lain;
    private Kavling $unit;
    private Kavling $unitLain;

    private const KOTAK = [[10, 10], [20, 10], [20, 18], [10, 18]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->lain = Project::create(['nama' => 'Lain', 'kode' => 'L1', 'kota' => 'X', 'is_active' => true]);
        $this->unit = $this->buatUnit($this->project, '1');
        $this->unitLain = $this->buatUnit($this->lain, '1');
    }

    private function buatUnit(Project $p, string $nomor): Kavling
    {
        $tipe = TipeUnitPreset::firstOrCreate(['project_id' => $p->id, 'nama' => '36/72'], ['is_active' => true]);

        return Kavling::create([
            'project_id' => $p->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => $nomor,
            'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id,
        ]);
    }

    private function simpan(Kavling $unit, mixed $bentuk, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->patchJson(route('kavlings.bentuk', $unit), ['bentuk' => $bentuk]);
    }

    public function test_bentuk_tersimpan_dalam_persen_dan_dikembalikan_sebagai_json(): void
    {
        $this->simpan($this->unit, [[10.123, 10], [20, 10.456], [20, 18], [10, 18]])
            ->assertOk()->assertJson(['ok' => true, 'id' => $this->unit->id, 'bentuk' => [[10.12, 10], [20, 10.46], [20, 18], [10, 18]]]);

        $this->assertSame([[10.12, 10], [20, 10.46], [20, 18], [10, 18]], $this->unit->fresh()->bentuk);
    }

    public function test_bentuk_bisa_dihapus_dan_penyimpanan_tidak_masuk_audit_trail(): void
    {
        $this->simpan($this->unit, self::KOTAK)->assertOk();
        $this->simpan($this->unit, null)->assertOk();

        $this->assertNull($this->unit->fresh()->bentuk);
        $this->assertDatabaseMissing('activity_log', ['subject_type' => Kavling::class, 'subject_id' => $this->unit->id, 'description' => 'created']);
        $this->assertSame(0, \Spatie\Activitylog\Models\Activity::where('subject_type', Kavling::class)->where('subject_id', $this->unit->id)
            ->where('properties', 'like', '%bentuk%')->count());
    }

    public function test_bentuk_tidak_valid_ditolak(): void
    {
        $this->simpan($this->unit, [[1, 1], [2, 2]])->assertStatus(422);                                // kurang dari 3 titik
        $this->simpan($this->unit, [[1, 1], [2, 2], [3, 150]])->assertStatus(422);                        // di luar 0–100
        $this->simpan($this->unit, [[1, 1], [2, 2], [3]])->assertStatus(422);                             // titik tidak berpasangan
        $this->simpan($this->unit, [['a', 1], [2, 2], [3, 3]])->assertStatus(422);                        // bukan angka
        $this->simpan($this->unit, [[5, 5], [10, 10], [15, 15]])->assertStatus(422);                      // segaris: tanpa luas
        $this->simpan($this->unit, array_fill(0, 401, [1, 1]))->assertStatus(422);                        // terlalu banyak titik
        $this->assertNull($this->unit->fresh()->bentuk);
    }

    public function test_hanya_yang_boleh_mengedit_kavling_dan_hanya_di_proyeknya(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);
        $this->simpan($this->unit, self::KOTAK, $sales)->assertStatus(403);                // tanpa izin edit kavling

        $proyek = User::factory()->create();
        $proyek->assignRole('admin_proyek');
        $proyek->projects()->attach($this->project->id);
        $this->simpan($this->unit, self::KOTAK, $proyek)->assertOk();                      // Admin Proyek di proyeknya
        $this->simpan($this->unitLain, self::KOTAK, $proyek)->assertStatus(403);           // bukan proyeknya

        $this->assertNull($this->unitLain->fresh()->bentuk);
    }

    public function test_bentuk_ikut_terkirim_ke_halaman_stok_kavling_dan_pemesanan(): void
    {
        $this->simpan($this->unit, self::KOTAK)->assertOk();

        $this->actingAs($this->admin)->get(route('projects.show', $this->project))
            ->assertInertia(fn ($page) => $page->where('kavlings.0.bentuk', self::KOTAK));

        $this->actingAs($this->admin)->get(route('penjualan.project', $this->project))
            ->assertInertia(fn ($page) => $page->where('kavlings.0.bentuk', self::KOTAK));
    }
}
