<?php

namespace Tests\Feature;

use App\Models\AlihPembeliRiwayat;
use App\Models\BankRekananPreset;
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

/** Daftar Konsumen: konsumen/transaksi yang batal atau dialihkan disembunyikan secara default dan bisa ditampilkan lewat centang. */
class KonsumenNonaktifTest extends TestCase
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
            'status_jual' => $tahap === 'batal' ? 'available' : 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);

        return KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $k->id, 'status' => $tahap === 'batal' ? 'cancelled' : 'active',
            'status_penjualan' => $tahap, 'cara_bayar' => 'cash', 'tanggal_booking' => now()]);
    }

    private function namaPerUnit(array $query = []): array
    {
        $rows = null;
        $this->actingAs($this->admin)->get(route('konsumens.index', $query))->assertOk()->assertInertia(function ($page) use (&$rows) {
            $rows = $page->toArray()['props']['rows']['data'];

            return $page;
        });

        return array_column($rows, 'konsumen_nama');
    }

    private function namaPerKonsumen(array $query = []): array
    {
        return array_column($this->daftarKonsumen($query), 'nama');
    }

    private function daftarKonsumen(array $query = []): array
    {
        $rows = null;
        $this->actingAs($this->admin)->get(route('konsumens.index', ['view' => 'konsumen'] + $query))->assertOk()->assertInertia(function ($page) use (&$rows) {
            $rows = $page->toArray()['props']['rows']['data'];

            return $page;
        });

        return $rows;
    }

    public function test_tampilan_per_unit_menyembunyikan_transaksi_batal_kecuali_dicentang_atau_difilter(): void
    {
        $aktif = Konsumen::create(['nama' => 'Aktif', 'no_hp' => '081']);
        $batal = Konsumen::create(['nama' => 'Batal', 'no_hp' => '082']);
        $this->transaksi($aktif);
        $this->transaksi($batal, 'batal');

        $this->assertSame(['Aktif'], $this->namaPerUnit());
        $this->assertEqualsCanonicalizing(['Aktif', 'Batal'], $this->namaPerUnit(['tampilkan_nonaktif' => 1]));
        $this->assertSame(['Batal'], $this->namaPerUnit(['status_penjualan' => 'batal']));          // filter "Batal" otomatis menampilkannya
    }

    public function test_tampilan_per_konsumen_menyembunyikan_batal_dan_dialihkan_tetapi_calon_pembeli_tetap_tampil(): void
    {
        $aktif = Konsumen::create(['nama' => 'Aktif', 'no_hp' => '081']);
        $campur = Konsumen::create(['nama' => 'Campur', 'no_hp' => '082']);          // satu batal + satu masih berjalan: tetap tampil
        $semuaBatal = Konsumen::create(['nama' => 'SemuaBatal', 'no_hp' => '083']);
        $dialihkan = Konsumen::create(['nama' => 'Dialihkan', 'no_hp' => '084']);
        $calon = Konsumen::create(['nama' => 'Calon', 'no_hp' => '085']);            // belum pernah bertransaksi
        $penerima = Konsumen::create(['nama' => 'Penerima', 'no_hp' => '086']);

        $this->transaksi($aktif);
        $this->transaksi($campur, 'batal');
        $this->transaksi($campur);
        $this->transaksi($semuaBatal, 'batal');
        $trx = $this->transaksi($penerima);
        AlihPembeliRiwayat::create(['kavling_konsumen_id' => $trx->id, 'dari_konsumen_id' => $dialihkan->id, 'ke_konsumen_id' => $penerima->id, 'hubungan' => 'saudara', 'alasan' => 'BI Checking tidak lolos', 'tahap_sebelum' => 'pemberkasan']);

        $this->assertEqualsCanonicalizing(['Aktif', 'Campur', 'Calon', 'Penerima'], $this->namaPerKonsumen());
        $this->assertEqualsCanonicalizing(['Aktif', 'Campur', 'SemuaBatal', 'Dialihkan', 'Calon', 'Penerima'], $this->namaPerKonsumen(['tampilkan_nonaktif' => 1]));

        // Badge: yang dialihkan tidak punya transaksi aktif; yang semuanya batal ditandai lewat jumlah aktif 0.
        $baris = collect($this->daftarKonsumen(['tampilkan_nonaktif' => 1]))->keyBy('nama');
        $this->assertSame([0, 1], [$baris['Dialihkan']['transaksi_aktif_count'], $baris['Dialihkan']['dialihkan_count']]);
        $this->assertSame([1, 0], [$baris['SemuaBatal']['transaksi_count'], $baris['SemuaBatal']['transaksi_aktif_count']]);
        $this->assertSame(1, $baris['Campur']['transaksi_aktif_count']);
    }

    public function test_baris_transaksi_hasil_peralihan_menampilkan_pembeli_sebelumnya(): void
    {
        $a = Konsumen::create(['nama' => 'Anton', 'no_hp' => '081']);
        $b = Konsumen::create(['nama' => 'Budi', 'no_hp' => '082']);
        $trx = $this->transaksi($b);
        $this->transaksi($a);                                                        // transaksi lain tanpa peralihan
        AlihPembeliRiwayat::create(['kavling_konsumen_id' => $trx->id, 'dari_konsumen_id' => $a->id, 'ke_konsumen_id' => $b->id, 'hubungan' => 'saudara', 'alasan' => 'BI Checking tidak lolos', 'tahap_sebelum' => 'pemberkasan']);

        $rows = null;
        $this->actingAs($this->admin)->get(route('konsumens.index'))->assertInertia(function ($page) use (&$rows) {
            $rows = collect($page->toArray()['props']['rows']['data'])->keyBy('konsumen_nama');

            return $page;
        });

        $this->assertSame('Anton', $rows['Budi']['peralihan_dari']);
        $this->assertNull($rows['Anton']['peralihan_dari']);
    }
}
