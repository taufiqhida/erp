<?php

namespace Tests\Feature;

use App\Models\BankRekananPreset;
use App\Models\DajamSbumPreset;
use App\Models\DeveloperProfile;
use App\Models\DokumenTemplate;
use App\Models\Kontraktor;
use App\Models\NotarisPreset;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\StatusColor;
use App\Models\SumberLead;
use App\Models\User;
use App\Support\PindahMaster;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Pindah Master Data (ekspor JSON → impor dengan pratinjau) dan perintah buat-superadmin. */
class PindahMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');

        // Profil Developer = baris tunggal ber-id 1 (getSingleton). Di database uji id auto-increment tidak ikut
        // ter-reset antar tes, jadi baris itu dibuat eksplisit supaya getSingleton selalu menemukannya.
        DeveloperProfile::query()->delete();
        DeveloperProfile::unguarded(fn () => DeveloperProfile::create(['id' => 1, 'nama_developer' => 'Nama Developer']));
    }

    /** Isi semua master seperti di server asal (staging). */
    private function isiSumber(): void
    {
        SumberLead::create(['nama' => 'Instagram', 'keterangan' => 'IG iklan', 'is_referral' => false, 'is_active' => true]);
        SumberLead::create(['nama' => 'Referral', 'keterangan' => null, 'is_referral' => true, 'is_active' => true]);
        BankRekananPreset::create(['nama' => 'BTN', 'nama_pt' => 'PT Bank Tabungan Negara', 'kantor_cabang' => 'Bandung', 'is_active' => true]);
        NotarisPreset::create(['nama' => 'Notaris Andi', 'is_active' => true]);
        SalesAgent::create(['nama' => 'Melza', 'tipe' => 'inhouse', 'is_active' => true]);
        Kontraktor::create(['nama' => 'CV Bangun Jaya', 'no_hp' => '0812', 'is_active' => true]);
        SkemaDpPreset::create(['nama' => 'KPR 5%', 'cara_bayar' => 'kpr_subsidi', 'booking_fee_aktif' => true, 'booking_fee_tipe' => 'nominal',
            'booking_fee_nilai' => 2000000, 'booking_fee_tenor' => 1, 'dp_aktif' => true, 'dp_tipe' => 'persen', 'dp_nilai' => 5, 'dp_tenor' => 3,
            'booking_fee_basis' => 'harga_dasar', 'dp_basis' => 'harga_dasar', 'is_active' => true]);
        PromoPreset::create(['nama' => 'Diskon Akhir Tahun', 'is_active' => true]);
        ProgramAllInPreset::create(['nama' => 'All In Basic', 'nominal' => 15000000, 'include_booking_fee' => true, 'include_dp' => false, 'is_active' => true]);
        DajamSbumPreset::create(['nama' => 'SBUM Pusat', 'kategori' => 'sbum', 'is_active' => true]);
        DokumenTemplate::create(['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'KTP Suami', 'sifat' => 'wajib', 'urutan' => 1]);
        DokumenTemplate::create(['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'Slip Gaji', 'sifat' => 'kondisional', 'urutan' => 2]);
        StatusBangunStage::where('nama', 'Pondasi')->update(['bobot' => 25, 'warna' => '#112233']);
        StatusColor::where('kode', 'available')->update(['warna' => '#00aa00']);

        $p = DeveloperProfile::getSingleton();
        $p->update(['nama_developer' => 'PT Sedaya Utama Sejahtera', 'alamat' => 'Bandung', 'email' => 'info@sedaya.test']);
        $p->banks()->create(['nama_bank' => 'BCA', 'nomor_rekening' => '1234567890', 'atas_nama_rekening' => 'PT Sedaya', 'is_primary' => true]);
    }

    /** Kosongkan semua master seperti server tujuan yang baru (production). */
    private function kosongkanTujuan(): void
    {
        foreach ([SumberLead::class, BankRekananPreset::class, NotarisPreset::class, Kontraktor::class, SkemaDpPreset::class, PromoPreset::class,
                  ProgramAllInPreset::class, DajamSbumPreset::class, DokumenTemplate::class] as $m) {
            $m::query()->delete();
        }
        SalesAgent::withTrashed()->forceDelete();
        StatusBangunStage::where('nama', 'Pondasi')->update(['bobot' => 20, 'warna' => '#f97316']);
        StatusColor::where('kode', 'available')->update(['warna' => '#10b981']);
        $p = DeveloperProfile::getSingleton();
        $p->banks()->delete();
        $p->update(['nama_developer' => 'Nama Developer', 'alamat' => null, 'email' => null]);
    }

    private function semuaKey(): array
    {
        return array_keys(PindahMaster::definisi());
    }

    private function berkas(array $payload): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('master.json', json_encode($payload));
    }

    private function periksa(array $payload, array $opsi = [])
    {
        return $this->actingAs($this->admin)->post(route('pengaturan.pindah-master.periksa'), array_merge([
            'file' => $this->berkas($payload), 'master' => $this->semuaKey(), 'perbarui' => 0,
        ], $opsi));
    }

    private function terapkan()
    {
        return $this->actingAs($this->admin)->post(route('pengaturan.pindah-master.terapkan'));
    }

    // ── Akses & ekspor ───────────────────────────────────────────────────

    public function test_hanya_superadmin_boleh_membuka_dan_mengekspor(): void
    {
        $staf = User::factory()->create();
        $staf->assignRole('admin_sales');

        $this->actingAs($staf)->get(route('pengaturan.pindah-master'))->assertRedirect(route('dashboard'));
        $this->actingAs($staf)->get(route('pengaturan.pindah-master.ekspor', ['master' => ['promo']]))->assertRedirect(route('dashboard'));
        $this->actingAs($this->admin)->get(route('pengaturan.pindah-master'))->assertOk();
    }

    public function test_ekspor_hanya_berisi_master_terpilih_dan_tidak_pernah_data_lain(): void
    {
        $this->isiSumber();

        $r = $this->actingAs($this->admin)->get(route('pengaturan.pindah-master.ekspor', ['master' => ['sumber_lead', 'bank_rekanan', 'users', 'konsumens']]));
        $r->assertOk()->assertHeader('content-disposition');
        $payload = json_decode($r->streamedContent(), true);

        $this->assertSame('ssid-master', $payload['format']);
        $this->assertSame(1, $payload['versi']);
        $this->assertSame(['sumber_lead', 'bank_rekanan'], array_keys($payload['data']));
        $this->assertSame('Instagram', $payload['data']['sumber_lead'][0]['nama']);
        $this->assertStringNotContainsString($this->admin->email, json_encode($payload));
        $this->assertDatabaseHas('activity_log', ['log_name' => 'master']);
    }

    public function test_ekspor_tanpa_pilihan_ditolak(): void
    {
        $this->actingAs($this->admin)->get(route('pengaturan.pindah-master.ekspor'))->assertStatus(422);
    }

    // ── Alur lengkap: staging → production ───────────────────────────────

    public function test_pindah_dari_sumber_ke_tujuan_kosong_menghasilkan_data_yang_sama(): void
    {
        $this->isiSumber();
        $payload = PindahMaster::ekspor($this->semuaKey());
        $this->kosongkanTujuan();

        $this->periksa($payload)->assertSessionHasNoErrors();
        $this->terapkan()->assertSessionHas('success');

        $this->assertSame(['Instagram', 'Referral'], SumberLead::ordered()->pluck('nama')->all());
        $this->assertTrue((bool) SumberLead::where('nama', 'Referral')->value('is_referral'));
        $this->assertSame('PT Bank Tabungan Negara', BankRekananPreset::where('nama', 'BTN')->value('nama_pt'));
        $this->assertSame('inhouse', SalesAgent::where('nama', 'Melza')->value('tipe'));
        $skema = SkemaDpPreset::where('nama', 'KPR 5%')->firstOrFail();
        $this->assertSame('kpr_subsidi', $skema->cara_bayar);
        $this->assertEquals(2000000, $skema->booking_fee_nilai);
        $this->assertEquals(3, $skema->dp_tenor);
        $this->assertEquals(15000000, ProgramAllInPreset::where('nama', 'All In Basic')->value('nominal'));
        $this->assertSame(['KTP Suami', 'Slip Gaji'], DokumenTemplate::where('cara_bayar', 'kpr_subsidi')->orderBy('urutan')->pluck('nama_dokumen')->all());

        $this->assertSame('PT Sedaya Utama Sejahtera', DeveloperProfile::getSingleton()->nama_developer);
        $this->assertSame('1234567890', DeveloperProfile::getSingleton()->banks()->value('nomor_rekening'));
        $this->assertDatabaseHas('activity_log', ['log_name' => 'master', 'description' => 'Impor master data: ' . $this->jumlahBaru() . ' baru, 0 diperbarui']);
    }

    private function jumlahBaru(): int
    {
        // dihitung dari yang kini ada di tabel master yang dikosongkan + profil/bank yang terisi ulang
        return SumberLead::count() + BankRekananPreset::count() + NotarisPreset::count() + SalesAgent::count() + Kontraktor::count()
            + SkemaDpPreset::count() + PromoPreset::count() + ProgramAllInPreset::count() + DajamSbumPreset::count() + DokumenTemplate::count()
            + 1 /* profil */ + 1 /* rekening */;
    }

    public function test_pratinjau_tidak_mengubah_apa_pun_tetapi_menunjukkan_hitungan_yang_sama_dengan_penerapan(): void
    {
        $this->isiSumber();
        $payload = PindahMaster::ekspor($this->semuaKey());
        $this->kosongkanTujuan();
        $sebelum = [SumberLead::count(), BankRekananPreset::count(), SalesAgent::withTrashed()->count(), DokumenTemplate::count()];

        $r = $this->periksa($payload);
        $r->assertSessionHasNoErrors();

        $this->assertSame($sebelum, [SumberLead::count(), BankRekananPreset::count(), SalesAgent::withTrashed()->count(), DokumenTemplate::count()]);
        $preview = session('pindah_master.preview');
        $this->assertTrue($preview['bisa_diterapkan']);
        $baris = collect($preview['baris'])->keyBy('key');
        $this->assertSame(2, $baris['sumber_lead']['baru']);
        $this->assertSame(2, $baris['dokumen_template']['baru']);
        $this->assertSame(1, $baris['bank_rekanan']['baru']);
    }

    public function test_impor_ulang_tidak_menggandakan(): void
    {
        $this->isiSumber();
        $payload = PindahMaster::ekspor($this->semuaKey());
        $this->kosongkanTujuan();

        $this->periksa($payload);
        $this->terapkan();
        $hitung = [SumberLead::count(), BankRekananPreset::count(), DokumenTemplate::count(), DeveloperProfile::getSingleton()->banks()->count()];

        $this->periksa($payload);
        $this->terapkan();

        $this->assertSame($hitung, [SumberLead::count(), BankRekananPreset::count(), DokumenTemplate::count(), DeveloperProfile::getSingleton()->banks()->count()]);
        $baris = collect(session('pindah_master.preview.baris') ?? [])->keyBy('key');
        $this->assertEmpty($baris); // sesi sudah dibersihkan setelah diterapkan
    }

    // ── Aturan pencocokan & perbarui ─────────────────────────────────────

    public function test_nama_dicocokkan_tanpa_membedakan_huruf_besar_kecil(): void
    {
        BankRekananPreset::create(['nama' => 'BTN', 'is_active' => true]);
        $payload = ['format' => 'ssid-master', 'versi' => 1, 'data' => ['bank_rekanan' => [
            ['nama' => 'btn', 'nama_pt' => null, 'kantor_cabang' => null, 'keterangan' => null, 'alamat' => null, 'is_active' => true],
        ]]];

        $this->periksa($payload, ['master' => ['bank_rekanan']]);
        $baris = session('pindah_master.preview.baris')[0];
        $this->assertSame(0, $baris['baru']);
        $this->assertSame(1, $baris['sama']);
    }

    public function test_nilai_beda_dilewati_kecuali_perbarui_dinyalakan(): void
    {
        PromoPreset::create(['nama' => 'Promo A', 'keterangan' => 'lama', 'is_active' => true]);
        $payload = ['format' => 'ssid-master', 'versi' => 1, 'data' => ['promo' => [['nama' => 'Promo A', 'keterangan' => 'baru', 'is_active' => true]]]];

        $this->periksa($payload, ['master' => ['promo']]);
        $this->terapkan();
        $this->assertSame('lama', PromoPreset::where('nama', 'Promo A')->value('keterangan'));

        $this->periksa($payload, ['master' => ['promo'], 'perbarui' => 1]);
        $this->terapkan();
        $this->assertSame('baru', PromoPreset::where('nama', 'Promo A')->value('keterangan'));
        $this->assertSame(1, PromoPreset::count());
    }

    public function test_agen_yang_sudah_dihapus_tidak_dihidupkan_atau_digandakan(): void
    {
        $a = SalesAgent::create(['nama' => 'Lama', 'tipe' => 'agen', 'is_active' => true]);
        $a->delete();
        $payload = ['format' => 'ssid-master', 'versi' => 1, 'data' => ['sales_agent' => [['nama' => 'Lama', 'tipe' => 'agen', 'is_active' => true]]]];

        $this->periksa($payload, ['master' => ['sales_agent']]);
        $this->terapkan();

        $this->assertSame(0, SalesAgent::count());
        $this->assertSame(1, SalesAgent::withTrashed()->count());
    }

    public function test_status_bangun_baru_ditambahkan_di_akhir_dan_yang_ada_hanya_diperbarui_bobot_warnanya(): void
    {
        $awal = StatusBangunStage::count();
        $urutanPondasi = StatusBangunStage::where('nama', 'Pondasi')->value('urutan');
        $payload = ['format' => 'ssid-master', 'versi' => 1, 'data' => ['status_bangun' => [
            ['nama' => 'Pondasi', 'bobot' => 30, 'warna' => '#abcdef'],
            ['nama' => 'Serah Kunci', 'bobot' => 0, 'warna' => '#123456'],
        ]]];

        $this->periksa($payload, ['master' => ['status_bangun'], 'perbarui' => 1]);
        $this->terapkan();

        $this->assertSame($awal + 1, StatusBangunStage::count());
        $this->assertEquals(30, StatusBangunStage::where('nama', 'Pondasi')->value('bobot'));
        $this->assertSame($urutanPondasi, StatusBangunStage::where('nama', 'Pondasi')->value('urutan'));
        $baru = StatusBangunStage::where('nama', 'Serah Kunci')->firstOrFail();
        $this->assertSame((int) StatusBangunStage::max('urutan'), $baru->urutan);
        $this->assertFalse($baru->is_default);
        $this->assertSame(1, StatusBangunStage::where('is_default', true)->count());
    }

    public function test_profil_developer_yang_sudah_terisi_tidak_ditimpa_tanpa_perbarui(): void
    {
        DeveloperProfile::getSingleton()->update(['nama_developer' => 'PT Asli', 'alamat' => 'Alamat Asli']);
        $payload = ['format' => 'ssid-master', 'versi' => 1, 'data' => ['profil_developer' => [
            'teks' => ['nama_developer' => 'PT Lain', 'alamat' => 'Alamat Lain'],
            'bank' => [['nama_bank' => 'BNI', 'nomor_rekening' => '999', 'atas_nama_rekening' => 'PT Lain', 'is_primary' => true]],
        ]]];

        $this->periksa($payload, ['master' => ['profil_developer']]);
        $this->terapkan();

        $p = DeveloperProfile::getSingleton();
        $this->assertSame('PT Asli', $p->nama_developer);
        $this->assertSame('Alamat Asli', $p->alamat);
        $this->assertSame(1, $p->banks()->count()); // rekening baru tetap ditambahkan
    }

    // ── Keamanan & masalah ───────────────────────────────────────────────

    public function test_file_bukan_json_atau_bukan_ekspor_sistem_ditolak(): void
    {
        $this->actingAs($this->admin)->post(route('pengaturan.pindah-master.periksa'), [
            'file' => UploadedFile::fake()->createWithContent('x.json', 'bukan json {'), 'master' => ['promo'],
        ])->assertSessionHasErrors('file');

        $this->periksa(['format' => 'lain', 'versi' => 1, 'data' => []], ['master' => ['promo']])->assertSessionHasErrors('file');
        $this->periksa(['format' => 'ssid-master', 'versi' => 99, 'data' => []], ['master' => ['promo']])->assertSessionHasErrors('file');
        $this->assertNull(session('pindah_master'));
    }

    public function test_masalah_pada_satu_baris_membatalkan_seluruh_impor(): void
    {
        $payload = ['format' => 'ssid-master', 'versi' => 1, 'data' => [
            'promo' => [['nama' => 'Promo Valid', 'is_active' => true]],
            'skema_dp' => [['nama' => 'Skema Rusak', 'cara_bayar' => 'barter']],
        ]];

        $this->periksa($payload, ['master' => ['promo', 'skema_dp']]);
        $preview = session('pindah_master.preview');
        $this->assertFalse($preview['bisa_diterapkan']);
        $this->assertStringContainsString('Skema DP', $preview['masalah'][0]);

        $this->terapkan()->assertSessionHas('error');
        $this->assertSame(0, PromoPreset::count()); // yang valid pun tidak ikut masuk
        $this->assertSame(0, SkemaDpPreset::count());
    }

    public function test_terapkan_tanpa_pratinjau_ditolak(): void
    {
        $this->terapkan()->assertSessionHas('error');
    }

    public function test_batal_membersihkan_pratinjau(): void
    {
        $this->periksa(['format' => 'ssid-master', 'versi' => 1, 'data' => ['promo' => []]], ['master' => ['promo']]);
        $this->assertNotNull(session('pindah_master'));

        $this->actingAs($this->admin)->post(route('pengaturan.pindah-master.batal'));
        $this->assertNull(session('pindah_master'));
    }

    // ── Perintah buat-superadmin ─────────────────────────────────────────

    public function test_perintah_membuat_superadmin_dengan_password_sementara_yang_wajib_diganti(): void
    {
        $kode = Artisan::call('users:buat-superadmin', ['email' => 'Pemilik@Sedaya.test', '--nama' => 'Pemilik']);
        $out = Artisan::output();

        $this->assertSame(0, $kode);
        $user = User::where('email', 'pemilik@sedaya.test')->firstOrFail();
        $this->assertTrue($user->hasRole('superadmin'));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);

        preg_match('/\b([A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4})\b/', $out, $m);
        $this->assertNotEmpty($m, 'Password sementara harus tampil di keluaran.');
        $this->assertTrue(Hash::check($m[1], $user->password));
        $this->assertDatabaseHas('activity_log', ['log_name' => 'akun', 'subject_id' => $user->id]);
    }

    public function test_perintah_menolak_email_yang_sudah_ada_dan_tidak_mengubah_apa_pun(): void
    {
        $hash = $this->admin->password;

        $kode = Artisan::call('users:buat-superadmin', ['email' => $this->admin->email]);

        $this->assertSame(1, $kode);
        $this->assertSame($hash, $this->admin->fresh()->password);
        $this->assertSame(1, User::where('email', $this->admin->email)->count());
    }

    public function test_perintah_gagal_jelas_kalau_role_belum_di_seed(): void
    {
        \Spatie\Permission\Models\Role::where('name', 'superadmin')->delete();

        $kode = Artisan::call('users:buat-superadmin', ['email' => 'baru@sedaya.test']);

        $this->assertSame(1, $kode);
        $this->assertStringContainsString('RolesAndPermissionsSeeder', Artisan::output());
        $this->assertNull(User::where('email', 'baru@sedaya.test')->first());
    }

    public function test_perintah_menolak_email_tidak_valid(): void
    {
        $this->assertSame(1, Artisan::call('users:buat-superadmin', ['email' => 'bukan-email']));
    }

    public function test_master_yang_dicentang_tetapi_tidak_ada_di_file_dilewati_dan_tidak_memblokir_impor(): void
    {
        $payload = ['format' => 'ssid-master', 'versi' => 1, 'data' => ['promo' => [['nama' => 'Promo Satu', 'is_active' => true]]]];

        // Semua master dicentang (bawaan halaman), padahal file hanya berisi Promo.
        $this->periksa($payload, ['master' => $this->semuaKey()]);
        $preview = session('pindah_master.preview');

        $this->assertTrue($preview['bisa_diterapkan']);
        $this->assertSame([], $preview['masalah']);
        $this->assertContains('Notaris', $preview['tidak_ada']);
        $this->assertNotContains('Promo', $preview['tidak_ada']);

        $this->terapkan()->assertSessionHas('success');
        $this->assertSame(1, PromoPreset::where('nama', 'Promo Satu')->count());
    }
}
