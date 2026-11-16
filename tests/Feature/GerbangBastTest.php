<?php

namespace Tests\Feature;

use App\Models\BastRecord;
use App\Models\CatatanTransaksi;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Rilis 3 / R2b: Rencana Akad cash menjadi lunak (catatan alasan wajib bila ada tagihan belum lunas) sedangkan konfirmasi BAST keras
 * untuk SEMUA cara bayar: semua tagihan konsumen lunas, kecuali override pemegang izin 'override transaction lock' dengan alasan tercatat.
 */
class GerbangBastTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    /** Transaksi siap BAST (bangunan siap, form BAST ditandatangani), pembayaran diatur terpisah. */
    private function transaksi(string $cara = 'cash', string $tahap = 'akad', array $ubah = []): KavlingKonsumen
    {
        $this->nomor++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'status_bangun_stage_id' => StatusBangunStage::finalStage()->id, 'status_bangun_persen' => 100,
            'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor, 'harga' => 100000000, 'status_jual' => 'sold']);
        $kk = KavlingKonsumen::create($ubah + ['kavling_id' => $unit->id, 'konsumen_id' => Konsumen::create(['nama' => 'Budi ' . $this->nomor, 'no_hp' => '0812'])->id, 'status' => 'active',
            'status_penjualan' => $tahap, 'cara_bayar' => $cara, 'tanggal_booking' => '2026-03-01', 'harga_dasar' => 100000000, 'harga_deal' => 100000000]);
        BastRecord::create(['kavling_konsumen_id' => $kk->id, 'status_ttd' => 'sudah_ttd', 'tanggal_bast' => '2026-05-01']);

        return $kk;
    }

    /** Jadwal cash: Booking Fee 1 jt (lunas) + DP 9 jt (parameter) + Pelunasan 90 jt (parameter). */
    private function jadwalCash(KavlingKonsumen $kk, bool $dpLunas, bool $pelunasanLunas): void
    {
        foreach ([['booking_fee', 1000000, true], ['dp', 9000000, $dpLunas], ['pelunasan', 90000000, $pelunasanLunas]] as [$jenis, $jumlah, $lunas]) {
            $j = $kk->jadwalTagihans()->create(['jenis' => $jenis, 'nomor_cicilan' => 1, 'jumlah' => $jumlah, 'tanggal_jatuh_tempo' => null, 'status' => 'belum_bayar']);
            if ($lunas) {
                $j->update(['status' => 'lunas', 'pembayaran_konsumen_id' => $kk->pembayarans()->create(['jenis' => $jenis, 'jumlah' => $jumlah, 'tanggal_bayar' => '2026-04-01'])->id]);
            }
        }
    }

    private function konfirmasi(User $u, KavlingKonsumen $kk, array $data = [])
    {
        return $this->actingAs($u)->withSession(['current_project_id' => $this->project->id])->patch(route('bast.confirm-selesai', $kk), $data);
    }

    // ── Konfirmasi BAST ──

    public function test_bast_ditolak_bila_ada_tagihan_belum_lunas_walau_dp_lunas(): void
    {
        $kk = $this->transaksi();
        $this->jadwalCash($kk, true, false);                                       // BF, DP lunas; Pelunasan belum

        $this->konfirmasi($this->user('admin_sales'), $kk)->assertStatus(422);
        $this->assertSame('akad', $kk->fresh()->status_penjualan);
    }

    public function test_bast_ditolak_bila_biaya_tanah_belum_dibayar_walau_dp_dan_pelunasan_lunas(): void
    {
        $kk = $this->transaksi('cash', 'akad', ['biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_nominal' => 5000000, 'harga_deal' => 105000000]);
        $this->jadwalCash($kk, true, true);

        $this->konfirmasi($this->user('admin_sales'), $kk)->assertStatus(422);

        $kk->pembayarans()->create(['jenis' => 'biaya_tanah', 'jumlah' => 5000000, 'tanggal_bayar' => '2026-04-02']);
        $this->konfirmasi($this->user('admin_sales'), $kk->fresh())->assertSessionHas('success');
        $this->assertSame('bast', $kk->fresh()->status_penjualan);
    }

    public function test_bast_kpr_menuntut_titipan_dan_biaya_tambahan_lunas_sisi_bank_tidak_ikut(): void
    {
        $kk = $this->transaksi('kpr_subsidi', 'akad', ['titipan_biaya_akad_nominal' => 3000000, 'plafon_kpr' => 90000000]);

        $this->konfirmasi($this->user('admin_sales'), $kk)->assertStatus(422);        // Titipan belum dibayar

        $kk->pembayarans()->create(['jenis' => 'titipan_biaya_akad', 'jumlah' => 3000000, 'tanggal_bayar' => '2026-04-02']);
        $this->konfirmasi($this->user('admin_sales'), $kk->fresh())->assertSessionHas('success');   // pencairan bank belum, tetapi bukan syarat BAST
    }

    public function test_bast_lolos_bila_semua_lunas(): void
    {
        $kk = $this->transaksi();
        $this->jadwalCash($kk, true, true);

        $this->konfirmasi($this->user('admin_sales'), $kk)->assertSessionHas('success');
        $this->assertSame('bast', $kk->fresh()->status_penjualan);
    }

    public function test_override_butuh_izin_dan_alasan_dan_tercatat(): void
    {
        $kk = $this->transaksi();
        $this->jadwalCash($kk, true, false);

        // Tanpa izin override, alasan apa pun tidak membantu.
        $this->konfirmasi($this->user('admin_sales'), $kk, ['alasan_override' => 'sisa dibayar minggu depan'])->assertStatus(422);

        // Pemegang izin: alasan wajib (minimal 5 karakter).
        $spv = $this->user('spv');
        $this->konfirmasi($spv, $kk)->assertStatus(422);
        $this->konfirmasi($spv, $kk, ['alasan_override' => 'abc'])->assertStatus(422);
        $this->assertSame('akad', $kk->fresh()->status_penjualan);

        $this->konfirmasi($spv, $kk, ['alasan_override' => 'Sisa Rp 90 jt dibayar minggu depan, ada surat pernyataan'])->assertSessionHas('success');
        $this->assertSame('bast', $kk->fresh()->status_penjualan);

        $catatan = CatatanTransaksi::where('kavling_konsumen_id', $kk->id)->get();
        $this->assertCount(1, $catatan);
        $this->assertStringContainsString('Pelunasan (sisa Rp 90.000.000)', $catatan[0]->isi);
        $this->assertStringContainsString('surat pernyataan', $catatan[0]->isi);
        $this->assertTrue(Activity::where('description', 'like', 'Override gerbang BAST%')->where('subject_id', $kk->id)->exists());
    }

    public function test_override_tidak_dicatat_bila_sebenarnya_sudah_lunas(): void
    {
        $kk = $this->transaksi();
        $this->jadwalCash($kk, true, true);

        $this->konfirmasi($this->user('spv'), $kk, ['alasan_override' => 'tidak perlu alasan'])->assertSessionHas('success');
        $this->assertSame(0, CatatanTransaksi::where('kavling_konsumen_id', $kk->id)->count());
    }

    public function test_halaman_dokumen_menampilkan_komponen_belum_lunas(): void
    {
        $kk = $this->transaksi();
        $this->jadwalCash($kk, false, false);

        $this->actingAs($this->user('spv'))->withSession(['current_project_id' => $this->project->id])->get(route('dokumen.index', $kk))->assertOk()
            ->assertInertia(fn ($p) => $p->where('transaksi.pembayaran_lunas', false)->where('transaksi.bisa_override_bast', true)
                ->where('transaksi.komponen_belum_lunas', fn ($k) => collect($k)->pluck('label')->all() === ['DP / Pengikatan', 'Pelunasan']));
    }

    // ── Rencana Akad cash lunak ──

    private function keRencanaAkad(KavlingKonsumen $kk, array $data = [])
    {
        return $this->actingAs($this->user('admin_sales'))->withSession(['current_project_id' => $this->project->id])
            ->patch(route('bookings.update-status', $kk), ['status_penjualan' => 'rencana_akad'] + $data);
    }

    public function test_rencana_akad_cash_butuh_catatan_alasan_bila_ada_tagihan_belum_lunas(): void
    {
        $kk = $this->transaksi('cash', 'pemberkasan');
        $this->jadwalCash($kk, true, false);                                       // Pelunasan baru dibayar saat akad

        $this->keRencanaAkad($kk)->assertStatus(422);
        $this->assertSame('pemberkasan', $kk->fresh()->status_penjualan);

        $this->keRencanaAkad($kk, ['catatan' => 'Pelunasan dibayar saat akad'])->assertSessionHas('success');
        $this->assertSame('rencana_akad', $kk->fresh()->status_penjualan);
        $this->assertSame(1, CatatanTransaksi::where('kavling_konsumen_id', $kk->id)->where('isi', 'like', '%Pelunasan dibayar saat akad%')->count());
    }

    public function test_rencana_akad_cash_lunas_tidak_butuh_catatan(): void
    {
        $kk = $this->transaksi('cash', 'pemberkasan');
        $this->jadwalCash($kk, true, true);

        $this->keRencanaAkad($kk)->assertSessionHas('success');
        $this->assertSame('rencana_akad', $kk->fresh()->status_penjualan);
    }
}
