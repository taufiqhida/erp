<?php

namespace Tests\Feature;

use App\Models\BankRekananPreset;
use App\Models\BastRecord;
use App\Models\CatatanTransaksi;
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

/** Catatan transaksi: entri yang bisa ditambah/diedit/diselesaikan (hapus khusus override) dan tanggal pengajuan bank satu tempat. */
class CatatanTransaksiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private Konsumen $konsumen;
    private KavlingKonsumen $trx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Sari Admin']);
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $this->konsumen = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        $bank = BankRekananPreset::create(['nama' => 'BTN', 'is_active' => true]);
        $this->trx = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $this->konsumen->id, 'status' => 'active',
            'status_penjualan' => 'pemberkasan', 'cara_bayar' => 'kpr_subsidi', 'tanggal_booking' => now(), 'bank_rekanan_preset_id' => $bank->id]);
    }

    private function user(string $role, bool $ditugaskan = true): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($ditugaskan) $u->projects()->attach($this->project->id);

        return $u;
    }

    private function catatan(string $jenis = 'booking', string $isi = 'Gratis kanopi'): CatatanTransaksi
    {
        return CatatanTransaksi::tulis($this->trx, $jenis, $isi, null, $this->admin);
    }

    private function lanjutkan(?string $catatan, array $extra = [])
    {
        return $this->actingAs($this->admin)->patch(route('bookings.update-status', $this->trx), ['status_penjualan' => 'proses_bank', 'catatan' => $catatan] + $extra);
    }

    // ── Catatan "Lanjutkan" ──────────────────────────────────────────────

    public function test_catatan_lanjutkan_menjadi_entri_pemberkasan_dan_tidak_menyentuh_catatan_booking(): void
    {
        $booking = $this->catatan('booking', 'Gratis kanopi dan biaya hook');

        $this->lanjutkan('Slip gaji menyusul setelah pengajuan')->assertSessionHas('success');

        $entri = CatatanTransaksi::where('jenis', 'pemberkasan')->firstOrFail();
        $this->assertSame('Slip gaji menyusul setelah pengajuan', $entri->isi);
        $this->assertSame('Pemberkasan → Proses Bank / SLIK', $entri->tahap);
        $this->assertSame('Sari Admin', $entri->penulis_nama);
        $this->assertFalse($entri->selesai);
        $this->assertSame('Gratis kanopi dan biaya hook', $booking->fresh()->isi);

        // Catatan berikutnya = entri baru, bukan menimpa.
        $this->trx->update(['status_penjualan' => 'pemberkasan']);
        $this->lanjutkan('Berkas sudah lengkap')->assertSessionHas('success');
        $this->assertSame(2, CatatanTransaksi::where('jenis', 'pemberkasan')->count());
    }

    public function test_lanjutkan_tanpa_catatan_tidak_membuat_entri(): void
    {
        $this->lanjutkan(null)->assertSessionHas('success');

        $this->assertSame(0, CatatanTransaksi::count());
    }

    // ── Tambah / edit / selesai / hapus ──────────────────────────────────

    public function test_admin_sales_dan_pemberkasan_bisa_menambah_mengedit_dan_menyelesaikan_tetapi_tidak_menghapus(): void
    {
        foreach (['admin_sales', 'admin_pemberkasan'] as $peran) {
            $u = $this->user($peran);

            $this->actingAs($u)->post(route('catatan.store', $this->trx), ['jenis' => 'pemberkasan', 'isi' => "dari {$peran}"])->assertSessionHas('success');
            $c = CatatanTransaksi::where('isi', "dari {$peran}")->firstOrFail();
            $this->assertSame($u->name, $c->penulis_nama);

            $this->actingAs($u)->patch(route('catatan.update', $c), ['isi' => "revisi {$peran}"])->assertSessionHas('success');
            $this->assertSame("revisi {$peran}", $c->fresh()->isi);

            $this->actingAs($u)->patch(route('catatan.selesai', $c), ['selesai' => true])->assertSessionHas('success');
            $this->assertTrue($c->fresh()->selesai);
            $this->assertSame($u->id, $c->fresh()->diselesaikan_oleh);
            $this->assertNotNull($c->fresh()->diselesaikan_at);

            $this->actingAs($u)->patch(route('catatan.selesai', $c), ['selesai' => false])->assertSessionHas('success');   // dibuka lagi
            $this->assertFalse($c->fresh()->selesai);
            $this->assertNull($c->fresh()->diselesaikan_oleh);

            $this->actingAs($u)->delete(route('catatan.destroy', $c))->assertStatus(403);
            $this->assertNotNull(CatatanTransaksi::find($c->id));
        }
    }

    public function test_hanya_spv_leader_dan_superadmin_yang_boleh_menghapus_dan_penghapusan_tercatat(): void
    {
        foreach (['spv', 'leader', 'superadmin'] as $peran) {
            $c = $this->catatan();
            $this->actingAs($this->user($peran))->delete(route('catatan.destroy', $c))->assertSessionHas('success');
            $this->assertNull(CatatanTransaksi::find($c->id));
            $this->assertNotNull(CatatanTransaksi::withTrashed()->find($c->id)->deleted_at);        // soft delete: jejak tetap ada
        }
        $this->assertTrue(\Spatie\Activitylog\Models\Activity::where('subject_type', CatatanTransaksi::class)->where('description', 'like', '%deleted%')->exists());
    }

    public function test_peran_tanpa_izin_pipeline_atau_di_luar_proyek_ditolak(): void
    {
        $c = $this->catatan();

        foreach (['pelaksana_lapangan', 'direktur', 'admin_keuangan'] as $peran) {
            $u = $this->user($peran);
            $this->actingAs($u)->post(route('catatan.store', $this->trx), ['jenis' => 'booking', 'isi' => 'x'])->assertStatus(403);
            $this->actingAs($u)->patch(route('catatan.update', $c), ['isi' => 'x'])->assertStatus(403);
        }

        $luar = $this->user('admin_sales', false);                           // tidak ditugaskan ke proyek ini
        $this->actingAs($luar)->post(route('catatan.store', $this->trx), ['jenis' => 'booking', 'isi' => 'x'])->assertStatus(403);
        $this->assertSame('Gratis kanopi', $c->fresh()->isi);
    }

    public function test_transaksi_selesai_terkunci_untuk_catatan_kecuali_pemegang_override(): void
    {
        $c = $this->catatan();
        $this->trx->update(['status' => 'completed']);

        $this->actingAs($this->user('admin_sales'))->patch(route('catatan.update', $c), ['isi' => 'ubah'])->assertStatus(403);
        $this->assertSame('Gratis kanopi', $c->fresh()->isi);

        $this->actingAs($this->user('spv'))->patch(route('catatan.update', $c), ['isi' => 'ubah oleh spv'])->assertSessionHas('success');
        $this->assertSame('ubah oleh spv', $c->fresh()->isi);
    }

    public function test_isi_catatan_wajib_dan_jenis_hanya_booking_atau_pemberkasan(): void
    {
        $this->actingAs($this->admin)->post(route('catatan.store', $this->trx), ['jenis' => 'booking', 'isi' => '  '])->assertSessionHasErrors('isi');
        $this->actingAs($this->admin)->post(route('catatan.store', $this->trx), ['jenis' => 'bank', 'isi' => 'x'])->assertSessionHasErrors('jenis');
        $this->assertSame(0, CatatanTransaksi::count());
    }

    // ── Tampilan ─────────────────────────────────────────────────────────

    public function test_profil_konsumen_menampilkan_semua_jenis_catatan_dan_menghitung_yang_masih_terbuka(): void
    {
        $this->catatan('booking', 'Gratis kanopi');
        $selesai = $this->catatan('pemberkasan', 'Slip gaji menyusul');
        $selesai->update(['selesai' => true]);
        $this->trx->update(['catatan_bank' => 'SLIK lancar', 'catatan_sp3k' => 'turun plafon sedikit']);
        DokumenKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'nama_dokumen' => 'KTP', 'sifat' => 'wajib', 'status' => 'perlu_revisi', 'catatan_revisi' => 'Foto buram']);
        DokumenKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'nama_dokumen' => 'KK', 'sifat' => 'wajib', 'status' => 'sudah_ada', 'catatan_revisi' => 'sudah dibenahi']);
        BastRecord::create(['kavling_konsumen_id' => $this->trx->id, 'catatan' => 'kunci diserahkan']);

        $this->actingAs($this->admin)->get(route('konsumens.show', $this->konsumen))->assertOk()
            ->assertInertia(fn ($page) => $page->has('transaksis.0.catatan_transaksi.entri', 2)
                ->where('transaksis.0.catatan_transaksi.proses_bank', 'SLIK lancar')
                ->where('transaksis.0.catatan_transaksi.sp3k', 'turun plafon sedikit')
                ->where('transaksis.0.catatan_transaksi.bast', 'kunci diserahkan')
                ->has('transaksis.0.catatan_transaksi.revisi_dokumen', 2)
                // terbuka: entri booking (1) + revisi KTP yang belum beres (1); entri selesai dan berkas yang sudah ada tidak dihitung
                ->where('transaksis.0.catatan_transaksi.terbuka', 2)
                ->where('transaksis.0.catatan_transaksi.izin.tambah', true)
                ->where('transaksis.0.catatan_transaksi.izin.hapus', true));
    }

    public function test_halaman_dokumen_membawa_catatan_dan_izin_sesuai_peran(): void
    {
        $this->catatan();

        $this->actingAs($this->user('admin_sales'))->get(route('dokumen.index', $this->trx))->assertOk()
            ->assertInertia(fn ($page) => $page->where('catatanTransaksi.terbuka', 1)->where('catatanTransaksi.izin.tambah', true)->where('catatanTransaksi.izin.hapus', false));
    }

    // ── Tanggal pengajuan bank: satu tempat ───────────────────────────────

    public function test_masuk_proses_bank_mengisi_hari_ini_atau_mempertahankan_tanggal_sebelumnya(): void
    {
        $this->lanjutkan(null)->assertSessionHas('success');
        $this->assertSame(now()->format('Y-m-d'), $this->trx->fresh()->tanggal_pengajuan_bank->format('Y-m-d'));

        $this->trx->update(['status_penjualan' => 'pemberkasan', 'tanggal_pengajuan_bank' => '2026-09-01']);          // pernah diajukan, lalu kembali
        $this->lanjutkan(null)->assertSessionHas('success');
        $this->assertSame('2026-09-01', $this->trx->fresh()->tanggal_pengajuan_bank->format('Y-m-d'));
    }

    public function test_tanggal_pengajuan_dikoreksi_lewat_endpoint_sendiri_dan_keputusan_bank_tidak_menimpanya(): void
    {
        $this->trx->update(['status_penjualan' => 'proses_bank', 'tanggal_pengajuan_bank' => '2026-09-01']);

        $this->actingAs($this->admin)->patch(route('bookings.tanggal-pengajuan', $this->trx), ['tanggal_pengajuan_bank' => '2026-09-10'])->assertSessionHas('success');
        $this->assertSame('2026-09-10', $this->trx->fresh()->tanggal_pengajuan_bank->format('Y-m-d'));

        // Form keputusan (walau masih mengirim tanggal lama) tidak boleh menimpa.
        $this->actingAs($this->admin)->patch(route('bookings.bank-decision', $this->trx), ['status_bank' => 'ditolak', 'tanggal_pengajuan_bank' => '2020-01-01'])->assertSessionHas('success');
        $this->assertSame('2026-09-10', $this->trx->fresh()->tanggal_pengajuan_bank->format('Y-m-d'));
        $this->assertSame('ditolak', $this->trx->fresh()->status_bank);
    }

    public function test_tanggal_pengajuan_validasi_tahap_dan_izin(): void
    {
        $url = route('bookings.tanggal-pengajuan', $this->trx);

        $this->actingAs($this->admin)->patch($url, ['tanggal_pengajuan_bank' => '2026-09-10'])->assertStatus(422);        // masih di Pemberkasan

        $this->trx->update(['status_penjualan' => 'proses_bank']);
        $this->actingAs($this->admin)->patch($url, ['tanggal_pengajuan_bank' => now()->addDays(2)->format('Y-m-d')])->assertSessionHasErrors('tanggal_pengajuan_bank');
        $this->actingAs($this->admin)->patch($url, ['tanggal_pengajuan_bank' => ''])->assertSessionHasErrors('tanggal_pengajuan_bank');
        $this->actingAs($this->user('admin_sales'))->patch($url, ['tanggal_pengajuan_bank' => '2026-09-10'])->assertStatus(403);   // bukan ranah sales
    }
}
