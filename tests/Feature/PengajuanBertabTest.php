<?php

namespace Tests\Feature;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Models\CancellationRequest;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman Pengajuan bertab: Pembatalan, Alih Pembeli, Tukar Unit, Rincian & Promo. Bawaan menampilkan yang Menunggu; tiap tab membawa
 * angka yang menunggu; angka total juga muncul di menu (hanya untuk peninjau).
 */
class PengajuanBertabTest extends TestCase
{
    use RefreshDatabase;

    private Project $a;
    private Project $b;
    private User $spv;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = Project::create(['nama' => 'Proyek A', 'kode' => 'PA', 'kota' => 'X', 'is_active' => true]);
        $this->b = Project::create(['nama' => 'Proyek B', 'kode' => 'PB', 'kota' => 'X', 'is_active' => true]);
        $this->spv = $this->user('spv', [$this->a]);
    }

    private function user(string $role, array $projects): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        foreach ($projects as $p) $u->projects()->attach($p->id);

        return $u;
    }

    private function pengajuan(CancellationRequestType $tipe, CancellationStatus $status = CancellationStatus::Pending, ?Project $project = null, array $payload = []): CancellationRequest
    {
        $this->n++;
        $project ??= $this->a;
        $tipeUnit = TipeUnitPreset::firstOrCreate(['project_id' => $project->id, 'nama' => '36/72'], ['is_active' => true]);
        $unit = Kavling::create(['project_id' => $project->id, 'tipe_unit_preset_id' => $tipeUnit->id, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id,
            'blok' => 'A1', 'nomor_kavling' => (string) $this->n, 'harga' => 100000000, 'status_jual' => 'booked']);
        $konsumen = Konsumen::create(['nama' => 'Konsumen ' . $this->n, 'no_hp' => '0812']);
        $kk = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
            'cara_bayar' => 'cash', 'tanggal_booking' => '2026-03-01', 'harga_dasar' => 100000000, 'harga_deal' => 100000000]);

        return CancellationRequest::create(['type' => $tipe, 'kavling_id' => $unit->id, 'kavling_konsumen_id' => $kk->id, 'konsumen_id' => $konsumen->id,
            'kavling_status_before' => 'booked', 'requested_by' => $this->user('admin_sales', [$project])->id, 'status' => $status, 'alasan' => 'alasan uji', 'payload' => $payload ?: null]);
    }

    private function props(array $query = [], ?User $user = null): array
    {
        $html = $this->actingAs($user ?? $this->spv)->get(route('cancellation-requests.index', $query))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    private function jenis(array $props): array
    {
        return collect($props['requests']['data'])->pluck('type')->unique()->values()->all();
    }

    public function test_tab_dan_angka_menunggu_per_tab(): void
    {
        $this->pengajuan(CancellationRequestType::Cancellation);
        $this->pengajuan(CancellationRequestType::Cancellation);
        $this->pengajuan(CancellationRequestType::UnitSwap);
        $this->pengajuan(CancellationRequestType::RincianChange);
        $this->pengajuan(CancellationRequestType::BuyerTransfer, CancellationStatus::Approved);          // tidak menunggu: tidak dihitung

        $tabs = collect($this->props()['tabs'])->pluck('menunggu', 'key')->all();

        $this->assertSame(['pembatalan' => 2, 'alih_pembeli' => 0, 'tukar_unit' => 1, 'rincian' => 1], $tabs);
        $this->assertSame(['Pembatalan', 'Alih Pembeli', 'Tukar Unit', 'Rincian & Promo'], collect($this->props()['tabs'])->pluck('label')->all());
    }

    public function test_bawaan_tab_pertama_yang_punya_pengajuan_menunggu_dan_status_menunggu(): void
    {
        $this->pengajuan(CancellationRequestType::UnitSwap);

        $p = $this->props();

        $this->assertSame('tukar_unit', $p['filters']['tab']);
        $this->assertSame('pending', $p['filters']['status']);
        $this->assertSame(['unit_swap'], $this->jenis($p));
    }

    public function test_tanpa_pengajuan_menunggu_bawaan_tab_pembatalan(): void
    {
        $this->assertSame('pembatalan', $this->props()['filters']['tab']);
    }

    public function test_tab_hanya_menampilkan_tipenya_dan_rincian_memuat_promo_booking(): void
    {
        $this->pengajuan(CancellationRequestType::Cancellation);
        $this->pengajuan(CancellationRequestType::RincianChange);
        $this->pengajuan(CancellationRequestType::RincianChange, payload: ['asal' => 'booking']);
        $this->pengajuan(CancellationRequestType::BuyerTransfer);

        $this->assertSame(['cancellation'], $this->jenis($this->props(['tab' => 'pembatalan'])));
        $this->assertSame(['buyer_transfer'], $this->jenis($this->props(['tab' => 'alih_pembeli'])));
        $rincian = $this->props(['tab' => 'rincian']);
        $this->assertSame(['rincian_change'], $this->jenis($rincian));
        $this->assertEqualsCanonicalizing(['rincian', 'booking'], collect($rincian['requests']['data'])->pluck('asal')->all());
    }

    public function test_filter_status_disetujui_ditolak_dan_semua(): void
    {
        $this->pengajuan(CancellationRequestType::Cancellation, CancellationStatus::Pending);
        $this->pengajuan(CancellationRequestType::Cancellation, CancellationStatus::Approved);
        $this->pengajuan(CancellationRequestType::Cancellation, CancellationStatus::Rejected);

        $hitung = fn (string $status) => count($this->props(['tab' => 'pembatalan', 'status' => $status])['requests']['data']);

        $this->assertSame(1, $hitung('pending'));
        $this->assertSame(1, $hitung('approved'));
        $this->assertSame(1, $hitung('rejected'));
        $this->assertSame(3, $hitung('semua'));
        $this->assertSame(1, count($this->props(['tab' => 'pembatalan'])['requests']['data']));          // tanpa parameter = Menunggu
        $this->assertSame(1, count($this->props(['tab' => 'pembatalan', 'status' => 'ngawur'])['requests']['data']));
    }

    public function test_tautan_lama_dengan_type_diarahkan_ke_tab_yang_sesuai(): void
    {
        $this->pengajuan(CancellationRequestType::Cancellation);
        $this->pengajuan(CancellationRequestType::BuyerTransfer);

        $p = $this->props(['type' => 'buyer_transfer']);

        $this->assertSame('alih_pembeli', $p['filters']['tab']);
        $this->assertSame(['buyer_transfer'], $this->jenis($p));
    }

    public function test_peninjau_hanya_melihat_pengajuan_proyeknya_dan_angka_menu_mengikutinya(): void
    {
        $this->pengajuan(CancellationRequestType::Cancellation, project: $this->a);
        $this->pengajuan(CancellationRequestType::Cancellation, project: $this->b);          // proyek lain: tidak terlihat oleh SPV proyek A

        $p = $this->props(['tab' => 'pembatalan']);

        $this->assertCount(1, $p['requests']['data']);
        $this->assertSame(1, collect($p['tabs'])->firstWhere('key', 'pembatalan')['menunggu']);
        $this->assertSame(1, $p['pengajuanMenunggu']);

        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');
        $this->assertSame(2, $this->props(['tab' => 'pembatalan'], $superadmin)['pengajuanMenunggu']);      // lintas proyek
    }

    public function test_penanda_menu_hanya_untuk_peninjau(): void
    {
        $this->pengajuan(CancellationRequestType::Cancellation);
        $sales = $this->user('admin_sales', [$this->a]);

        $this->assertSame(0, $this->props(['tab' => 'pembatalan'], $sales)['pengajuanMenunggu']);
        $this->assertSame(1, $this->props(['tab' => 'pembatalan'])['pengajuanMenunggu']);
    }
}
