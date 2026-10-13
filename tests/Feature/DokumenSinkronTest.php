<?php

namespace Tests\Feature;

use App\Models\DokumenKonsumen;
use App\Models\DokumenTemplate;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\SinkronDokumen;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Template pemberkasan: syarat berkas kondisional, progress (wajib + kondisional berlaku) dan Sinkronkan ke konsumen. */
class DokumenSinkronTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private Konsumen $konsumen;
    private KavlingKonsumen $trx;
    private DokumenTemplate $ktp;
    private DokumenTemplate $ktpPasangan;
    private DokumenTemplate $nibUsaha;
    private DokumenTemplate $foto;
    private DokumenTemplate $suratManual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        DokumenTemplate::query()->delete();                       // seeder bisa membawa template bawaan; uji dengan set sendiri
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $this->konsumen = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812', 'status_pernikahan' => 'menikah', 'pekerjaan' => 'karyawan_swasta']);
        $this->trx = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $this->konsumen->id, 'status' => 'active',
            'status_penjualan' => 'pemberkasan', 'cara_bayar' => 'kpr_subsidi', 'tanggal_booking' => now()]);

        $this->ktp = $this->template('KTP', 'wajib');
        $this->ktpPasangan = $this->template('KTP Pasangan', 'kondisional', ['syarat_pernikahan' => ['menikah']]);
        $this->nibUsaha = $this->template('NIB Usaha', 'kondisional', ['syarat_pekerjaan' => ['wirausaha']]);
        $this->foto = $this->template('Foto', 'opsional');
        $this->suratManual = $this->template('Surat Keterangan', 'kondisional');
    }

    private function template(string $nama, string $sifat, array $syarat = [], string $cara = 'kpr_subsidi'): DokumenTemplate
    {
        return DokumenTemplate::create(['cara_bayar' => $cara, 'nama_dokumen' => $nama, 'sifat' => $sifat, 'urutan' => (int) DokumenTemplate::where('cara_bayar', $cara)->max('urutan') + 1] + $syarat);
    }

    /** Salin semua template ke konsumen seperti saat booking. */
    private function booking(): void
    {
        foreach (DokumenTemplate::where('cara_bayar', 'kpr_subsidi')->orderBy('urutan')->get() as $t) {
            DokumenKonsumen::create(['kavling_konsumen_id' => $this->trx->id] + DokumenKonsumen::atributDariTemplate($t, $this->konsumen));
        }
        $this->trx = $this->trx->fresh();
    }

    private function berkas(string $nama): ?DokumenKonsumen
    {
        return DokumenKonsumen::where('kavling_konsumen_id', $this->trx->id)->where('nama_dokumen', $nama)->first();
    }

    public function test_syarat_berlaku_mengikuti_data_konsumen_dan_data_kosong_dianggap_berlaku(): void
    {
        $this->assertTrue($this->ktpPasangan->berlakuUntuk($this->konsumen));                                  // menikah
        $this->assertFalse($this->ktpPasangan->berlakuUntuk(new Konsumen(['status_pernikahan' => 'belum_menikah'])));
        $this->assertTrue($this->ktpPasangan->berlakuUntuk(new Konsumen()));                                   // belum diisi: tetap ditagih
        $this->assertFalse($this->nibUsaha->berlakuUntuk($this->konsumen));                                    // karyawan, bukan wirausaha
        $this->assertTrue($this->nibUsaha->berlakuUntuk(new Konsumen(['pekerjaan' => 'wirausaha'])));
        $this->assertFalse($this->suratManual->berlakuUntuk($this->konsumen));                                 // kondisional tanpa syarat: tidak dihitung
        $this->assertTrue($this->ktp->berlakuUntuk($this->konsumen));                                          // bukan kondisional

        $dua = $this->template('Dua Syarat', 'kondisional', ['syarat_pernikahan' => ['menikah'], 'syarat_pekerjaan' => ['wirausaha']]);
        $this->assertFalse($dua->berlakuUntuk($this->konsumen));                                               // keduanya harus terpenuhi
        $this->assertTrue($dua->berlakuUntuk(new Konsumen(['status_pernikahan' => 'menikah', 'pekerjaan' => 'wirausaha'])));
        $this->assertSame('Menikah · Wirausaha', $dua->syaratLabel());
    }

    public function test_booking_menandai_berkas_kondisional_yang_tidak_berlaku(): void
    {
        $this->booking();

        $this->assertTrue($this->berkas('KTP Pasangan')->berlaku);
        $this->assertFalse($this->berkas('NIB Usaha')->berlaku);
        $this->assertTrue($this->berkas('NIB Usaha')->tidak_berlaku);
        $this->assertFalse($this->berkas('Surat Keterangan')->berlaku);                // tanpa syarat: tidak dihitung
    }

    public function test_progress_hanya_menghitung_wajib_dan_kondisional_yang_berlaku(): void
    {
        $this->booking();                                        // dihitung: KTP (wajib) dan KTP Pasangan (menikah)
        $this->berkas('KTP')->update(['status' => 'sudah_ada']);

        $p = $this->trx->fresh()->progress_berkas;
        $this->assertSame([50, 1, 2], [(int) $p['persen'], $p['ada'], $p['total']]);
        $this->assertFalse($this->trx->fresh()->dokumen_wajib_lengkap);

        $this->berkas('KTP Pasangan')->update(['status' => 'sudah_ada']);
        $this->assertSame(100, (int) $this->trx->fresh()->progress_berkas['persen']);
        $this->assertTrue($this->trx->fresh()->dokumen_wajib_lengkap);        // Foto (opsional), NIB (tidak berlaku), Surat (tanpa syarat) tidak menghalangi
    }

    public function test_ubah_status_pernikahan_konsumen_menyesuaikan_berkas_kondisional(): void
    {
        $this->booking();
        $this->assertTrue($this->berkas('KTP Pasangan')->berlaku);

        $this->actingAs($this->admin)->put(route('konsumens.update', $this->konsumen), ['nama' => 'Budi', 'status_pernikahan' => 'belum_menikah', 'pekerjaan' => 'wirausaha'])->assertSessionDoesntHaveErrors();

        $this->assertFalse($this->berkas('KTP Pasangan')->berlaku);
        $this->assertTrue($this->berkas('NIB Usaha')->berlaku);
    }

    public function test_sinkron_menambah_berkas_baru_tanpa_menimpa_status_yang_sudah_diisi(): void
    {
        $this->booking();
        $this->berkas('KTP')->update(['status' => 'sudah_ada']);
        $this->template('Kartu Keluarga', 'wajib');

        $rencana = SinkronDokumen::rencana($this->trx);
        $this->assertSame(['tambah'], array_unique(array_column($rencana, 'aksi')));
        $this->assertSame('Kartu Keluarga', $rencana[0]['nama']);

        $hasil = SinkronDokumen::terapkan($this->trx);

        $this->assertSame(1, $hasil['tambah']);
        $this->assertSame('belum_ada', $this->berkas('Kartu Keluarga')->status);
        $this->assertSame('sudah_ada', $this->berkas('KTP')->status);
        $this->assertSame([], SinkronDokumen::rencana($this->trx->fresh()));      // sudah selaras
    }

    public function test_sinkron_mengubah_sifat_dan_nama_lewat_tautan_template_tanpa_menggandakan(): void
    {
        $this->booking();
        $this->berkas('Foto')->update(['status' => 'sudah_ada']);
        $this->ktp->update(['sifat' => 'kondisional', 'nama_dokumen' => 'KTP Pemohon']);     // wajib → kondisional + ganti nama
        $this->foto->update(['sifat' => 'wajib']);

        $hasil = SinkronDokumen::terapkan($this->trx->fresh());

        $this->assertSame(0, $hasil['tambah']);
        $this->assertSame(2, $hasil['ubah']);
        $this->assertSame('kondisional', $this->berkas('KTP Pemohon')->sifat);
        $this->assertNull($this->berkas('KTP'));
        $this->assertSame('wajib', $this->berkas('Foto')->sifat);
        $this->assertSame('sudah_ada', $this->berkas('Foto')->status);                       // status tetap
        $this->assertSame(5, DokumenKonsumen::where('kavling_konsumen_id', $this->trx->id)->count());
    }

    public function test_template_dihapus_berkas_belum_diisi_dihapus_dan_yang_sudah_diisi_dipertahankan(): void
    {
        $this->booking();
        $this->berkas('Foto')->update(['status' => 'sudah_ada']);
        $this->foto->delete();
        $this->suratManual->delete();

        $rencana = collect(SinkronDokumen::rencana($this->trx->fresh()))->keyBy('nama');
        $this->assertSame('pertahankan', $rencana['Foto']['aksi']);
        $this->assertSame('hapus', $rencana['Surat Keterangan']['aksi']);

        $hasil = SinkronDokumen::terapkan($this->trx->fresh());

        $this->assertSame(1, $hasil['hapus']);
        $this->assertNotNull($this->berkas('Foto'));                  // sudah diisi: tidak hilang
        $this->assertNull($this->berkas('Surat Keterangan'));
    }

    public function test_perubahan_syarat_template_menyesuaikan_berlaku_lewat_sinkron(): void
    {
        $this->booking();
        $this->nibUsaha->update(['syarat_pekerjaan' => ['karyawan_swasta']]);                 // sekarang berlaku untuk karyawan

        $hasil = SinkronDokumen::terapkan($this->trx->fresh());

        $this->assertSame(1, $hasil['ubah']);
        $this->assertTrue($this->berkas('NIB Usaha')->berlaku);
    }

    public function test_endpoint_sinkron_konsumen_pratinjau_lalu_terapkan_dan_izin(): void
    {
        $this->booking();
        $this->template('Kartu Keluarga', 'wajib');

        $this->actingAs($this->admin)->postJson(route('dokumen.sinkron', $this->trx), ['simulasi' => true])
            ->assertOk()->assertJsonPath('ringkas.tambah', 1)->assertJsonPath('butir.0.nama', 'Kartu Keluarga');
        $this->assertNull($this->berkas('Kartu Keluarga'));                                    // pratinjau tidak menyimpan

        $this->actingAs($this->admin)->post(route('dokumen.sinkron', $this->trx))->assertSessionHas('success');
        $this->assertNotNull($this->berkas('Kartu Keluarga'));

        $pelaksana = User::factory()->create();
        $pelaksana->assignRole('pelaksana_lapangan');
        $this->actingAs($pelaksana)->post(route('dokumen.sinkron', $this->trx))->assertStatus(403);
    }

    public function test_sinkron_massal_dari_pengaturan_menjangkau_semua_transaksi_aktif(): void
    {
        $this->booking();
        $this->template('Kartu Keluarga', 'wajib');
        $this->trx->update(['status_penjualan' => 'pemberkasan']);

        $r = $this->actingAs($this->admin)->postJson(route('pengaturan.dokumen-templates.sinkron'), ['simulasi' => true])->assertOk();
        $r->assertJsonPath('transaksi_terdampak', 1)->assertJsonPath('total.tambah', 1);
        $this->assertNull($this->berkas('Kartu Keluarga'));

        $this->actingAs($this->admin)->post(route('pengaturan.dokumen-templates.sinkron'))->assertSessionHas('success');
        $this->assertNotNull($this->berkas('Kartu Keluarga'));

        // Transaksi batal tidak disentuh.
        $this->trx->update(['status_penjualan' => 'batal', 'status' => 'cancelled']);
        $this->template('Surat Baru', 'wajib');
        $this->actingAs($this->admin)->post(route('pengaturan.dokumen-templates.sinkron'));
        $this->assertNull($this->berkas('Surat Baru'));
    }

    public function test_berkas_kondisional_wajib_punya_syarat_saat_dibuat_atau_dijadikan_kondisional(): void
    {
        $store = route('pengaturan.dokumen-templates.store');

        $this->actingAs($this->admin)->post($store, ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Tanpa Syarat', 'sifat' => 'kondisional'])->assertSessionHasErrors('syarat_pernikahan');
        $this->assertNull(DokumenTemplate::where('nama_dokumen', 'Tanpa Syarat')->first());

        $this->actingAs($this->admin)->post($store, ['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Dengan Syarat', 'sifat' => 'kondisional', 'syarat_pekerjaan' => ['wirausaha']])->assertSessionDoesntHaveErrors();
        $this->assertSame(['wirausaha'], DokumenTemplate::where('nama_dokumen', 'Dengan Syarat')->first()->syaratPekerjaan());

        // Mengubah sifat Wajib → Kondisional tanpa syarat ditolak.
        $this->actingAs($this->admin)->patch(route('pengaturan.dokumen-templates.update', $this->ktp), ['nama_dokumen' => 'KTP', 'sifat' => 'kondisional'])->assertSessionHasErrors('syarat_pernikahan');
        $this->assertSame('wajib', $this->ktp->fresh()->sifat);

        // Template lama (kondisional tanpa syarat) tetap bisa diganti namanya tanpa dipaksa memberi syarat.
        $this->actingAs($this->admin)->patch(route('pengaturan.dokumen-templates.update', $this->suratManual), ['nama_dokumen' => 'Surat Keterangan Baru', 'sifat' => 'kondisional'])->assertSessionDoesntHaveErrors();
        $this->assertSame('Surat Keterangan Baru', $this->suratManual->fresh()->nama_dokumen);
        // …tetapi bila syarat dikirim kosong untuk kondisional, ditolak.
        $this->actingAs($this->admin)->patch(route('pengaturan.dokumen-templates.update', $this->suratManual), ['nama_dokumen' => 'X', 'sifat' => 'kondisional', 'syarat_pernikahan' => [], 'syarat_pekerjaan' => []])->assertSessionHasErrors('syarat_pernikahan');
    }

    public function test_template_menyimpan_syarat_membersihkannya_saat_bukan_kondisional_dan_tidak_hilang_saat_ganti_nama(): void
    {
        $url = route('pengaturan.dokumen-templates.update', $this->ktpPasangan);

        $this->actingAs($this->admin)->patch($url, ['nama_dokumen' => 'KTP Pasangan', 'sifat' => 'kondisional', 'syarat_pernikahan' => ['menikah', 'cerai_hidup'], 'syarat_pekerjaan' => ['wirausaha']])->assertSessionDoesntHaveErrors();
        $this->assertSame(['menikah', 'cerai_hidup'], $this->ktpPasangan->fresh()->syaratPernikahan());
        $this->assertSame(['wirausaha'], $this->ktpPasangan->fresh()->syaratPekerjaan());

        $this->actingAs($this->admin)->patch($url, ['nama_dokumen' => 'KTP Pasangan Sah', 'sifat' => 'kondisional'])->assertSessionDoesntHaveErrors();   // tanpa kirim syarat
        $this->assertSame(['menikah', 'cerai_hidup'], $this->ktpPasangan->fresh()->syaratPernikahan());

        $this->actingAs($this->admin)->patch($url, ['nama_dokumen' => 'KTP Pasangan Sah', 'sifat' => 'wajib'])->assertSessionDoesntHaveErrors();
        $this->assertSame([], $this->ktpPasangan->fresh()->syaratPernikahan());                                    // bukan kondisional: syarat dibersihkan

        $this->actingAs($this->admin)->patch($url, ['nama_dokumen' => 'X', 'sifat' => 'kondisional', 'syarat_pernikahan' => ['ngawur']])->assertSessionHasErrors('syarat_pernikahan.0');
    }
}
