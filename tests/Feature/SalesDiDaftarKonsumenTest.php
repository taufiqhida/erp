<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\SalesAgent;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Sales/Agent di daftar Konsumen (mode Per Unit): tampil di sel Tgl Booking, bisa difilter, dan ikut ekspor Excel. */
class SalesDiDaftarKonsumenTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $user;
    private SalesAgent $andi;
    private SalesAgent $budi;
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->andi = SalesAgent::create(['nama' => 'Andi Sales', 'tipe' => 'sales', 'is_active' => true]);
        $this->budi = SalesAgent::create(['nama' => 'Budi Agen', 'tipe' => 'agen', 'is_active' => true]);

        foreach ([['1', 'Konsumen A', $this->andi->id], ['2', 'Konsumen B', $this->budi->id], ['3', 'Konsumen C', null]] as [$no, $nama, $salesId]) {
            $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => $no,
                'status_jual' => 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
            KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => $nama])->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
                'cara_bayar' => 'cash', 'tanggal_booking' => '2026-10-10', 'harga_deal' => 300000000, 'sales_agent_id' => $salesId]);
        }

        $this->user = User::factory()->create();
        $this->user->assignRole('spv');
        $this->user->projects()->attach($this->project->id);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        parent::tearDown();
    }

    private function props(array $query = []): array
    {
        $html = $this->actingAs($this->user)->withSession(['current_project_id' => $this->project->id])->get(route('konsumens.index', $query))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    public function test_daftar_memuat_nama_sales_dan_opsi_filter(): void
    {
        $p = $this->props();

        $nama = collect($p['rows']['data'])->pluck('sales_nama', 'konsumen_nama');
        $this->assertSame('Andi Sales', $nama['Konsumen A']);
        $this->assertSame('Budi Agen', $nama['Konsumen B']);
        $this->assertNull($nama['Konsumen C']);
        $this->assertEqualsCanonicalizing(['Andi Sales', 'Budi Agen'], collect($p['filterOptions']['sales'])->pluck('nama')->all());
    }

    public function test_filter_sales_hanya_menampilkan_konsumen_sales_itu(): void
    {
        $p = $this->props(['sales_agent_id' => $this->budi->id]);

        $this->assertSame(['Konsumen B'], collect($p['rows']['data'])->pluck('konsumen_nama')->all());
        $this->assertSame((string) $this->budi->id, (string) $p['filters']['sales_agent_id']);
    }

    public function test_ekspor_excel_memuat_kolom_sales_dan_mengikuti_filter(): void
    {
        $r = $this->actingAs($this->user)->withSession(['current_project_id' => $this->project->id])
            ->get(route('konsumens.export', ['sales_agent_id' => $this->andi->id]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp') . '.xlsx';
        $this->tmp[] = $path;
        file_put_contents($path, $r->streamedContent());

        $baris = IOFactory::load($path)->getActiveSheet()->toArray();
        $this->assertContains('Sales / Agent', $baris[0]);
        $kolom = array_search('Sales / Agent', $baris[0], true);
        $this->assertCount(2, $baris);    // header + 1 baris (hanya konsumen milik Andi)
        $this->assertSame('Andi Sales', $baris[1][$kolom]);
        $this->assertSame('Konsumen A', $baris[1][0]);
    }
}
