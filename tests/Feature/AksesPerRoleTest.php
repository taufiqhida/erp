<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Jalur masuk per role: Beranda, Proses Bangun, Stok Kavling, dan Dashboard (angka penjualan & keuangan). */
class AksesPerRoleTest extends TestCase
{
    use RefreshDatabase;

    private Project $tugas;
    private Project $lain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->tugas = Project::create(['nama' => 'Griya Tugas', 'kode' => 'GT', 'kota' => 'X', 'is_active' => true]);
        $this->lain = Project::create(['nama' => 'Proyek Lain', 'kode' => 'PL', 'kota' => 'X', 'is_active' => true]);
    }

    private function user(string $role, bool $ditugaskan = true): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($ditugaskan) $u->projects()->attach($this->tugas->id);

        return $u;
    }

    // ── Pelaksana Lapangan ───────────────────────────────────────────────

    public function test_pelaksana_lapangan_bisa_membuka_beranda_dan_hanya_melihat_proyek_yang_ditugaskan(): void
    {
        $this->actingAs($this->user('pelaksana_lapangan'))->get(route('beranda'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Projects/Index')
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $this->tugas->id));
    }

    public function test_pelaksana_lapangan_masuk_ke_proses_bangun_dan_proyek_aktif_ikut_terpasang(): void
    {
        $this->actingAs($this->user('pelaksana_lapangan'))->get(route('proses-bangun.index', $this->tugas))->assertOk();

        $this->assertSame($this->tugas->id, session('current_project_id'));
    }

    public function test_pelaksana_lapangan_tidak_bisa_membuka_stok_kavling_dashboard_atau_data_bisnis(): void
    {
        $u = $this->user('pelaksana_lapangan');

        $this->actingAs($u)->get(route('projects.show', $this->tugas))->assertForbidden();
        $this->actingAs($u)->get(route('dashboard'))->assertRedirect(route('beranda'));
        $this->actingAs($u)->get(route('konsumens.index'))->assertStatus(403);
        $this->actingAs($u)->get(route('keuangan.index'))->assertStatus(403);
    }

    public function test_pelaksana_lapangan_tidak_bisa_membuka_proyek_yang_tidak_ditugaskan(): void
    {
        $this->actingAs($this->user('pelaksana_lapangan'))->get(route('proses-bangun.index', $this->lain))->assertForbidden();
    }

    public function test_pelaksana_tanpa_penugasan_proyek_melihat_beranda_kosong(): void
    {
        $this->actingAs($this->user('pelaksana_lapangan', false))->get(route('beranda'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects.data', 0));
    }

    public function test_keluar_dari_mode_proyek_untuk_pelaksana_kembali_ke_beranda_bukan_dashboard(): void
    {
        $this->actingAs($this->user('pelaksana_lapangan'))->get(route('projects.clear-active'))->assertRedirect(route('beranda'));
    }

    // ── Dashboard: hanya peran yang melihat data konsumen/keuangan ───────

    public function test_dashboard_terbuka_untuk_peran_bisnis_dan_ditutup_untuk_peran_proyek(): void
    {
        foreach (['superadmin', 'manager', 'spv', 'leader', 'admin_sales', 'admin_pemberkasan', 'admin_keuangan'] as $role) {
            $this->actingAs($this->user($role))->get(route('dashboard'))->assertOk();
        }
        foreach (['pelaksana_lapangan', 'admin_proyek'] as $role) {
            $this->actingAs($this->user($role))->get(route('dashboard'))->assertRedirect(route('beranda'));
        }
    }

    // ── Admin Keuangan: bisa melihat (bukan mengubah) proyek & stok kavling ─

    public function test_admin_keuangan_bisa_melihat_stok_kavling_tetapi_tidak_mengubah_proyek(): void
    {
        $u = $this->user('admin_keuangan');

        $this->actingAs($u)->get(route('projects.show', $this->tugas))->assertOk();
        $this->actingAs($u)->get(route('projects.edit', $this->tugas))->assertForbidden();
    }
}
