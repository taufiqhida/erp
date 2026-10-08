<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\Kontraktor;
use App\Models\Project;
use App\Models\Spk;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Proses Bangun → Update Proses (massal): set tahap + persen yang sama untuk banyak unit sekaligus. */
class UpdateProsesMassalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private Project $lain;
    private StatusBangunStage $awal;
    private StatusBangunStage $atap;
    private StatusBangunStage $akhir;
    private array $unit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');

        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->lain = Project::create(['nama' => 'Lain', 'kode' => 'L1', 'kota' => 'X', 'is_active' => true]);

        $this->awal = StatusBangunStage::defaultStage();
        $this->atap = StatusBangunStage::where('nama', 'Atap')->firstOrFail();
        $this->akhir = StatusBangunStage::finalStage();

        foreach (['A1' => 3, 'A2' => 2] as $blok => $jumlah) {
            for ($i = 1; $i <= $jumlah; $i++) {
                $this->unit["{$blok}-{$i}"] = $this->buatUnit($this->project, $blok, (string) $i);
            }
        }
    }

    private function buatUnit(Project $p, string $blok, string $nomor): Kavling
    {
        $tipe = TipeUnitPreset::firstOrCreate(['project_id' => $p->id, 'nama' => '36/72'], ['is_active' => true]);

        return Kavling::create([
            'project_id' => $p->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => $blok, 'nomor_kavling' => $nomor,
            'status_bangun_stage_id' => $this->awal->id, 'status_bangun_persen' => 0, 'harga' => 300000000,
        ]);
    }

    private function ids(string ...$kunci): array
    {
        return array_map(fn ($k) => $this->unit[$k]->id, $kunci);
    }

    private function massal(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('proses-bangun.massal', $this->project), $data);
    }

    public function test_semua_unit_terpilih_diubah_ke_tahap_dan_persen_yang_sama(): void
    {
        $this->massal(['kavling_ids' => $this->ids('A1-1', 'A1-2', 'A1-3'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 40])
            ->assertSessionHas('success')->assertSessionDoesntHaveErrors();

        foreach (['A1-1', 'A1-2', 'A1-3'] as $k) {
            $u = $this->unit[$k]->fresh();
            $this->assertSame($this->atap->id, $u->status_bangun_stage_id);
            $this->assertEquals(40, $u->status_bangun_persen);
        }
        // Unit yang tidak dipilih tidak tersentuh.
        $this->assertSame($this->awal->id, $this->unit['A2-1']->fresh()->status_bangun_stage_id);
        $this->assertEquals(StatusBangunStage::progressFor($this->atap->id, 40), $this->unit['A1-1']->fresh()->progress_bangun);
    }

    public function test_tahap_awal_selalu_nol_persen(): void
    {
        $this->unit['A1-1']->update(['status_bangun_stage_id' => $this->atap->id, 'status_bangun_persen' => 70]);

        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->awal->id, 'persen' => 55])->assertSessionDoesntHaveErrors();

        $this->assertEquals(0, $this->unit['A1-1']->fresh()->status_bangun_persen);
    }

    public function test_persen_wajib_dan_dibatasi_untuk_tahap_selain_awal(): void
    {
        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->atap->id])->assertSessionHasErrors('persen');
        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 150])->assertSessionHasErrors('persen');
        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => -1])->assertSessionHasErrors('persen');
        $this->assertSame($this->awal->id, $this->unit['A1-1']->fresh()->status_bangun_stage_id);
    }

    public function test_unit_selesai_dilewati_secara_bawaan_dan_bisa_ikut_diubah_bila_diminta(): void
    {
        $this->unit['A1-1']->update(['status_bangun_stage_id' => $this->akhir->id, 'status_bangun_persen' => 100]);

        $r = $this->massal(['kavling_ids' => $this->ids('A1-1', 'A1-2'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 30]);
        $r->assertSessionHas('success', fn ($m) => str_contains($m, '1 unit diubah') && str_contains($m, '1 unit selesai dilewati'));
        $this->assertSame($this->akhir->id, $this->unit['A1-1']->fresh()->status_bangun_stage_id);
        $this->assertSame($this->atap->id, $this->unit['A1-2']->fresh()->status_bangun_stage_id);

        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 30, 'lewati_selesai' => false]);
        $this->assertSame($this->atap->id, $this->unit['A1-1']->fresh()->status_bangun_stage_id);
    }

    public function test_unit_yang_sudah_sama_dihitung_terpisah_dan_tidak_diubah(): void
    {
        $this->unit['A1-1']->update(['status_bangun_stage_id' => $this->atap->id, 'status_bangun_persen' => 40]);

        $this->massal(['kavling_ids' => $this->ids('A1-1', 'A1-2'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 40])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 unit diubah') && str_contains($m, '1 unit sudah sama'));
    }

    public function test_unit_dari_proyek_lain_menggagalkan_semuanya(): void
    {
        $asing = $this->buatUnit($this->lain, 'Z', '1');

        $this->massal(['kavling_ids' => [...$this->ids('A1-1'), $asing->id], 'status_bangun_stage_id' => $this->atap->id, 'persen' => 40])->assertStatus(422);

        $this->assertSame($this->awal->id, $this->unit['A1-1']->fresh()->status_bangun_stage_id);
        $this->assertSame($this->awal->id, $asing->fresh()->status_bangun_stage_id);
    }

    public function test_batas_200_unit_dan_pilihan_kosong_ditolak(): void
    {
        $this->massal(['kavling_ids' => [], 'status_bangun_stage_id' => $this->atap->id, 'persen' => 10])->assertSessionHasErrors('kavling_ids');
        $this->massal(['kavling_ids' => range(1, 201), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 10])->assertSessionHasErrors('kavling_ids');
    }

    public function test_catatan_unit_tidak_disentuh(): void
    {
        $this->unit['A1-1']->update(['catatan' => 'Tunggu material']);

        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 10, 'catatan' => 'abaikan']);

        $this->assertSame('Tunggu material', $this->unit['A1-1']->fresh()->catatan);
    }

    public function test_hanya_yang_berizin_dan_punya_akses_proyek_boleh(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);
        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 10], $sales);
        $this->assertSame($this->awal->id, $this->unit['A1-1']->fresh()->status_bangun_stage_id);

        $lapangan = User::factory()->create();
        $lapangan->assignRole('pelaksana_lapangan');
        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 10], $lapangan);
        $this->assertSame($this->awal->id, $this->unit['A1-1']->fresh()->status_bangun_stage_id); // belum ditugaskan ke proyek

        $lapangan->projects()->attach($this->project->id);
        $this->massal(['kavling_ids' => $this->ids('A1-1'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 10], $lapangan)->assertSessionHas('success');
        $this->assertSame($this->atap->id, $this->unit['A1-1']->fresh()->status_bangun_stage_id);
    }

    public function test_riwayat_per_unit_dan_ringkasan_massal_tercatat(): void
    {
        $this->massal(['kavling_ids' => $this->ids('A1-1', 'A1-2'), 'status_bangun_stage_id' => $this->atap->id, 'persen' => 25]);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'proses_bangun', 'causer_id' => $this->admin->id]);
        $this->assertDatabaseHas('activity_log', ['subject_type' => Kavling::class, 'subject_id' => $this->unit['A1-1']->id]);
    }

    public function test_daftar_unit_modal_dimuat_sesuai_permintaan_dan_memuat_spk_serta_status_selesai(): void
    {
        $kontraktor = Kontraktor::create(['nama' => 'CV Jaya', 'is_active' => true]);
        $spk = Spk::create(['project_id' => $this->project->id, 'kontraktor_id' => $kontraktor->id, 'nomor_spk' => 'SPK-001',
            'tanggal_terbit' => now(), 'tanggal_deadline' => now()->addMonths(2), 'created_by' => $this->admin->id]);
        $spk->kavlings()->attach($this->ids('A1-1', 'A1-2'));
        $this->unit['A1-3']->update(['status_bangun_stage_id' => $this->akhir->id, 'status_bangun_persen' => 100]);

        // Muatan biasa: tidak memuat daftar unit (hemat); muatan sebagian (partial reload) memuatnya.
        $this->actingAs($this->admin)->get(route('proses-bangun.index', $this->project))
            ->assertInertia(fn ($page) => $page->missing('unitMassal'));

        $this->actingAs($this->admin)->get(route('proses-bangun.index', $this->project), [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'ProsesBangun/Index', 'X-Inertia-Partial-Data' => 'unitMassal',
            'X-Inertia-Version' => \Inertia\Inertia::getVersion(),
        ])->assertOk()->assertJsonCount(5, 'props.unitMassal')
            ->assertJsonPath('props.unitMassal.0.spk_ids', [$spk->id])
            ->assertJsonPath('props.unitMassal.4.id', $this->unit['A2-2']->id);
    }
}
