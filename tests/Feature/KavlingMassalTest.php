<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Edit massal unit: ganti harga (angka/persen/nominal) dan ganti Tipe Unit, dengan pratinjau & pengaman status transaksi. */
class KavlingMassalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private Project $lain;
    private TipeUnitPreset $tipeA;
    private TipeUnitPreset $tipeB;
    /** @var Kavling[] */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->lain = Project::create(['nama' => 'Lain', 'kode' => 'L1', 'kota' => 'X', 'is_active' => true]);
        $this->tipeA = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'luas_tanah' => 72, 'luas_bangunan' => 36, 'is_active' => true]);
        $this->tipeB = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '45/90', 'luas_tanah' => 90, 'luas_bangunan' => 45, 'is_active' => true]);

        // 1–2: tersedia (A1), 3: tidak tersedia (A1), 4: dipesan (A1), 5: terjual (A2), 6: tersedia tanpa harga (A2)
        $this->u[1] = $this->unit('A1', '1', 'available', 300000000);
        $this->u[2] = $this->unit('A1', '2', 'available', 310000000);
        $this->u[3] = $this->unit('A1', '3', 'hold', 320000000);
        $this->u[4] = $this->unit('A1', '4', 'booked', 330000000);
        $this->u[5] = $this->unit('A2', '5', 'sold', 340000000);
        $this->u[6] = $this->unit('A2', '6', 'available', null);
    }

    private function unit(string $blok, string $nomor, string $status, ?int $harga): Kavling
    {
        return Kavling::create([
            'project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipeA->id, 'blok' => $blok, 'nomor_kavling' => $nomor,
            'status_jual' => $status, 'harga' => $harga, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id,
        ]);
    }

    private function ids(): array
    {
        return array_map(fn ($k) => $k->id, $this->u);
    }

    private function kirim(array $data, ?User $user = null, ?Project $project = null)
    {
        return $this->actingAs($user ?? $this->admin)->postJson(route('kavlings.massal', $project ?? $this->project), $data);
    }

    private function harga(int $n): ?float
    {
        $h = $this->u[$n]->fresh()->harga;

        return $h === null ? null : (float) $h;
    }

    public function test_ganti_harga_angka_baru_hanya_untuk_unit_yang_belum_berproses_transaksi(): void
    {
        $this->actingAs($this->admin)->post(route('kavlings.massal', $this->project), [
            'aksi' => 'harga', 'mode' => 'set', 'nilai' => 350000000, 'kavling_ids' => $this->ids(),
        ])->assertSessionHas('success');

        foreach ([1, 2, 3, 6] as $n) $this->assertSame(350000000.0, $this->harga($n), "unit $n");
        $this->assertSame(330000000.0, $this->harga(4));   // dipesan: tidak disentuh
        $this->assertSame(340000000.0, $this->harga(5));   // terjual: tidak disentuh
    }

    public function test_pratinjau_menghitung_tanpa_menyimpan_dan_menjelaskan_unit_yang_dilewati(): void
    {
        $r = $this->kirim(['aksi' => 'harga', 'mode' => 'set', 'nilai' => 350000000, 'kavling_ids' => $this->ids(), 'simulasi' => true])->assertOk();

        $r->assertJsonPath('ringkas', ['total' => 6, 'ubah' => 4, 'lewati' => 2, 'sama' => 0]);
        $this->assertSame(300000000.0, $this->harga(1));   // tidak ada yang tersimpan
        $baris = collect($r->json('baris'));
        $this->assertSame('Sudah berproses transaksi', $baris->firstWhere('id', $this->u[4]->id)['alasan']);
        $this->assertEquals(300000000, $baris->firstWhere('id', $this->u[1]->id)['lama']);
        $this->assertEquals(350000000, $baris->firstWhere('id', $this->u[1]->id)['baru']);
    }

    public function test_naik_turun_persen_dibulatkan_ke_rupiah_dan_unit_tanpa_harga_dilewati(): void
    {
        $this->kirim(['aksi' => 'harga', 'mode' => 'persen', 'nilai' => 5, 'kavling_ids' => $this->ids()])->assertRedirect();
        $this->assertSame(315000000.0, $this->harga(1));
        $this->assertSame(325500000.0, $this->harga(2));
        $this->assertNull($this->harga(6));                // belum punya harga: tidak dikarang

        $this->kirim(['aksi' => 'harga', 'mode' => 'persen', 'nilai' => -10, 'kavling_ids' => [$this->u[1]->id]])->assertRedirect();
        $this->assertSame(283500000.0, $this->harga(1));
    }

    public function test_naik_turun_nominal_tidak_pernah_menghasilkan_harga_negatif(): void
    {
        $this->kirim(['aksi' => 'harga', 'mode' => 'nominal', 'nilai' => 10000000, 'kavling_ids' => [$this->u[1]->id]])->assertRedirect();
        $this->assertSame(310000000.0, $this->harga(1));

        $this->kirim(['aksi' => 'harga', 'mode' => 'nominal', 'nilai' => -999000000, 'kavling_ids' => [$this->u[1]->id]])->assertRedirect();
        $this->assertSame(0.0, $this->harga(1));
    }

    public function test_ganti_tipe_mengganti_tipe_dan_dengan_itu_luasnya(): void
    {
        $this->kirim(['aksi' => 'tipe', 'tipe_unit_preset_id' => $this->tipeB->id, 'kavling_ids' => $this->ids()])->assertRedirect();

        $this->assertSame($this->tipeB->id, $this->u[1]->fresh()->tipe_unit_preset_id);
        $this->assertSame(90.0, (float) $this->u[1]->fresh()->tipeUnitPreset->luas_tanah);
        $this->assertSame($this->tipeA->id, $this->u[4]->fresh()->tipe_unit_preset_id);   // dipesan tetap
        $this->assertSame($this->tipeA->id, $this->u[5]->fresh()->tipe_unit_preset_id);   // terjual tetap
    }

    public function test_tipe_yang_sudah_sama_dihitung_sama_dan_tipe_proyek_lain_ditolak(): void
    {
        $this->kirim(['aksi' => 'tipe', 'tipe_unit_preset_id' => $this->tipeA->id, 'kavling_ids' => [$this->u[1]->id], 'simulasi' => true])
            ->assertOk()->assertJsonPath('ringkas.sama', 1);

        $asing = TipeUnitPreset::create(['project_id' => $this->lain->id, 'nama' => 'Asing', 'is_active' => true]);
        $this->kirim(['aksi' => 'tipe', 'tipe_unit_preset_id' => $asing->id, 'kavling_ids' => [$this->u[1]->id]])->assertStatus(422);
        $nonaktif = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => 'Mati', 'is_active' => false]);
        $this->kirim(['aksi' => 'tipe', 'tipe_unit_preset_id' => $nonaktif->id, 'kavling_ids' => [$this->u[1]->id]])->assertStatus(422);
        $this->assertSame($this->tipeA->id, $this->u[1]->fresh()->tipe_unit_preset_id);
    }

    public function test_pilih_semua_hasil_filter_memakai_filter_yang_sama_dengan_tabel(): void
    {
        $this->kirim(['aksi' => 'harga', 'mode' => 'set', 'nilai' => 1000000000, 'semua_hasil_filter' => true, 'filter' => ['blok' => 'A1']])->assertRedirect();

        foreach ([1, 2, 3] as $n) $this->assertSame(1000000000.0, $this->harga($n), "unit $n");
        $this->assertSame(330000000.0, $this->harga(4));
        $this->assertNull($this->harga(6));                // blok A2: di luar filter
    }

    public function test_unit_proyek_lain_ditolak(): void
    {
        $asing = Kavling::create(['project_id' => $this->lain->id, 'tipe_unit_preset_id' => TipeUnitPreset::create(['project_id' => $this->lain->id, 'nama' => 'X', 'is_active' => true])->id,
            'blok' => 'B', 'nomor_kavling' => '1', 'harga' => 100, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        $this->kirim(['aksi' => 'harga', 'mode' => 'set', 'nilai' => 1, 'kavling_ids' => [$this->u[1]->id, $asing->id]])->assertStatus(422);
        $this->assertSame(100.0, (float) $asing->fresh()->harga);
        $this->assertSame(300000000.0, $this->harga(1));
    }

    public function test_input_tidak_valid_menghasilkan_422_json(): void
    {
        $this->kirim(['aksi' => 'harga', 'kavling_ids' => $this->ids(), 'simulasi' => true])->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $this->kirim(['aksi' => 'harga', 'mode' => 'set', 'nilai' => -5, 'kavling_ids' => $this->ids()])->assertStatus(422);
        $this->kirim(['aksi' => 'harga', 'mode' => 'set', 'nilai' => 1])->assertStatus(422);                                   // tanpa unit
        $this->kirim(['aksi' => 'rahasia', 'kavling_ids' => $this->ids()])->assertStatus(422);
        $this->assertSame(300000000.0, $this->harga(1));
    }

    public function test_hanya_yang_boleh_mengedit_kavling_dan_hanya_di_proyeknya(): void
    {
        $data = ['aksi' => 'harga', 'mode' => 'set', 'nilai' => 1, 'kavling_ids' => [$this->u[1]->id]];

        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);
        $this->kirim($data, $sales)->assertStatus(403);                       // tanpa izin edit kavling

        $proyek = User::factory()->create();
        $proyek->assignRole('admin_proyek');
        $proyek->projects()->attach($this->project->id);
        $this->kirim($data, $proyek)->assertRedirect();                             // Admin Proyek di proyeknya
        $this->kirim($data, $proyek, $this->lain)->assertStatus(403);         // bukan proyeknya
    }

    public function test_perubahan_masuk_audit_trail(): void
    {
        $this->kirim(['aksi' => 'harga', 'mode' => 'set', 'nilai' => 350000000, 'kavling_ids' => [$this->u[1]->id, $this->u[2]->id]])->assertRedirect();

        $this->assertSame(1, Activity::where('log_name', 'kavling')->where('description', 'like', 'Edit massal harga%')->count());
        $this->assertTrue(Activity::where('subject_type', Kavling::class)->where('subject_id', $this->u[1]->id)->where('description', 'like', '%updated%')->exists());
    }
}
