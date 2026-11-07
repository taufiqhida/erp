<?php

namespace Tests\Feature;

use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Kontraktor;
use App\Models\Project;
use App\Models\Spk;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * SPK Unit: Buat SPK (satu unit hanya di satu SPK, Nilai SPK wajib), Edit SPK (tambah/keluarkan unit, tercatat), daftar SPK dengan kartu status,
 * pembatasan Nilai SPK, serta kartu ringkasan progres bangun di Proses Bangun.
 */
class SpkUnitTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Project $lain;
    private Kontraktor $kontraktor;
    private StatusBangunStage $awal;
    private StatusBangunStage $tengah;
    private StatusBangunStage $akhir;
    private User $admin;
    private User $adminProyek;
    private User $pelaksana;
    private array $unit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->lain = Project::create(['nama' => 'Lain', 'kode' => 'L1', 'kota' => 'X', 'is_active' => true]);
        $this->kontraktor = Kontraktor::create(['nama' => 'CV Uji', 'is_active' => true]);
        $this->awal = StatusBangunStage::defaultStage();
        $this->tengah = StatusBangunStage::where('nama', 'Atap')->firstOrFail();
        $this->akhir = StatusBangunStage::finalStage();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->adminProyek = $this->userRole('admin_proyek');
        $this->pelaksana = $this->userRole('pelaksana_lapangan');

        foreach (['A1-1', 'A1-2', 'A1-3', 'A1-4', 'A2-1', 'A2-2'] as $kode) {
            [$blok, $nomor] = explode('-', $kode);
            $this->unit[$kode] = $this->buatUnit($this->project, $blok, $nomor);
        }
    }

    private function userRole(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function buatUnit(Project $p, string $blok, string $nomor, array $o = []): Kavling
    {
        $tipe = TipeUnitPreset::firstOrCreate(['project_id' => $p->id, 'nama' => '36/72'], ['is_active' => true]);

        return Kavling::create($o + [
            'project_id' => $p->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => $blok, 'nomor_kavling' => $nomor,
            'status_bangun_stage_id' => $this->awal->id, 'status_bangun_persen' => 0, 'status_jual' => 'available', 'harga' => 166000000,
        ]);
    }

    private function ids(string ...$kode): array
    {
        return array_map(fn ($k) => $this->unit[$k]->id, $kode);
    }

    private function spk(string $nomor, array $unit, array $o = []): Spk
    {
        $spk = Spk::create($o + [
            'project_id' => $this->project->id, 'kontraktor_id' => $this->kontraktor->id, 'nomor_spk' => $nomor,
            'tanggal_terbit' => now()->subDays(10)->toDateString(), 'tanggal_deadline' => now()->addDays(30)->toDateString(),
            'nilai_spk' => 100000000, 'created_by' => $this->admin->id,
        ]);
        $spk->kavlings()->attach($this->ids(...$unit));

        return $spk;
    }

    private function selesai(string ...$kode): void
    {
        foreach ($kode as $k) {
            $this->unit[$k]->update(['status_bangun_stage_id' => $this->akhir->id, 'status_bangun_persen' => 100]);
        }
    }

    private function payload(array $o = []): array
    {
        return $o + [
            'nomor_spk' => '001/SPK/2026', 'kontraktor_id' => $this->kontraktor->id,
            'tanggal_terbit' => '2026-11-01', 'tanggal_deadline' => '2027-01-31', 'nilai_spk' => 450000000, 'catatan' => 'Borongan tahap awal',
            'kavling_ids' => $this->ids('A1-1', 'A1-2'),
        ];
    }

    private function props(string $url, ?User $user = null): array
    {
        $html = $this->actingAs($user ?? $this->admin)->get($url)->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    // ── Buat SPK ─────────────────────────────────────────────────────

    public function test_buat_spk_menyimpan_nilai_unit_dan_tercatat_di_audit_trail(): void
    {
        $this->actingAs($this->adminProyek)->post(route('spk.store', $this->project), $this->payload())
            ->assertRedirect(route('spk.index', $this->project))->assertSessionHas('success');

        $spk = Spk::where('nomor_spk', '001/SPK/2026')->firstOrFail();
        $this->assertEquals(450000000, $spk->nilai_spk);
        $this->assertEqualsCanonicalizing($this->ids('A1-1', 'A1-2'), $spk->kavlings()->pluck('kavlings.id')->all());
        $this->assertTrue(Activity::where('log_name', 'spk')->where('description', 'SPK diterbitkan')->exists());
    }

    public function test_nilai_spk_wajib_diisi_untuk_spk_baru(): void
    {
        $this->actingAs($this->adminProyek)->post(route('spk.store', $this->project), $this->payload(['nilai_spk' => '']))
            ->assertSessionHasErrors('nilai_spk');
        $this->actingAs($this->adminProyek)->post(route('spk.store', $this->project), $this->payload(['nilai_spk' => -5]))
            ->assertSessionHasErrors('nilai_spk');
        $this->assertSame(0, Spk::count());
    }

    public function test_buat_spk_menolak_unit_yang_sudah_punya_spk_dan_tidak_menimpa_spk_lama(): void
    {
        $lama = $this->spk('SPK-LAMA', ['A1-1']);

        $r = $this->actingAs($this->adminProyek)->post(route('spk.store', $this->project), $this->payload(['kavling_ids' => $this->ids('A1-1', 'A1-3')]));

        $r->assertSessionHasErrors('kavling_ids');
        $this->assertStringContainsString('SPK-LAMA', session('errors')->first('kavling_ids'));
        $this->assertSame(1, Spk::count());                                                   // SPK baru tidak terbentuk
        $this->assertSame([$this->unit['A1-1']->id], $lama->kavlings()->pluck('kavlings.id')->all());
    }

    public function test_nomor_spk_unik_per_proyek_tetapi_boleh_sama_di_proyek_lain(): void
    {
        $this->spk('SPK-001', ['A1-1']);

        $this->actingAs($this->adminProyek)->post(route('spk.store', $this->project), $this->payload(['nomor_spk' => 'SPK-001']))
            ->assertSessionHasErrors('nomor_spk');

        $unitLain = $this->buatUnit($this->lain, 'B1', '1');
        $this->actingAs($this->admin)->post(route('spk.store', $this->lain), $this->payload(['nomor_spk' => 'SPK-001', 'kavling_ids' => [$unitLain->id]]))
            ->assertSessionHasNoErrors();
    }

    public function test_unit_milik_proyek_lain_ditolak(): void
    {
        $unitLain = $this->buatUnit($this->lain, 'B1', '1');

        $this->actingAs($this->adminProyek)->post(route('spk.store', $this->project), $this->payload(['kavling_ids' => [$unitLain->id]]))
            ->assertSessionHasErrors('kavling_ids.0');
    }

    // ── Edit SPK ─────────────────────────────────────────────────────

    public function test_edit_spk_mengubah_data_menambah_dan_mengeluarkan_unit_dan_tercatat(): void
    {
        $spk = $this->spk('SPK-1', ['A1-1', 'A1-2']);

        $this->actingAs($this->adminProyek)->put(route('spk.update', [$this->project, $spk]), $this->payload([
            'nomor_spk' => 'SPK-1-REV', 'nilai_spk' => 300000000, 'catatan' => 'Revisi', 'kavling_ids' => $this->ids('A1-2', 'A1-3'),
        ]))->assertRedirect(route('spk.index', $this->project))->assertSessionHas('success');

        $spk->refresh();
        $this->assertSame('SPK-1-REV', $spk->nomor_spk);
        $this->assertEquals(300000000, $spk->nilai_spk);
        $this->assertEqualsCanonicalizing($this->ids('A1-2', 'A1-3'), $spk->kavlings()->pluck('kavlings.id')->all());
        $this->assertSame(0, $this->unit['A1-1']->fresh()->spks()->count());                    // A1-1 dikeluarkan -> tanpa SPK

        $log = Activity::where('log_name', 'spk')->where('description', 'SPK diubah')->firstOrFail();
        $this->assertCount(1, $log->properties['unit_dikeluarkan']);
        $this->assertCount(1, $log->properties['unit_ditambah']);
        $this->assertSame('SPK-1', $log->properties['lama']['nomor_spk']);
        $this->assertSame('SPK-1-REV', $log->properties['baru']['nomor_spk']);
    }

    public function test_unit_yang_dikeluarkan_bisa_dimasukkan_ke_spk_lain(): void
    {
        $a = $this->spk('SPK-A', ['A1-1', 'A1-2']);
        $b = $this->spk('SPK-B', ['A1-3']);

        $this->actingAs($this->adminProyek)->put(route('spk.update', [$this->project, $a]), $this->payload(['nomor_spk' => 'SPK-A', 'kavling_ids' => $this->ids('A1-1')]))->assertSessionHasNoErrors();
        $this->actingAs($this->adminProyek)->put(route('spk.update', [$this->project, $b]), $this->payload(['nomor_spk' => 'SPK-B', 'kavling_ids' => $this->ids('A1-3', 'A1-2')]))->assertSessionHasNoErrors();

        $this->assertSame('SPK-B', $this->unit['A1-2']->fresh()->spk_aktif->nomor_spk);
    }

    public function test_edit_menolak_unit_milik_spk_lain_dan_spk_tanpa_unit(): void
    {
        $a = $this->spk('SPK-A', ['A1-1']);
        $this->spk('SPK-B', ['A1-2']);

        $this->actingAs($this->adminProyek)->put(route('spk.update', [$this->project, $a]), $this->payload(['nomor_spk' => 'SPK-A', 'kavling_ids' => $this->ids('A1-1', 'A1-2')]))
            ->assertSessionHasErrors('kavling_ids');
        $this->actingAs($this->adminProyek)->put(route('spk.update', [$this->project, $a]), $this->payload(['nomor_spk' => 'SPK-A', 'kavling_ids' => []]))
            ->assertSessionHasErrors('kavling_ids');
        $this->assertSame([$this->unit['A1-1']->id], $a->kavlings()->pluck('kavlings.id')->all());   // tidak berubah
    }

    public function test_edit_spk_proyek_lain_tidak_ditemukan(): void
    {
        $spkLain = Spk::create([
            'project_id' => $this->lain->id, 'kontraktor_id' => $this->kontraktor->id, 'nomor_spk' => 'X-1',
            'tanggal_terbit' => '2026-10-01', 'tanggal_deadline' => '2026-12-01', 'nilai_spk' => 1, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('spk.edit', [$this->project, $spkLain]))->assertNotFound();
        $this->actingAs($this->admin)->put(route('spk.update', [$this->project, $spkLain]), $this->payload())->assertNotFound();
    }

    public function test_form_menandai_unit_yang_dipakai_spk_lain_dan_halaman_edit_memuat_unit_sendiri(): void
    {
        $a = $this->spk('SPK-A', ['A1-1']);
        $this->spk('SPK-B', ['A1-2']);

        $buat = collect($this->props(route('spk.create', $this->project))['kavlings'])->keyBy('id');
        $this->assertSame('SPK-A', $buat[$this->unit['A1-1']->id]['spk_lain_nomor']);
        $this->assertNull($buat[$this->unit['A1-3']->id]['spk_lain_nomor']);

        $edit = $this->props(route('spk.edit', [$this->project, $a]));
        $kav = collect($edit['kavlings'])->keyBy('id');
        $this->assertNull($kav[$this->unit['A1-1']->id]['spk_lain_nomor']);                      // unit sendiri tetap bisa dipilih
        $this->assertSame('SPK-B', $kav[$this->unit['A1-2']->id]['spk_lain_nomor']);
        $this->assertSame([$this->unit['A1-1']->id], $edit['spk']['kavling_ids']);
    }

    // ── Izin ─────────────────────────────────────────────────────────

    public function test_pelaksana_lapangan_tidak_bisa_membuat_atau_mengedit_spk(): void
    {
        $spk = $this->spk('SPK-A', ['A1-1']);

        $this->actingAs($this->pelaksana)->get(route('spk.create', $this->project))->assertForbidden();
        $this->actingAs($this->pelaksana)->post(route('spk.store', $this->project), $this->payload())->assertForbidden();
        $this->actingAs($this->pelaksana)->get(route('spk.edit', [$this->project, $spk]))->assertForbidden();
        $this->actingAs($this->pelaksana)->put(route('spk.update', [$this->project, $spk]), $this->payload())->assertForbidden();
        $this->assertSame(1, Spk::count());
    }

    public function test_nilai_spk_tidak_dikirim_ke_pelaksana_lapangan_tapi_ada_untuk_admin_proyek(): void
    {
        $this->spk('SPK-A', ['A1-1'], ['nilai_spk' => 123456789]);

        $p = $this->props(route('spk.index', $this->project), $this->pelaksana);
        $this->assertFalse($p['lihatNilai']);
        $this->assertFalse($p['canManage']);
        $this->assertArrayNotHasKey('nilai_spk', $p['rows']['data'][0]);
        $this->assertStringNotContainsString('123456789', json_encode($p));

        $a = $this->props(route('spk.index', $this->project), $this->adminProyek);
        $this->assertTrue($a['lihatNilai']);
        $this->assertEquals(123456789, $a['rows']['data'][0]['nilai_spk']);
    }

    // ── Daftar & kartu status ────────────────────────────────────────

    public function test_kartu_dan_filter_status_berjalan_lewat_deadline_dan_selesai(): void
    {
        $this->spk('A-BERJALAN', ['A1-1'], ['tanggal_deadline' => now()->addDays(20)->toDateString()]);
        $this->spk('B-TELAT', ['A1-2'], ['tanggal_deadline' => now()->subDays(4)->toDateString()]);
        $this->spk('C-SELESAI-TELAT', ['A1-3', 'A1-4'], ['tanggal_deadline' => now()->subDays(30)->toDateString()]);
        $this->selesai('A1-3', 'A1-4');

        $p = $this->props(route('spk.index', $this->project));
        $this->assertSame(['total' => 3, 'berjalan' => 2, 'lewat_deadline' => 1, 'selesai' => 1], $p['kartu']);
        $this->assertSame('berjalan', $p['filters']['status']);
        $this->assertSame(['B-TELAT', 'A-BERJALAN'], collect($p['rows']['data'])->pluck('nomor_spk')->all());   // bawaan: deadline terdekat/lewat di atas

        $telat = $this->props(route('spk.index', ['project' => $this->project->id] + ['status' => 'lewat_deadline']));
        $this->assertSame(['B-TELAT'], collect($telat['rows']['data'])->pluck('nomor_spk')->all());

        $selesai = $this->props(route('spk.index', ['project' => $this->project->id] + ['status' => 'selesai']))['rows']['data'];
        $this->assertSame(['C-SELESAI-TELAT'], collect($selesai)->pluck('nomor_spk')->all());
        $this->assertSame('selesai', $selesai[0]['deadline_status']);                       // SPK selesai tidak ditandai terlambat
        $this->assertFalse($selesai[0]['lewat_deadline']);

        $semua = $this->props(route('spk.index', ['project' => $this->project->id] + ['status' => 'semua']));
        $this->assertCount(3, $semua['rows']['data']);
    }

    public function test_baris_memuat_unit_catatan_dan_mendukung_sort_nilai_dan_cari(): void
    {
        $this->spk('SPK-KECIL', ['A1-1'], ['nilai_spk' => 50000000, 'catatan' => 'Pondasi dulu']);
        $this->spk('SPK-BESAR', ['A1-2', 'A1-3'], ['nilai_spk' => 900000000]);
        $this->spk('SPK-TANPA-NILAI', ['A1-4'], ['nilai_spk' => null]);

        $desc = $this->props(route('spk.index', ['project' => $this->project->id] + ['urut' => 'nilai', 'arah' => 'desc']))['rows']['data'];
        $this->assertSame(['SPK-BESAR', 'SPK-KECIL', 'SPK-TANPA-NILAI'], array_column($desc, 'nomor_spk'));   // tanpa nilai selalu di bawah
        $asc = $this->props(route('spk.index', ['project' => $this->project->id] + ['urut' => 'nilai', 'arah' => 'asc']))['rows']['data'];
        $this->assertSame('SPK-TANPA-NILAI', end($asc)['nomor_spk']);

        $besar = collect($desc)->firstWhere('nomor_spk', 'SPK-BESAR');
        $this->assertSame(2, $besar['jumlah_unit']);
        $this->assertCount(2, $besar['unit']);
        $this->assertSame('Pondasi dulu', collect($desc)->firstWhere('nomor_spk', 'SPK-KECIL')['catatan']);

        $cari = $this->props(route('spk.index', ['project' => $this->project->id] + ['search' => 'KECIL']))['rows']['data'];
        $this->assertSame(['SPK-KECIL'], array_column($cari, 'nomor_spk'));
    }

    public function test_pelaksana_tidak_bisa_mengurutkan_berdasarkan_nilai(): void
    {
        $this->spk('SPK-A', ['A1-1']);

        $p = $this->props(route('spk.index', ['project' => $this->project->id] + ['urut' => 'nilai']), $this->pelaksana);

        $this->assertNotSame('nilai', $p['filters']['urut']);
    }

    // ── Proses Bangun: kartu ringkasan & highlight ───────────────────

    public function test_ringkasan_progres_bangun_membagi_unit_selesai_menjadi_ready_stock_menunggu_bast_dan_sudah_bast(): void
    {
        $u = $this->unit;
        $u['A1-2']->update(['status_bangun_stage_id' => $this->tengah->id, 'status_bangun_persen' => 40]);        // progress
        $this->selesai('A1-3', 'A1-4', 'A2-1', 'A2-2');
        $u['A1-4']->update(['status_jual' => 'hold']);                                                           // hold tetap ready stock
        $extra = [];
        foreach (['bast' => 'sold', 'akad' => 'sold', 'pemberkasan' => 'booked'] as $tahap => $jual) {
            $extra[$tahap] = $this->buatUnit($this->project, 'C1', (string) (count($extra) + 1), ['status_bangun_stage_id' => $this->akhir->id, 'status_bangun_persen' => 100, 'status_jual' => $jual]);
            KavlingKonsumen::create([
                'kavling_id' => $extra[$tahap]->id, 'konsumen_id' => Konsumen::create(['nama' => "K {$tahap}"])->id, 'status' => 'active', 'status_penjualan' => $tahap,
                'cara_bayar' => 'cash', 'tanggal_booking' => '2026-10-01', 'harga_dasar' => 166000000, 'harga_deal' => 166000000,
            ]);
        }
        $this->buatUnit($this->project, 'D1', '1', ['status_bangun_stage_id' => $this->akhir->id, 'status_bangun_persen' => 50]);   // tahap akhir tapi 50% -> masih progress

        $r = $this->props(route('proses-bangun.index', $this->project))['ringkasan'];

        // Unit: A1-1 belum mulai; A1-2 progress; A1-3, A1-4, A2-1, A2-2 selesai tersedia/hold (ready stock); C1-1 sudah BAST; C1-2 akad; C1-3 dipesan; D1-1 progress.
        $this->assertSame(10, $r['total']);
        $this->assertSame(1, $r['belum_mulai']);
        $this->assertSame(2, $r['progress']);
        $this->assertSame(7, $r['selesai']);
        $this->assertSame(4, $r['ready_stock']);
        $this->assertSame(2, $r['menunggu_bast']);
        $this->assertSame(1, $r['menunggu_bast_akad']);
        $this->assertSame(1, $r['sudah_bast']);
        $this->assertSame($r['total'], array_sum(array_column($r['per_tahap'], 'jumlah')));
        $this->assertSame($r['belum_mulai'], collect($r['per_tahap'])->firstWhere('id', $this->awal->id)['jumlah']);

        $rata = Kavling::where('project_id', $this->project->id)->with('statusBangunStage')->get()->avg(fn ($k) => $k->progress_bangun);
        $this->assertEqualsWithDelta($rata, $r['rata_rata'], 0.06);
    }

    public function test_ringkasan_tidak_ikut_filter_tabel_dan_proyek_lain_tidak_tercampur(): void
    {
        $this->buatUnit($this->lain, 'Z1', '1', ['status_bangun_stage_id' => $this->akhir->id, 'status_bangun_persen' => 100]);

        $semua = $this->props(route('proses-bangun.index', $this->project))['ringkasan'];
        $terfilter = $this->props(route('proses-bangun.index', ['project' => $this->project->id] + ['blok' => 'A1']))['ringkasan'];

        $this->assertSame(6, $semua['total']);
        $this->assertSame(0, $semua['selesai']);
        $this->assertSame($semua, $terfilter);
    }

    public function test_deadline_unit_selesai_netral_sedangkan_unit_belum_selesai_lewat_deadline_tetap_merah(): void
    {
        $this->spk('SPK-TELAT', ['A1-1', 'A1-2'], ['tanggal_deadline' => now()->subDays(10)->toDateString()]);
        $this->selesai('A1-1');

        $data = collect($this->props(route('proses-bangun.index', $this->project))['kavlings']['data'])->keyBy('id');

        $this->assertSame('selesai', $data[$this->unit['A1-1']->id]['spk_deadline_status']);
        $this->assertSame('expired', $data[$this->unit['A1-2']->id]['spk_deadline_status']);
        $this->assertNull($data[$this->unit['A1-3']->id]['spk_deadline_status']);
    }

    public function test_proses_bangun_memuat_ringkasan_spk_dan_tidak_lagi_punya_tombol_buat_spk(): void
    {
        $this->spk('SPK-A', ['A1-1']);
        $this->spk('SPK-B', ['A1-2'], ['tanggal_deadline' => now()->subDays(2)->toDateString()]);

        $p = $this->props(route('proses-bangun.index', $this->project));

        $this->assertSame(2, $p['spkKartu']['berjalan']);
        $this->assertSame(1, $p['spkKartu']['lewat_deadline']);
        $this->assertArrayNotHasKey('canCreateSpk', $p);
    }
}
