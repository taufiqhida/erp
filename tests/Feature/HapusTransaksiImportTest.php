<?php

namespace Tests\Feature;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Enums\StatusJual;
use App\Models\CancellationRequest;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Perintah transaksi:hapus-import: penerus resmi skrip koreksi insiden 9 Nov (transaksi hasil import yang salah masuk). */
class HapusTransaksiImportTest extends TestCase
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
        $this->project = Project::create(['nama' => 'Proyek Uji', 'kode' => 'PU1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
    }

    /** Transaksi "hasil import": pembayaran berketerangan Saldo Awal (Import). */
    private function transaksiImport(?Konsumen $konsumen = null, ?Kavling $unit = null, string $status = 'active'): KavlingKonsumen
    {
        $this->nomor++;
        $unit ??= Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id,
            'blok' => 'B6', 'nomor_kavling' => (string) $this->nomor, 'harga' => 100000000, 'status_jual' => StatusJual::Sold]);
        $konsumen ??= Konsumen::create(['nama' => "Pembeli {$this->nomor}", 'nik' => '33741507010000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT)]);
        $kk = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $konsumen->id, 'status' => $status, 'status_penjualan' => 'akad', 'cara_bayar' => 'cash',
            'tanggal_booking' => '2026-01-10', 'harga_dasar' => 100000000, 'harga_deal' => 100000000]);
        PembayaranKonsumen::create(['kavling_konsumen_id' => $kk->id, 'jenis' => 'booking_fee', 'jumlah' => 1000000, 'tanggal_bayar' => '2026-01-10', 'keterangan' => 'Saldo Awal (Import)']);
        $kk->jadwalTagihans()->create(['jenis' => 'dp', 'nomor_cicilan' => 1, 'jumlah' => 5000000, 'tanggal_jatuh_tempo' => '2026-02-10', 'status' => 'belum_bayar']);

        return $kk->fresh();
    }

    public function test_pratinjau_tidak_menghapus_apa_pun_dan_menampilkan_rincian(): void
    {
        $kk = $this->transaksiImport();

        $this->artisan('transaksi:hapus-import', ['--id' => [$kk->id]])
            ->expectsOutputToContain("Transaksi #{$kk->id}")
            ->expectsOutputToContain('pembayaran_konsumens')
            ->expectsOutputToContain('status unit akan dikembalikan ke Tersedia')
            ->expectsOutputToContain('PRATINJAU')
            ->assertExitCode(0);

        $this->assertNotNull(KavlingKonsumen::find($kk->id));
    }

    public function test_terapkan_wajib_alasan_dan_id(): void
    {
        $kk = $this->transaksiImport();

        $this->artisan('transaksi:hapus-import')->assertExitCode(1);                                                 // tanpa --id
        $this->artisan('transaksi:hapus-import', ['--id' => [$kk->id], '--terapkan' => true])->assertExitCode(1);     // tanpa alasan
        $this->assertNotNull(KavlingKonsumen::find($kk->id));
    }

    public function test_terapkan_menghapus_transaksi_beserta_anaknya_mengembalikan_unit_dan_mencatat_log_tanpa_menghapus_konsumen(): void
    {
        $kk = $this->transaksiImport();
        $unit = $kk->kavling;

        $this->artisan('transaksi:hapus-import', ['--id' => [$kk->id], '--terapkan' => true, '--alasan' => 'NIK salah di file'])->assertExitCode(0);

        $this->assertNull(KavlingKonsumen::find($kk->id));
        $this->assertSame(0, PembayaranKonsumen::where('kavling_konsumen_id', $kk->id)->count());     // anak ikut terhapus (CASCADE)
        $this->assertSame(0, $kk->jadwalTagihans()->count());
        $this->assertSame(StatusJual::Available, $unit->fresh()->status_jual);
        $this->assertNotNull(Konsumen::find($kk->konsumen_id));                                       // konsumen TIDAK dihapus tanpa --hapus-konsumen
        $this->assertTrue(Activity::where('log_name', 'koreksi_import')->where('description', 'like', '%NIK salah di file%')->exists());
    }

    public function test_status_unit_tidak_berubah_bila_masih_ada_transaksi_aktif_lain_di_unit_itu(): void
    {
        $a = $this->transaksiImport();
        $b = $this->transaksiImport(unit: $a->kavling);

        $this->artisan('transaksi:hapus-import', ['--id' => [$b->id], '--terapkan' => true, '--alasan' => 'salah masuk'])->assertExitCode(0);

        $this->assertNotNull(KavlingKonsumen::find($a->id));
        $this->assertSame(StatusJual::Sold, $a->kavling->fresh()->status_jual);
    }

    public function test_hapus_konsumen_hanya_bila_tidak_punya_transaksi_lain_dan_nik_bisa_dipakai_lagi(): void
    {
        $sendiri = $this->transaksiImport();
        $nik = $sendiri->konsumen->nik;
        $punyaLain = $this->transaksiImport();
        $this->transaksiImport(konsumen: $punyaLain->konsumen);                                       // konsumen ini punya dua transaksi

        $this->artisan('transaksi:hapus-import', ['--id' => [$sendiri->id, $punyaLain->id], '--terapkan' => true, '--hapus-konsumen' => true, '--alasan' => 'uji'])->assertExitCode(0);

        $this->assertNull(Konsumen::withTrashed()->find($sendiri->konsumen_id));                      // terhapus permanen
        $this->assertNotNull(Konsumen::find($punyaLain->konsumen_id));                                // masih punya transaksi lain: tetap ada
        $this->assertNotNull(Konsumen::create(['nama' => 'Orang Baru', 'nik' => $nik]));              // NIK lama tidak lagi menghalangi
    }

    public function test_ditolak_bila_ada_pembayaran_yang_dicatat_di_luar_impor(): void
    {
        $kk = $this->transaksiImport();
        PembayaranKonsumen::create(['kavling_konsumen_id' => $kk->id, 'jenis' => 'dp', 'jumlah' => 2000000, 'tanggal_bayar' => '2026-03-01', 'keterangan' => 'Transfer BCA dicatat finance']);

        $this->artisan('transaksi:hapus-import', ['--id' => [$kk->id], '--terapkan' => true, '--alasan' => 'uji'])
            ->expectsOutputToContain('dicatat di luar impor')
            ->assertExitCode(1);

        $this->assertNotNull(KavlingKonsumen::find($kk->id));
    }

    public function test_ditolak_bila_ada_jejak_operasi_nyata_seperti_pengajuan(): void
    {
        $kk = $this->transaksiImport();
        CancellationRequest::create(['type' => CancellationRequestType::Cancellation, 'kavling_id' => $kk->kavling_id, 'kavling_konsumen_id' => $kk->id, 'konsumen_id' => $kk->konsumen_id,
            'kavling_status_before' => 'sold', 'alasan' => 'uji', 'requested_by' => $this->admin->id, 'status' => CancellationStatus::Pending]);

        $this->artisan('transaksi:hapus-import', ['--id' => [$kk->id], '--terapkan' => true, '--alasan' => 'uji'])
            ->expectsOutputToContain('cancellation_requests')
            ->assertExitCode(1);

        $this->assertNotNull(KavlingKonsumen::find($kk->id));
    }

    public function test_semua_atau_tidak_sama_sekali_bila_salah_satu_ditolak(): void
    {
        $aman = $this->transaksiImport();
        $manual = $this->transaksiImport();
        PembayaranKonsumen::create(['kavling_konsumen_id' => $manual->id, 'jenis' => 'dp', 'jumlah' => 1, 'tanggal_bayar' => '2026-03-01', 'keterangan' => 'manual']);

        $this->artisan('transaksi:hapus-import', ['--id' => [$aman->id, $manual->id], '--terapkan' => true, '--alasan' => 'uji'])->assertExitCode(1);

        $this->assertNotNull(KavlingKonsumen::find($aman->id));                                       // yang aman pun tidak dihapus
        $this->assertNotNull(KavlingKonsumen::find($manual->id));
    }

    public function test_id_yang_tidak_ada_dilaporkan_dan_tidak_menghapus_apa_pun(): void
    {
        $kk = $this->transaksiImport();

        $this->artisan('transaksi:hapus-import', ['--id' => [$kk->id, 999999], '--terapkan' => true, '--alasan' => 'uji'])
            ->expectsOutputToContain('#999999: tidak ditemukan')
            ->assertExitCode(1);

        $this->assertNotNull(KavlingKonsumen::find($kk->id));
    }
}
