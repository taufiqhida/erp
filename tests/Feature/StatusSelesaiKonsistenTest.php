<?php

namespace Tests\Feature;

use App\Enums\StatusJual;
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
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Status Selesai (completed) konsisten: aturan "lunas penuh" tunggal, transaksi Selesai yang koreksinya membuatnya tak lagi lunas
 * otomatis dibuka kembali (jalur event saja, bukan perintah massal), perintah buka-kembali dengan pratinjau, dan penandanya.
 */
class StatusSelesaiKonsistenTest extends TestCase
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
        $this->admin->projects()->attach($this->project->id);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
    }

    /** Transaksi cash Rp 10 jt dengan satu pembayaran Pelunasan (legacy lump sum) senilai $dibayar. */
    private function transaksi(string $status, float $dibayar): array
    {
        $this->nomor++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id,
            'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor, 'harga' => 10000000, 'status_jual' => StatusJual::Sold]);
        $konsumen = Konsumen::create(['nama' => "Pembeli {$this->nomor}", 'no_hp' => '0812']);
        // Seperti alur nyata (impor): transaksi dibuat berjalan, pembayarannya dicatat, BARU diberi status (tanpa event) — kalau dibuat
        // langsung Selesai sebelum pembayarannya ada, peninjauan ulang otomatis memang langsung membukanya.
        $kk = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'status_penjualan' => 'akad', 'cara_bayar' => 'cash',
            'tanggal_booking' => '2026-01-10', 'harga_dasar' => 10000000, 'harga_deal' => 10000000]);
        $bayar = PembayaranKonsumen::create(['kavling_konsumen_id' => $kk->id, 'jenis' => 'pelunasan', 'jumlah' => $dibayar, 'tanggal_bayar' => '2026-02-01', 'keterangan' => 'uji']);
        if ($status !== 'active') DB::table('kavling_konsumen')->where('id', $kk->id)->update(['status' => $status]);

        return [$kk->fresh(), $bayar, $unit];
    }

    public function test_aturan_lunas_penuh_memakai_toleransi_setengah_rupiah(): void
    {
        $b = fn ($terbayarK, $terbayarB = 0) => ['total_piutang_konsumen' => 1000.0, 'total_terbayar_konsumen' => $terbayarK, 'total_piutang_bank' => 0.0, 'total_terbayar_bank' => $terbayarB];

        $this->assertTrue(KavlingKonsumen::lunasPenuh($b(1000.0)));
        $this->assertTrue(KavlingKonsumen::lunasPenuh($b(999.6)));          // selisih pembulatan
        $this->assertFalse(KavlingKonsumen::lunasPenuh($b(999.0)));
        $this->assertFalse(KavlingKonsumen::lunasPenuh(['total_piutang_konsumen' => 0.0, 'total_terbayar_konsumen' => 0.0, 'total_piutang_bank' => 500.0, 'total_terbayar_bank' => 100.0]));
    }

    public function test_transaksi_selesai_tetap_selesai_selama_masih_lunas(): void
    {
        [$kk, $bayar] = $this->transaksi('completed', 10000000);

        $bayar->update(['keterangan' => 'diubah tanpa mengubah nominal']);

        $this->assertSame('completed', $kk->fresh()->status);
        $this->assertSame(0, Activity::where('description', 'like', '%dibuka kembali%')->count());
    }

    public function test_koreksi_pembayaran_yang_membuat_tak_lunas_membuka_kembali_dan_mencatat_log(): void
    {
        [$kk, $bayar, $unit] = $this->transaksi('completed', 10000000);

        $bayar->update(['jumlah' => 6000000]);                                       // dikoreksi setelah Selesai

        $kk->refresh();
        $this->assertSame('active', $kk->status);
        $this->assertFalse($kk->is_locked);
        $this->assertSame(StatusJual::Sold, $unit->fresh()->status_jual);            // status unit tidak ikut berubah
        $log = Activity::where('description', 'like', '%dibuka kembali otomatis%')->firstOrFail();
        $this->assertStringContainsString('sisa konsumen Rp 4.000.000', $log->description);
        $this->assertEquals(4000000, $log->properties['sisa_konsumen']);
    }

    public function test_hapus_pembayaran_lewat_override_membuka_kembali_dan_memberi_toast(): void
    {
        [$kk, $bayar] = $this->transaksi('completed', 10000000);

        $r = $this->actingAs($this->admin)->delete(route('pembayaran.destroy', $bayar));

        $this->assertSame('active', $kk->fresh()->status);
        $r->assertSessionHas('warning', fn ($m) => str_contains($m, 'dibuka kembali') && str_contains($m, 'Rp 10.000.000'));
    }

    public function test_transaksi_berjalan_dan_batal_tidak_disentuh_hook(): void
    {
        [$aktif, $bayarAktif] = $this->transaksi('active', 10000000);
        [$batal, $bayarBatal] = $this->transaksi('cancelled', 10000000);

        $bayarAktif->update(['jumlah' => 1000000]);
        $bayarBatal->update(['jumlah' => 1000000]);

        $this->assertSame('active', $aktif->fresh()->status);
        $this->assertSame('cancelled', $batal->fresh()->status);
    }

    public function test_tandai_selesai_manual_memakai_aturan_yang_sama(): void
    {
        [$belum] = $this->transaksi('active', 6000000);
        [$lunas] = $this->transaksi('active', 10000000);

        $this->actingAs($this->admin)->post(route('keuangan.mark-complete', $belum))->assertStatus(422);
        $this->assertSame('active', $belum->fresh()->status);

        $this->actingAs($this->admin)->post(route('keuangan.mark-complete', $lunas))->assertSessionHas('success');
        $this->assertSame('completed', $lunas->fresh()->status);
    }

    public function test_finance_recompute_tidak_membuka_kembali_diam_diam(): void
    {
        [$kk] = $this->transaksi('completed', 10000000);
        DB::table('pembayaran_konsumens')->where('kavling_konsumen_id', $kk->id)->update(['jumlah' => 6000000]);   // melewati event model

        $this->artisan('finance:recompute')->assertExitCode(0);

        $this->assertSame('completed', $kk->fresh()->status);
        $this->assertEquals(4000000, $kk->fresh()->fin_sisa_konsumen);
    }

    public function test_penanda_selesai_belum_lunas_di_daftar_dan_detail_keuangan(): void
    {
        [$kk] = $this->transaksi('completed', 10000000);
        DB::table('pembayaran_konsumens')->where('kavling_konsumen_id', $kk->id)->update(['jumlah' => 6000000]);
        $kk->refreshFinance();                                                       // tanpa tinjau: meniru peninggalan lama
        [$lunas] = $this->transaksi('completed', 10000000);

        $this->actingAs($this->admin)->get(route('keuangan.index', ['tampil' => 'semua']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('rows.data', fn ($rows) => collect($rows)->firstWhere('id', $kk->id)['selesai_belum_lunas'] === true
                && collect($rows)->firstWhere('id', $lunas->id)['selesai_belum_lunas'] === false));

        $this->actingAs($this->admin)->get(route('keuangan.detail', $kk))->assertOk()
            ->assertInertia(fn ($p) => $p->where('transaksi.selesai_belum_lunas', true)->where('transaksi.sisa_konsumen', 4000000));
    }

    // ── Perintah ──────────────────────────────────────────────────────────

    public function test_perintah_pratinjau_tidak_mengubah_dan_hanya_mendaftar_yang_belum_lunas(): void
    {
        [$belum] = $this->transaksi('completed', 10000000);
        DB::table('pembayaran_konsumens')->where('kavling_konsumen_id', $belum->id)->update(['jumlah' => 6000000]);
        [$lunas] = $this->transaksi('completed', 10000000);

        $this->artisan('transaksi:buka-kembali-selesai')
            ->expectsOutputToContain('PRATINJAU: 1 transaksi Selesai belum lunas')
            ->expectsOutputToContain('A1-1')
            ->assertExitCode(0);

        $this->assertSame('completed', $belum->fresh()->status);
        $this->assertSame('completed', $lunas->fresh()->status);
    }

    public function test_perintah_terapkan_wajib_alasan_dan_unit_lalu_membuka_hanya_yang_dipilih(): void
    {
        [$a] = $this->transaksi('completed', 10000000);
        [$b] = $this->transaksi('completed', 10000000);
        DB::table('pembayaran_konsumens')->whereIn('kavling_konsumen_id', [$a->id, $b->id])->update(['jumlah' => 6000000]);

        $this->artisan('transaksi:buka-kembali-selesai', ['--terapkan' => true, '--unit' => ['A1-1']])->assertExitCode(1);            // tanpa alasan
        $this->artisan('transaksi:buka-kembali-selesai', ['--terapkan' => true, '--alasan' => 'uji'])->assertExitCode(1);              // tanpa unit/--semua
        $this->assertSame('completed', $a->fresh()->status);

        $this->artisan('transaksi:buka-kembali-selesai', ['--terapkan' => true, '--unit' => ['A1-1', 'Z9-9'], '--alasan' => 'Sisa belum dibayar'])
            ->expectsOutputToContain('Dibuka kembali: #' . $a->id)
            ->expectsOutputToContain("unit 'z9-9' bukan kandidat")
            ->assertExitCode(0);

        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame('completed', $b->fresh()->status);                          // tidak dipilih
        $this->assertTrue(Activity::where('description', 'like', '%dibuka kembali lewat perintah%Sisa belum dibayar%')->exists());
    }

    public function test_perintah_semua_membuka_semua_kandidat(): void
    {
        [$a] = $this->transaksi('completed', 10000000);
        [$b] = $this->transaksi('completed', 10000000);
        [$lunas] = $this->transaksi('completed', 10000000);
        DB::table('pembayaran_konsumens')->whereIn('kavling_konsumen_id', [$a->id, $b->id])->update(['jumlah' => 6000000]);

        $this->artisan('transaksi:buka-kembali-selesai', ['--terapkan' => true, '--semua' => true, '--alasan' => 'uji semua'])->assertExitCode(0);

        $this->assertSame(['active', 'active', 'completed'], [$a->fresh()->status, $b->fresh()->status, $lunas->fresh()->status]);
    }
}
