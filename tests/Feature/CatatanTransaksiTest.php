<?php

namespace Tests\Feature;

use App\Models\BankRekananPreset;
use App\Models\BastRecord;
use App\Models\DokumenKonsumen;
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

/** Catatan di modal "Lanjutkan" tidak lagi menimpa catatan booking; semua catatan transaksi tampil di profil konsumen. */
class CatatanTransaksiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Konsumen $konsumen;
    private KavlingKonsumen $trx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Sari Admin']);
        $this->admin->assignRole('superadmin');
        $project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $project->id, 'nama' => '36/72', 'is_active' => true]);
        $unit = Kavling::create(['project_id' => $project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $this->konsumen = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        $bank = BankRekananPreset::create(['nama' => 'BTN', 'is_active' => true]);
        $this->trx = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $this->konsumen->id, 'status' => 'active',
            'status_penjualan' => 'pemberkasan', 'cara_bayar' => 'kpr_subsidi', 'tanggal_booking' => now(), 'bank_rekanan_preset_id' => $bank->id,
            'catatan' => 'Gratis kanopi dan biaya hook']);
    }

    private function lanjutkan(?string $catatan)
    {
        return $this->actingAs($this->admin)->patch(route('bookings.update-status', $this->trx), ['status_penjualan' => 'proses_bank', 'catatan' => $catatan]);
    }

    public function test_catatan_lanjutkan_ditambahkan_sebagai_riwayat_dan_tidak_menimpa_catatan_booking(): void
    {
        $this->lanjutkan('Slip gaji menyusul setelah pengajuan')->assertSessionHas('success');

        $trx = $this->trx->fresh();
        $this->assertSame('Gratis kanopi dan biaya hook', $trx->catatan);                       // catatan booking utuh
        $this->assertStringContainsString('Slip gaji menyusul setelah pengajuan', $trx->catatan_pemberkasan);
        $this->assertStringContainsString('Sari Admin', $trx->catatan_pemberkasan);
        $this->assertStringContainsString('Pemberkasan → Proses Bank', $trx->catatan_pemberkasan);

        // Catatan berikutnya ditambahkan di bawahnya, bukan menggantikan.
        $this->trx->update(['status_penjualan' => 'pemberkasan']);
        $this->lanjutkan('Berkas sudah lengkap')->assertSessionHas('success');
        $isi = $this->trx->fresh()->catatan_pemberkasan;
        $this->assertStringContainsString('Slip gaji menyusul', $isi);
        $this->assertStringContainsString('Berkas sudah lengkap', $isi);
    }

    public function test_lanjutkan_tanpa_catatan_tidak_menghapus_catatan_apa_pun(): void
    {
        $this->trx->update(['catatan_pemberkasan' => 'catatan lama']);

        $this->lanjutkan(null)->assertSessionHas('success');

        $this->assertSame('Gratis kanopi dan biaya hook', $this->trx->fresh()->catatan);
        $this->assertSame('catatan lama', $this->trx->fresh()->catatan_pemberkasan);
    }

    public function test_profil_konsumen_menampilkan_semua_enam_jenis_catatan(): void
    {
        $this->trx->update(['catatan_pemberkasan' => 'berkas disusulkan', 'catatan_bank' => 'SLIK lancar', 'catatan_sp3k' => 'turun plafon sedikit']);
        DokumenKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'nama_dokumen' => 'KTP', 'sifat' => 'wajib', 'status' => 'perlu_revisi', 'catatan_revisi' => 'Foto buram']);
        DokumenKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'nama_dokumen' => 'KK', 'sifat' => 'wajib', 'status' => 'sudah_ada']);
        BastRecord::create(['kavling_konsumen_id' => $this->trx->id, 'catatan' => 'kunci diserahkan']);

        $this->actingAs($this->admin)->get(route('konsumens.show', $this->konsumen))->assertOk()
            ->assertInertia(fn ($page) => $page->where('transaksis.0.catatan_transaksi.booking', 'Gratis kanopi dan biaya hook')
                ->where('transaksis.0.catatan_transaksi.pemberkasan', 'berkas disusulkan')
                ->where('transaksis.0.catatan_transaksi.proses_bank', 'SLIK lancar')
                ->where('transaksis.0.catatan_transaksi.sp3k', 'turun plafon sedikit')
                ->where('transaksis.0.catatan_transaksi.bast', 'kunci diserahkan')
                ->has('transaksis.0.catatan_transaksi.revisi_dokumen', 1)
                ->where('transaksis.0.catatan_transaksi.revisi_dokumen.0.catatan', 'Foto buram'));
    }
}
