<?php

namespace Tests\Feature;

use App\Models\BiayaTambahanPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Project;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\PromoPresetLawan;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\SumberLead;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Promo per biaya: tiap baris biaya (Harga Jual, Kelebihan Tanah, tiap Biaya Tambahan, Titipan Biaya Akad) memilih promonya sendiri dan
 * hanya berlaku bila promo itu memang punya lawan baris tersebut. Termasuk cadangan untuk form lama (hanya promo_preset_id) dan
 * perintah promo:isi-per-biaya untuk transaksi lama.
 */
class PromoPerBiayaTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private TipeUnitPreset $tipe;
    private SalesAgent $agen;
    private SumberLead $lead;
    private SkemaDpPreset $skema;
    private ProgramAllInPreset $allIn;          // Rp 11 jt, titipan bruto Rp 11 jt
    private BiayaTambahanPreset $hook;
    private PromoPreset $promoHargaJual;
    private PromoPreset $promoTanah;
    private PromoPreset $promoTitipan;
    private PromoPreset $promoHook;
    private PromoPreset $promoGabungan;         // lawan: harga jual + tanah + hook
    private PromoPreset $promoLabel;            // tanpa lawan
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->agen = SalesAgent::create(['nama' => 'Agen 1', 'tipe' => 'sales', 'is_active' => true]);
        $this->lead = SumberLead::create(['nama' => 'Instagram', 'is_referral' => false, 'is_active' => true]);
        $this->skema = SkemaDpPreset::create([
            'nama' => 'KPR UTJ 1jt DP 1,66jt', 'cara_bayar' => 'kpr_subsidi',
            'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal', 'booking_fee_nilai' => 1000000, 'booking_fee_tenor' => 1, 'booking_fee_masuk_harga_jual' => true,
            'dp_aktif' => true, 'dp_tipe' => 'nominal', 'dp_nilai' => 1660000, 'dp_tenor' => 1, 'dp_masuk_harga_jual' => true,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_netto',
        ]);
        $this->allIn = ProgramAllInPreset::create(['nama' => 'All In 11jt', 'nominal' => 11000000, 'include_booking_fee' => false, 'include_dp' => false, 'is_active' => true]);
        $this->hook = BiayaTambahanPreset::create(['nama' => 'Kavling Hook', 'is_active' => true]);

        $this->promoHargaJual = $this->promo('Diskon Harga', [['jenis' => PromoPresetLawan::HARGA_JUAL]]);
        $this->promoTanah = $this->promo('Free Tanah', [['jenis' => PromoPresetLawan::TANAH]]);
        $this->promoTitipan = $this->promo('Diskon Biaya Akad', [['jenis' => PromoPresetLawan::TITIPAN_BIAYA_AKAD]]);
        $this->promoHook = $this->promo('Free Hook', [['jenis' => PromoPresetLawan::BIAYA_TAMBAHAN, 'biaya_tambahan_preset_id' => $this->hook->id]]);
        $this->promoGabungan = $this->promo('Promo Gabungan', [
            ['jenis' => PromoPresetLawan::HARGA_JUAL], ['jenis' => PromoPresetLawan::TANAH],
            ['jenis' => PromoPresetLawan::BIAYA_TAMBAHAN, 'biaya_tambahan_preset_id' => $this->hook->id],
        ]);
        $this->promoLabel = $this->promo('Free Kanopi', []);
    }

    private function promo(string $nama, array $lawan): PromoPreset
    {
        $p = PromoPreset::create(['nama' => $nama, 'is_active' => true]);
        foreach ($lawan as $l) $p->lawan()->create($l);

        return $p;
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->projects()->attach($this->project->id);

        return $u;
    }

    private function unit(): Kavling
    {
        $this->nomor++;

        return Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor,
            'status_jual' => 'available', 'harga' => 166000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
    }

    private function tanah(): array
    {
        return ['biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_mode' => 'nominal', 'biaya_kelebihan_tanah_nominal_input' => 10000000];
    }

    private function booking(array $ubah = []): KavlingKonsumen
    {
        $unit = $this->unit();
        $this->actingAs($this->user('admin_sales'))->post(route('bookings.store', $unit), array_merge([
            'tanggal_booking' => now()->format('Y-m-d'), 'konsumen_nama' => 'Budi ' . $this->nomor, 'konsumen_no_hp' => '0812', 'konsumen_nik' => '32010000000000' . str_pad((string) $this->nomor, 2, '0', STR_PAD_LEFT),
            'konsumen_pekerjaan' => 'wirausaha', 'konsumen_status_pernikahan' => 'menikah', 'konsumen_sumber_lead_id' => $this->lead->id,
            'sales_agent_id' => $this->agen->id, 'cara_bayar' => 'kpr_subsidi', 'skema_dp_preset_id' => $this->skema->id,
            'program_all_in_preset_id' => $this->allIn->id,
        ], $ubah))->assertSessionHasNoErrors();

        return KavlingKonsumen::where('kavling_id', $unit->id)->firstOrFail();
    }

    private function ubahRincian(KavlingKonsumen $kk, array $ubah)
    {
        return $this->actingAs($this->user('spv'))->patch(route('bookings.rincian-pesanan', $kk), array_merge([
            'biaya_kelebihan_tanah_aktif' => false, 'biaya_tambahan' => [], 'promo_preset_id' => null,
            'program_all_in_preset_id' => $this->allIn->id,
        ], $ubah));
    }

    public function test_tiap_biaya_memakai_promo_berbeda_dan_tersimpan_per_baris(): void
    {
        $kk = $this->booking($this->tanah() + [
            'promo_preset_id' => null,
            'diskon_promo_id' => $this->promoHargaJual->id, 'diskon_mode' => 'nominal', 'diskon_nilai' => 5000000,
            'promo_tanah_id' => $this->promoTanah->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100],
            'promo_titipan_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 3000000],
            'biaya_tambahan' => [['preset_id' => $this->hook->id, 'nominal' => 4000000, 'promo_preset_id' => $this->promoHook->id, 'potongan' => ['mode' => 'persen', 'nilai' => 50]]],
        ]);

        $this->assertSame($this->promoHargaJual->id, $kk->diskon_promo_id);
        $this->assertSame($this->promoTanah->id, $kk->biaya_kelebihan_tanah_promo_id);
        $this->assertSame($this->promoTitipan->id, $kk->titipan_biaya_akad_promo_id);
        $this->assertSame($this->promoHook->id, $kk->biayaTambahans->first()->promo_preset_id);
        $this->assertNull($kk->promo_preset_id);

        $this->assertEquals(10000000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertEquals(2000000, $kk->biayaTambahans->first()->potongan);
        $this->assertEquals(3000000, $kk->titipan_biaya_akad_potongan);
        $this->assertEquals(5000000, $kk->diskon_nominal);
        // 166 jt + (10 jt - 10 jt) + (4 jt - 2 jt) = 168 jt, dikurangi diskon 5 jt
        $this->assertEquals(163000000, $kk->harga_deal);
    }

    public function test_promo_yang_bukan_lawan_baris_itu_diabaikan(): void
    {
        $kk = $this->booking($this->tanah() + [
            'promo_tanah_id' => $this->promoTitipan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100],       // promoTitipan bukan lawan tanah
            'diskon_promo_id' => $this->promoTanah->id, 'diskon_mode' => 'nominal', 'diskon_nilai' => 1000000,         // promoTanah bukan lawan harga jual
        ]);

        $this->assertEquals(0, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertNull($kk->biaya_kelebihan_tanah_promo_id);
        $this->assertEquals(0, $kk->diskon_nominal);
        $this->assertNull($kk->diskon_promo_id);
    }

    public function test_kunci_baru_yang_dikirim_kosong_tidak_memakai_promo_lama(): void
    {
        // promo_preset_id (label) adalah promo yang punya lawan tanah, tetapi baris tanah memilih "tanpa promo" secara eksplisit.
        $kk = $this->booking($this->tanah() + [
            'promo_preset_id' => $this->promoTanah->id, 'promo_tanah_id' => null, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100],
        ]);

        $this->assertEquals(0, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertNull($kk->biaya_kelebihan_tanah_promo_id);
    }

    public function test_form_lama_hanya_promo_tunggal_masih_berlaku_dan_mengisi_referensi(): void
    {
        $kk = $this->booking($this->tanah() + [
            'promo_preset_id' => $this->promoGabungan->id,
            'potongan_tanah' => ['mode' => 'persen', 'nilai' => 50],
            'diskon_mode' => 'nominal', 'diskon_nilai' => 2000000,
            'biaya_tambahan' => [['preset_id' => $this->hook->id, 'nominal' => 4000000, 'potongan' => ['mode' => 'persen', 'nilai' => 25]]],
        ]);

        $this->assertEquals(5000000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertSame($this->promoGabungan->id, $kk->biaya_kelebihan_tanah_promo_id);
        $this->assertSame($this->promoGabungan->id, $kk->diskon_promo_id);
        $this->assertSame($this->promoGabungan->id, $kk->biayaTambahans->first()->promo_preset_id);
        $this->assertEquals(1000000, $kk->biayaTambahans->first()->potongan);
        $this->assertNull($kk->titipan_biaya_akad_promo_id);                // promoGabungan tidak punya lawan titipan
    }

    public function test_ubah_rincian_mengganti_dan_menghapus_promo_per_biaya(): void
    {
        $kk = $this->booking($this->tanah() + ['promo_tanah_id' => $this->promoTanah->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 100]]);
        $this->assertEquals(10000000, $kk->biaya_kelebihan_tanah_potongan);

        // Pindah ke promo gabungan dengan potongan 30%, dan tambahkan promo di titipan.
        $this->ubahRincian($kk, $this->tanah() + [
            'promo_tanah_id' => $this->promoGabungan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 30],
            'promo_titipan_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 2000000],
        ])->assertSessionHasNoErrors();
        $kk->refresh();
        $this->assertEquals(3000000, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertSame($this->promoGabungan->id, $kk->biaya_kelebihan_tanah_promo_id);
        $this->assertSame($this->promoTitipan->id, $kk->titipan_biaya_akad_promo_id);

        // Hapus promo tanah: potongan hilang, referensi kosong; titipan tetap.
        $this->ubahRincian($kk, $this->tanah() + [
            'promo_tanah_id' => null, 'promo_titipan_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 2000000],
        ])->assertSessionHasNoErrors();
        $kk->refresh();
        $this->assertEquals(0, $kk->biaya_kelebihan_tanah_potongan);
        $this->assertNull($kk->biaya_kelebihan_tanah_promo_id);
        $this->assertSame($this->promoTitipan->id, $kk->titipan_biaya_akad_promo_id);
    }

    public function test_nama_promo_gabungan_memuat_semua_promo_tanpa_duplikat(): void
    {
        $kk = $this->booking($this->tanah() + [
            'promo_preset_id' => $this->promoLabel->id,
            'diskon_promo_id' => $this->promoGabungan->id, 'diskon_mode' => 'nominal', 'diskon_nilai' => 1000000,
            'promo_tanah_id' => $this->promoGabungan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 10],
            'promo_titipan_id' => $this->promoTitipan->id, 'potongan_titipan' => ['mode' => 'nominal', 'nilai' => 1000000],
        ]);

        $kk->load('promoPreset', 'diskonPromo', 'tanahPromo', 'titipanPromo', 'biayaTambahans.promoPreset');
        $this->assertSame('Free Kanopi, Promo Gabungan, Diskon Biaya Akad', $kk->promo_nama_gabungan);
        $this->assertSame('Diskon Biaya Akad', $kk->namaPromoDariId($kk->promoIdTitipan()));
    }

    public function test_perintah_isi_per_biaya_pratinjau_tidak_menulis_lalu_terapkan_mengisi_dan_idempoten(): void
    {
        $kk = $this->booking($this->tanah() + [
            'promo_preset_id' => $this->promoGabungan->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 50],
            'diskon_mode' => 'nominal', 'diskon_nilai' => 2000000,
            'biaya_tambahan' => [['preset_id' => $this->hook->id, 'nominal' => 4000000, 'potongan' => ['mode' => 'persen', 'nilai' => 25]]],
        ]);
        // Jadikan transaksi "lama": referensi per biaya belum ada.
        DB::table('kavling_konsumen')->where('id', $kk->id)->update(['diskon_promo_id' => null, 'biaya_kelebihan_tanah_promo_id' => null]);
        DB::table('kavling_konsumen_biaya_tambahan')->where('kavling_konsumen_id', $kk->id)->update(['promo_preset_id' => null]);
        $hargaDeal = (float) $kk->fresh()->harga_deal;

        $this->artisan('promo:isi-per-biaya')->expectsOutputToContain('PRATINJAU: 1 transaksi punya potongan promo, 3 referensi akan diisi')->assertExitCode(0);
        $this->assertNull($kk->fresh()->diskon_promo_id);                      // pratinjau tidak menulis

        $this->artisan('promo:isi-per-biaya', ['--terapkan' => true])->assertExitCode(0);
        $baru = $kk->fresh(['biayaTambahans']);
        $this->assertSame($this->promoGabungan->id, $baru->diskon_promo_id);
        $this->assertSame($this->promoGabungan->id, $baru->biaya_kelebihan_tanah_promo_id);
        $this->assertSame($this->promoGabungan->id, $baru->biayaTambahans->first()->promo_preset_id);
        $this->assertSame($this->promoGabungan->id, $baru->promo_preset_id);   // promo lama tidak disentuh
        $this->assertEquals($hargaDeal, (float) $baru->harga_deal);            // angka tidak berubah

        $this->artisan('promo:isi-per-biaya', ['--terapkan' => true])->expectsOutputToContain('0 referensi akan diisi')->assertExitCode(0);
    }

    public function test_perintah_isi_per_biaya_melaporkan_kasus_yang_tidak_cocok_dan_membiarkannya_kosong(): void
    {
        $kk = $this->booking($this->tanah() + ['promo_preset_id' => $this->promoTanah->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 50]]);
        // Data lama yang janggal: ada diskon tetapi promo transaksi (Free Tanah) bukan lawan Harga Jual.
        DB::table('kavling_konsumen')->where('id', $kk->id)->update(['diskon_nominal' => 1000000, 'diskon_mode' => 'nominal', 'diskon_nilai' => 1000000, 'diskon_promo_id' => null, 'biaya_kelebihan_tanah_promo_id' => null]);

        $this->artisan('promo:isi-per-biaya', ['--terapkan' => true])
            ->expectsOutputToContain('Ditinjau')
            ->assertExitCode(0);

        $baru = $kk->fresh();
        $this->assertSame($this->promoTanah->id, $baru->biaya_kelebihan_tanah_promo_id);   // yang cocok terisi
        $this->assertNull($baru->diskon_promo_id);                                         // yang tidak cocok dibiarkan
    }

    public function test_keterangan_titipan_termasuk_dp_atau_bf_mengikuti_preset_all_in(): void
    {
        $allInDp = ProgramAllInPreset::create(['nama' => 'All In incl DP', 'nominal' => 11000000, 'include_booking_fee' => false, 'include_dp' => true, 'is_active' => true]);
        $allInKeduanya = ProgramAllInPreset::create(['nama' => 'All In incl BF DP', 'nominal' => 11000000, 'include_booking_fee' => true, 'include_dp' => true, 'is_active' => true]);

        $tanpa = $this->booking();                                                                                  // All In 11 jt tanpa include
        $this->assertNull($tanpa->load('programAllInPreset')->titipanTermasuk(1660000));

        $dp = $this->booking(['program_all_in_preset_id' => $allInDp->id]);
        $this->assertSame('DP', $dp->load('programAllInPreset')->titipanTermasuk(1660000));
        $this->assertNull($dp->titipanTermasuk(0));                                                                 // tanpa DP tidak ada yang dikurangi

        $dua = $this->booking(['program_all_in_preset_id' => $allInKeduanya->id]);
        $this->assertSame('Booking Fee & DP', $dua->load('programAllInPreset')->titipanTermasuk(1660000));
    }

    public function test_transaksi_lama_belum_diisi_tetap_terbaca_lewat_promo_tunggal_lama(): void
    {
        $kk = $this->booking($this->tanah() + ['promo_preset_id' => $this->promoTanah->id, 'potongan_tanah' => ['mode' => 'persen', 'nilai' => 50]]);
        DB::table('kavling_konsumen')->where('id', $kk->id)->update(['biaya_kelebihan_tanah_promo_id' => null]);

        $kk = $kk->fresh();
        $this->assertSame($this->promoTanah->id, $kk->promoIdTanah());             // cadangan: promo lama karena ada potongan
        $this->assertNull($kk->promoIdDiskon());                                   // tidak ada diskon, jadi tidak ada promo di baris itu
    }
}
