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

/**
 * Tahap D: paginasi server-side seragam (20/50/100, diingat) + urutan Proses Bangun di server.
 */
class PaginasiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');

        $this->project = Project::create(['nama' => 'P', 'kode' => 'P1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36', 'is_active' => true]);

        $stages = StatusBangunStage::ordered()->get();
        $this->assertGreaterThanOrEqual(3, $stages->count(), 'tahap bangun bawaan migrasi harus ada');

        for ($i = 1; $i <= 45; $i++) {
            $stage = $stages[$i % $stages->count()];
            Kavling::create([
                'project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id,
                'status_bangun_stage_id' => $stage->id, 'status_bangun_persen' => $stage->is_default ? 0 : (($i * 7) % 101),
                'blok' => 'A', 'nomor_kavling' => (string) $i, 'harga' => 1, 'status_jual' => 'available',
            ]);
        }
    }

    /** Props halaman Inertia dari HTML penuh (akses langsung, tanpa header Inertia). */
    private function props(string $url): array
    {
        $html = $this->actingAs($this->admin)->get($url)->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    public function test_pilihan_per_halaman_diterima_dan_diingat(): void
    {
        $url = route('proses-bangun.index', $this->project, false);

        $this->assertSame(50, $this->props($url)['kavlings']['per_page']);            // bawaan
        $this->assertSame(20, $this->props($url . '?per_page=20')['kavlings']['per_page']);
        $this->assertSame(20, $this->props($url)['kavlings']['per_page']);            // diingat tanpa ?per_page
        $this->assertSame(20, $this->props($url . '?per_page=7')['kavlings']['per_page']); // nilai tak sah diabaikan: tetap pilihan terakhir (20)
        $this->assertSame(100, $this->props($url . '?per_page=100')['kavlings']['per_page']);
    }

    public function test_halaman_kedua_berisi_sisa_data(): void
    {
        $url = route('proses-bangun.index', $this->project, false);

        $hal2 = $this->props($url . '?per_page=20&page=3')['kavlings'];
        $this->assertSame(45, $hal2['total']);
        $this->assertCount(5, $hal2['data']);      // 45 = 20 + 20 + 5
        $this->assertSame(41, $hal2['from']);
    }

    public function test_urutan_progress_dihitung_di_server_lintas_halaman(): void
    {
        $url = route('proses-bangun.index', $this->project, false);

        foreach (['asc', 'desc'] as $arah) {
            $nilai = [];
            foreach ([1, 2, 3] as $halaman) {
                $data = $this->props($url . "?per_page=20&urut=progress&arah={$arah}&page={$halaman}")['kavlings']['data'];
                array_push($nilai, ...array_map(fn ($r) => (float) $r['progress_bangun'], $data));
            }

            $this->assertCount(45, $nilai);
            $diurut = $nilai;
            $arah === 'asc' ? sort($diurut) : rsort($diurut);
            $this->assertSame($diurut, $nilai, "progress harus terurut {$arah} di seluruh halaman");
        }
    }

    public function test_urutan_deadline_unit_tanpa_spk_dan_selesai_di_bawah(): void
    {
        $kontraktor = Kontraktor::create(['nama' => 'CV Uji', 'is_active' => true]);
        $spk = Spk::create([
            'project_id' => $this->project->id, 'kontraktor_id' => $kontraktor->id, 'nomor_spk' => 'SPK-1',
            'tanggal_terbit' => now()->subDays(10), 'tanggal_deadline' => now()->addDays(20), 'created_by' => $this->admin->id,
        ]);
        $spk2 = Spk::create([
            'project_id' => $this->project->id, 'kontraktor_id' => $kontraktor->id, 'nomor_spk' => 'SPK-2',
            'tanggal_terbit' => now()->subDays(5), 'tanggal_deadline' => now()->addDays(5), 'created_by' => $this->admin->id,
        ]);
        // Hanya pakai unit yang BELUM selesai (progress < 100) supaya aturan "selesai di bawah" tidak ikut campur.
        $belum = Kavling::where('project_id', $this->project->id)->where('status_bangun_persen', '<', 100)
            ->whereNotIn('status_bangun_stage_id', [StatusBangunStage::finalStage()->id])->orderBy('id')->take(2)->get();
        $spk->kavlings()->attach($belum[0]->id);   // deadline +20 hari
        $spk2->kavlings()->attach($belum[1]->id);  // deadline +5 hari (lebih mendesak)

        $data = $this->props(route('proses-bangun.index', $this->project, false) . '?urut=deadline')['kavlings']['data'];

        $this->assertSame($belum[1]->id, $data[0]['id']); // paling mendesak di atas
        $this->assertSame($belum[0]->id, $data[1]['id']);
        $this->assertNull($data[2]['spk_deadline_raw']);  // sisanya tanpa SPK
    }

    public function test_komponen_paginasi_ada_di_halaman_yang_diganti(): void
    {
        // Pengaman regresi: setiap halaman server-side memakai komponen bersama (bukan blok salinan).
        foreach (['Keuangan/Index', 'Keuangan/Pencairan', 'Konsumens/Index', 'CancellationRequests/Index', 'AuditTrail/Index', 'Projects/Show', 'ProsesBangun/Index', 'Roles/Index'] as $page) {
            $this->assertStringContainsString('<Pagination', file_get_contents(resource_path("js/Pages/{$page}.vue")), $page);
        }
    }

    public function test_penjualan_dan_stok_kavling_hanya_mengirim_data_mode_yang_dipakai(): void
    {
        foreach ([
            'penjualan' => route('penjualan.project', $this->project, false),
            'stok' => route('projects.show', $this->project, false),
        ] as $nama => $url) {
            // Tabel: satu halaman saja, tanpa daftar lengkap.
            $tabel = $this->props($url . '?tampilan=tabel&per_page=20');
            $this->assertSame([], $tabel['kavlings'], "$nama: mode tabel tidak boleh mengirim daftar lengkap");
            $this->assertCount(20, $tabel['kavlingsPage']['data'], $nama);
            $this->assertSame(45, $tabel['kavlingsPage']['total'], $nama);
            $this->assertSame('tabel', $tabel['tampilan']);

            // Siteplan: daftar lengkap untuk peta.
            $peta = $this->props($url);
            $this->assertCount(45, $peta['kavlings'], "$nama: mode siteplan mengirim semua unit");
            $this->assertSame('siteplan', $peta['tampilan']);
        }
    }

    public function test_filter_tabel_penjualan_di_server_dan_opsi_dari_proyek(): void
    {
        $url = route('penjualan.project', $this->project, false);

        $tabel = $this->props($url . '?tampilan=tabel&blok=A&per_page=50');
        $this->assertSame(45, $tabel['kavlingsPage']['total']);
        $this->assertSame(['A'], $tabel['filterOptions']['blok']);

        $tak = $this->props($url . '?tampilan=tabel&blok=Z');
        $this->assertSame(0, $tak['kavlingsPage']['total']);
        $this->assertSame(['A'], $tak['filterOptions']['blok']); // opsi tetap utuh walau hasil filter kosong
    }

    public function test_urutan_sort_dan_per_halaman_terbawa_di_tautan_halaman_berikutnya(): void
    {
        $url = route('proses-bangun.index', $this->project, false) . '?urut=progress&arah=desc&per_page=20';
        $links = $this->props($url)['kavlings']['links'];

        $halaman2 = collect($links)->firstWhere('label', '2');
        $this->assertStringContainsString('urut=progress', $halaman2['url']);
        $this->assertStringContainsString('arah=desc', $halaman2['url']);
        $this->assertStringContainsString('per_page=20', $halaman2['url']);

        // Dan halaman 2 benar-benar lanjutan urutan yang sama (bukan diurutkan ulang per halaman).
        $h1 = array_column($this->props($url)['kavlings']['data'], 'progress_bangun');
        $h2 = array_column($this->props($url . '&page=2')['kavlings']['data'], 'progress_bangun');
        $this->assertTrue((float) max($h2) <= (float) min($h1) + 0.0001, 'halaman 2 harus berisi nilai <= minimum halaman 1 (urut menurun)');
    }
}
