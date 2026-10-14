<?php

namespace Tests\Feature;

use App\Models\AlihPembeliRiwayat;
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
 * Menghapus konsumen yang masih punya transaksi tidak boleh terjadi (unit tetap terjual tetapi pembelinya "hilang" sehingga daftar
 * Konsumen error 500 dan halaman pesanan 404). Data yang terlanjur seperti itu tetap bisa dibuka dan dipulihkan lewat perintah artisan.
 */
class KonsumenHapusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private TipeUnitPreset $tipe;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
    }

    private function transaksi(Konsumen $k, string $tahap = 'pemberkasan'): KavlingKonsumen
    {
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) ++$this->nomor,
            'status_jual' => $tahap === 'batal' ? 'available' : 'sold', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        return KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $k->id, 'status' => $tahap === 'batal' ? 'cancelled' : 'active',
            'status_penjualan' => $tahap, 'cara_bayar' => 'cash', 'tanggal_booking' => now()]);
    }

    // ── Pencegahan ───────────────────────────────────────────────────────

    public function test_konsumen_yang_punya_transaksi_tidak_bisa_dihapus_baik_aktif_maupun_batal(): void
    {
        $aktif = Konsumen::create(['nama' => 'Aktif', 'no_hp' => '081']);
        $batal = Konsumen::create(['nama' => 'Batal', 'no_hp' => '082']);
        $trxAktif = $this->transaksi($aktif);
        $this->transaksi($batal, 'batal');

        $this->actingAs($this->admin)->delete(route('konsumens.destroy', $aktif))->assertSessionHas('error');
        $this->actingAs($this->admin)->delete(route('konsumens.destroy', $batal))->assertSessionHas('error');

        $this->assertNotNull(Konsumen::find($aktif->id));
        $this->assertNotNull(Konsumen::find($batal->id));
        $this->assertSame('sold', $trxAktif->kavling->fresh()->status_jual->value);          // unit tidak tersentuh
    }

    public function test_konsumen_yang_pernah_terlibat_peralihan_tidak_bisa_dihapus(): void
    {
        $a = Konsumen::create(['nama' => 'Anton', 'no_hp' => '081']);
        $b = Konsumen::create(['nama' => 'Budi', 'no_hp' => '082']);
        $trx = $this->transaksi($b);
        AlihPembeliRiwayat::create(['kavling_konsumen_id' => $trx->id, 'dari_konsumen_id' => $a->id, 'ke_konsumen_id' => $b->id, 'hubungan' => 'saudara', 'alasan' => 'BI Checking tidak lolos', 'tahap_sebelum' => 'pemberkasan']);

        $this->actingAs($this->admin)->delete(route('konsumens.destroy', $a))->assertSessionHas('error');     // pembeli lama (tanpa transaksi sendiri)
        $this->assertNotNull(Konsumen::find($a->id));
    }

    public function test_calon_pembeli_tanpa_transaksi_masih_bisa_dihapus_dan_peran_tanpa_izin_ditolak(): void
    {
        $calon = Konsumen::create(['nama' => 'Calon', 'no_hp' => '083']);
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');

        $this->actingAs($sales)->delete(route('konsumens.destroy', $calon))->assertStatus(403);
        $this->assertNotNull(Konsumen::find($calon->id));

        $this->actingAs($this->admin)->delete(route('konsumens.destroy', $calon))->assertSessionHas('success');
        $this->assertNull(Konsumen::find($calon->id));
    }

    public function test_ikon_hapus_hanya_untuk_yang_berizin_dan_konsumen_tanpa_riwayat(): void
    {
        $calon = Konsumen::create(['nama' => 'Calon', 'no_hp' => '083']);
        $punya = Konsumen::create(['nama' => 'Punya', 'no_hp' => '084']);
        $this->transaksi($punya);

        $this->actingAs($this->admin)->get(route('konsumens.index', ['view' => 'konsumen']))->assertOk()
            ->assertInertia(function ($page) {
                $baris = collect($page->toArray()['props']['rows']['data'])->keyBy('nama');
                $this->assertTrue($baris['Calon']['bisa_dihapus']);
                $this->assertFalse($baris['Punya']['bisa_dihapus']);

                return $page;
            });
    }

    // ── Pemulihan data yang sudah terlanjur ──────────────────────────────

    public function test_halaman_tidak_error_walau_konsumen_transaksi_sudah_terlanjur_terhapus(): void
    {
        $k = Konsumen::create(['nama' => 'Terhapus', 'no_hp' => '085']);
        $trx = $this->transaksi($k);
        $k->delete();                                                                  // keadaan di staging sebelum perbaikan

        $this->actingAs($this->admin)->get(route('konsumens.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('konsumens.index', ['view' => 'konsumen']))->assertOk();
        $this->actingAs($this->admin)->get(route('dokumen.index', $trx))->assertOk();
        $this->actingAs($this->admin)->get(route('projects.show', $this->project))->assertOk();
    }

    public function test_perintah_pulihkan_menampilkan_lalu_memulihkan_hanya_konsumen_yang_punya_transaksi(): void
    {
        $salah = Konsumen::create(['nama' => 'Salah Hapus', 'no_hp' => '086']);
        $this->transaksi($salah);
        $salah->delete();
        $sengaja = Konsumen::create(['nama' => 'Calon Dihapus', 'no_hp' => '087']);
        $sengaja->delete();

        $this->artisan('konsumens:pulihkan')->expectsOutputToContain('Salah Hapus')->assertSuccessful();
        $this->assertNull(Konsumen::find($salah->id));                                 // tanpa --pulihkan hanya menampilkan

        $this->artisan('konsumens:pulihkan', ['--pulihkan' => true])->assertSuccessful();
        $this->assertNotNull(Konsumen::find($salah->id));                              // dipulihkan
        $this->assertNull(Konsumen::find($sengaja->id));                               // calon pembeli yang sengaja dihapus tidak disentuh

        $this->artisan('konsumens:pulihkan', ['--pulihkan' => true])->expectsOutputToContain('Tidak ada')->assertSuccessful();     // idempoten
    }
}
