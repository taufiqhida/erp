<?php

namespace Tests\Feature;

use App\Models\BiayaTambahanPreset;
use App\Models\JadwalTagihan;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Project;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\SumberLead;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\RekapKekuranganBank;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rilis 3 / R2b: pada cash, biaya tanah, biaya tambahan dan titipan jadi baris Kartu Piutang sendiri (seperti KPR); Pelunasan
 * = harga dasar - diskon - Booking Fee/DP yang masuk harga jual. Total piutang tidak boleh berubah dibanding rumus lama.
 */
class PelunasanCashTerpisahTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SalesAgent $agen;
    private SumberLead $lead;
    private BiayaTambahanPreset $hook;
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->agen = SalesAgent::create(['nama' => 'Agen 1', 'tipe' => 'sales', 'is_active' => true]);
        $this->lead = SumberLead::create(['nama' => 'Instagram', 'is_referral' => false, 'is_active' => true]);
        $this->hook = BiayaTambahanPreset::create(['nama' => 'Kavling Hook', 'is_active' => true]);
    }

    private function skema(bool $bfMasuk = true, bool $dpMasuk = true): SkemaDpPreset
    {
        return SkemaDpPreset::create([
            'nama' => 'Cash ' . ++$this->nomor, 'cara_bayar' => 'cash',
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => $bfMasuk,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 10000000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => $dpMasuk,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto',
        ]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function booking(array $ubah = [], ?SkemaDpPreset $skema = null, string $cara = 'cash'): KavlingKonsumen
    {
        $this->nomor++;
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor,
            'status_jual' => 'available', 'harga' => 200000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $this->actingAs($this->user('admin_sales'))->post(route('bookings.store', $unit), array_merge([
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'Budi ' . $this->nomor, 'konsumen_no_hp' => '0812', 'konsumen_nik' => '32010101019000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT),
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agen->id, 'cara_bayar' => $cara, 'cicilan_kali' => $cara === 'cash_bertahap' ? 3 : null,
            'skema_dp_preset_id' => ($skema ?? $this->skema())->id,
            'biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_mode' => 'per_m2', 'biaya_kelebihan_tanah_luas' => 10, 'biaya_kelebihan_tanah_harga_per_m2' => 800000,
            'biaya_tambahan' => [['preset_id' => $this->hook->id, 'nominal' => 5000000]],
        ], $ubah))->assertSessionHasNoErrors();

        return KavlingKonsumen::where('kavling_id', $unit->id)->firstOrFail();
    }

    private function breakdown(KavlingKonsumen $kk): array
    {
        return $kk->fresh(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])->kartuPiutangBreakdown();
    }

    private function baris(array $breakdown, string $nama): ?array
    {
        return collect($breakdown['kartu_piutang_static'])->firstWhere('nama', $nama);
    }

    public function test_cash_punya_baris_tanah_dan_biaya_tambahan_dan_pelunasan_hanya_harga_dasar(): void
    {
        $kk = $this->booking();                                                     // harga 200 jt + tanah 8 jt + hook 5 jt, BF 1 jt, DP 10 jt masuk harga jual
        $b = $this->breakdown($kk);

        $this->assertEquals(8000000, $this->baris($b, 'Biaya Penambahan Tanah')['nominal']);
        $this->assertEquals(5000000, $this->baris($b, 'Kavling Hook')['nominal']);
        $this->assertEquals(200000000 - 1000000 - 10000000, $this->baris($b, 'Pelunasan')['nominal']);
        $this->assertEquals(200000000 - 1000000 - 10000000, $kk->jadwalTagihans()->where('jenis', 'pelunasan')->sum('jumlah'));
        $this->assertEquals(213000000, $b['total_piutang_konsumen']);                // = harga deal, tidak berubah dari rumus lama
    }

    public function test_total_piutang_sama_dengan_rumus_lama_untuk_berbagai_kombinasi(): void
    {
        $kombinasi = [
            [true, true, [], 'cash'],
            [false, true, [], 'cash'],
            [true, false, [], 'cash'],
            [false, false, [], 'cash'],
            [true, true, ['biaya_kelebihan_tanah_aktif' => false, 'biaya_tambahan' => []], 'cash'],
            [true, true, ['biaya_kelebihan_tanah_aktif' => false], 'cash_bertahap'],
            [false, true, [], 'cash_bertahap'],
        ];
        foreach ($kombinasi as [$bfMasuk, $dpMasuk, $ubah, $cara]) {
            $kk = $this->booking($ubah, $this->skema($bfMasuk, $dpMasuk), $cara)->fresh();
            $bf = (float) $kk->jadwalTagihans()->where('jenis', 'booking_fee')->sum('jumlah');
            $dp = (float) $kk->jadwalTagihans()->where('jenis', 'dp')->sum('jumlah');
            $lama = $bf + $dp + max(0, (float) $kk->harga_deal - ($bfMasuk ? $bf : 0) - ($dpMasuk ? $dp : 0));   // rumus lama: Pelunasan dari harga deal

            $this->assertEqualsWithDelta($lama, $this->breakdown($kk)['total_piutang_konsumen'], 0.01, "{$cara} bf=" . (int) $bfMasuk . ' dp=' . (int) $dpMasuk);
        }
    }

    public function test_pembayaran_tanah_dan_biaya_tambahan_pada_cash_tercatat_terpisah_dan_menentukan_lunas(): void
    {
        $kk = $this->booking();
        $admin = $this->user('superadmin');
        $this->actingAs($admin)->post(route('biaya-tanah.cicilan.store', $kk), ['jumlah' => 8000000, 'tanggal_bayar' => now()->format('Y-m-d')])->assertSessionHasNoErrors();
        $b = $this->breakdown($kk);

        $this->assertSame('lunas', $this->baris($b, 'Biaya Penambahan Tanah')['status']);
        $this->assertSame('belum_bayar', $this->baris($b, 'Pelunasan')['status']);
        $this->assertEquals(8000000 + 1000000, $b["total_terbayar_konsumen"]);   // tanah 8 jt + Booking Fee 1 jt (otomatis lunas saat booking)
        $this->assertFalse(KavlingKonsumen::lunasPenuh($b));
    }

    public function test_rekap_piutang_cash_punya_kolom_biaya_tanah_dan_biaya_tambahan(): void
    {
        $kk = $this->booking();
        $kolom = collect(RekapKekuranganBank::kolomTersedia([$kk->id], RekapKekuranganBank::TAMPILAN_CASH))->pluck('key')->all();

        $this->assertContains('k:biaya_tanah', $kolom);
        $this->assertContains('k:bt:kavling hook', $kolom);
        $this->assertContains('k:pelunasan', $kolom);
        $this->assertLessThan(array_search('k:pelunasan', $kolom), array_search('k:biaya_tanah', $kolom));
    }

    // ── Perintah perbaikan jadwal cash lama ──

    /** Menyusun kondisi "jadwal lama": Pelunasan masih mengandung tanah + biaya tambahan (13 jt). */
    private function jadwalLama(int $cicilan = 1): KavlingKonsumen
    {
        $kk = $this->booking([], null, $cicilan > 1 ? 'cash_bertahap' : 'cash');
        $kk->jadwalTagihans()->where('jenis', 'pelunasan')->delete();
        $total = 189000000 + 13000000;                                              // rumus lama: harga deal - BF - DP
        for ($i = 1; $i <= $cicilan; $i++) {
            $kk->jadwalTagihans()->create(['jenis' => 'pelunasan', 'nomor_cicilan' => $i, 'jumlah' => $total / $cicilan, 'tanggal_jatuh_tempo' => null, 'status' => 'belum_bayar']);
        }

        return $kk->fresh();
    }

    public function test_perintah_pratinjau_tidak_mengubah_apa_pun(): void
    {
        $kk = $this->jadwalLama();

        $this->artisan('pelunasan:pisah-komponen')->expectsOutputToContain('1 transaksi bisa disesuaikan')->assertSuccessful();

        $this->assertEquals(202000000, $kk->jadwalTagihans()->where('jenis', 'pelunasan')->sum('jumlah'));
    }

    public function test_perintah_menerapkan_pengurangan_dari_cicilan_terakhir_yang_belum_dibayar(): void
    {
        $kk = $this->jadwalLama(2);                                                // 2 x 101 jt
        $pertama = $kk->jadwalTagihans()->where('jenis', 'pelunasan')->orderBy('nomor_cicilan')->first();
        $pertama->update(['status' => 'lunas']);                                    // tak boleh disentuh (sudah dibayar)
        $pertama->forceFill(['pembayaran_konsumen_id' => $kk->pembayarans()->create(['jenis' => 'pelunasan', 'jumlah' => 101000000, 'tanggal_bayar' => now()])->id])->save();

        $this->artisan('pelunasan:pisah-komponen --terapkan')->assertSuccessful();

        $rows = $kk->jadwalTagihans()->where('jenis', 'pelunasan')->orderBy('nomor_cicilan')->get();
        $this->assertEquals(101000000, $rows[0]->jumlah);
        $this->assertEquals(88000000, $rows[1]->jumlah);                            // 101 jt - 13 jt
        $this->assertEquals(213000000, $this->breakdown($kk)['total_piutang_konsumen']);

        // Dijalankan ulang: sudah beres, tidak ada yang berubah lagi.
        $this->artisan('pelunasan:pisah-komponen --terapkan')->expectsOutputToContain('0 transaksi bisa disesuaikan')->assertSuccessful();
        $this->assertEquals(88000000, $kk->jadwalTagihans()->where('jenis', 'pelunasan')->orderBy('nomor_cicilan')->get()[1]->jumlah);
    }

    public function test_perintah_menghapus_cicilan_yang_habis_dan_melaporkan_kasus_yang_tak_bisa_otomatis(): void
    {
        // Cicilan terakhir (kecil) habis dipotong -> dihapus; sisa pengurangan ke cicilan sebelumnya.
        $kk = $this->jadwalLama(1);
        $kk->jadwalTagihans()->where('jenis', 'pelunasan')->delete();
        $kk->jadwalTagihans()->create(['jenis' => 'pelunasan', 'nomor_cicilan' => 1, 'jumlah' => 195000000, 'tanggal_jatuh_tempo' => null, 'status' => 'belum_bayar']);
        $kk->jadwalTagihans()->create(['jenis' => 'pelunasan', 'nomor_cicilan' => 2, 'jumlah' => 7000000, 'tanggal_jatuh_tempo' => null, 'status' => 'belum_bayar']);

        $this->artisan('pelunasan:pisah-komponen --terapkan')->assertSuccessful();
        $rows = $kk->jadwalTagihans()->where('jenis', 'pelunasan')->orderBy('nomor_cicilan')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(189000000, $rows[0]->jumlah);

        // Pelunasan sudah dibayar penuh: tidak ada cicilan bebas untuk dikurangi -> manual.
        $lunas = $this->jadwalLama();
        $j = $lunas->jadwalTagihans()->where('jenis', 'pelunasan')->firstOrFail();
        $j->update(['status' => 'lunas', 'pembayaran_konsumen_id' => $lunas->pembayarans()->create(['jenis' => 'pelunasan', 'jumlah' => 202000000, 'tanggal_bayar' => now()])->id]);
        // Pelunasan diubah manual (tidak sama dengan rumus lama/baru) -> manual.
        $manual = $this->jadwalLama();
        $manual->jadwalTagihans()->where('jenis', 'pelunasan')->update(['jumlah' => 150000000]);

        $this->artisan('pelunasan:pisah-komponen --terapkan')
            ->expectsOutputToContain('2 perlu ditangani manual')->assertSuccessful();
        $this->assertEquals(202000000, $lunas->jadwalTagihans()->where('jenis', 'pelunasan')->sum('jumlah'));
        $this->assertEquals(150000000, $manual->jadwalTagihans()->where('jenis', 'pelunasan')->sum('jumlah'));
    }
}
