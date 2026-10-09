<?php

namespace Tests\Feature;

use App\Models\DokumenTemplate;
use App\Models\SumberLead;
use App\Models\User;
use App\Support\PindahMaster;
use Database\Seeders\MasterAwalSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Superadmin mengubah nama/email akun, dan seeder master awal (Sumber Lead + Template Pemberkasan). */
class DataAkunDanMasterAwalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->staf = User::factory()->create(['name' => 'Melza', 'email' => 'melza@erp.local']);
        $this->staf->assignRole('admin_sales');
    }

    // ── Ubah data akun ───────────────────────────────────────────────────

    public function test_superadmin_mengubah_nama_dan_email_akun_dan_tercatat(): void
    {
        $this->actingAs($this->admin)->patch(route('users.update', $this->staf), ['name' => 'Melza Putri', 'email' => 'Melza@SedayaRealty.com'])
            ->assertSessionHas('success')->assertSessionDoesntHaveErrors();

        $u = $this->staf->fresh();
        $this->assertSame('Melza Putri', $u->name);
        $this->assertSame('melza@sedayarealty.com', $u->email); // huruf kecil
        $this->assertTrue($u->hasRole('admin_sales'));           // role tidak tersentuh

        $log = \Spatie\Activitylog\Models\Activity::where('log_name', 'akun')->where('subject_id', $this->staf->id)->latest('id')->firstOrFail();
        $this->assertSame('melza@erp.local', $log->properties['lama']['email']);
        $this->assertSame('melza@sedayarealty.com', $log->properties['baru']['email']);
    }

    public function test_email_tidak_boleh_sama_dengan_akun_lain_tetapi_boleh_tetap_sama_dengan_miliknya(): void
    {
        $this->actingAs($this->admin)->patch(route('users.update', $this->staf), ['name' => 'Melza', 'email' => $this->admin->email])
            ->assertSessionHasErrors('email');
        $this->assertSame('melza@erp.local', $this->staf->fresh()->email);

        $this->actingAs($this->admin)->patch(route('users.update', $this->staf), ['name' => 'Melza Baru', 'email' => 'melza@erp.local'])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('Melza Baru', $this->staf->fresh()->name);
    }

    public function test_validasi_nama_dan_email(): void
    {
        $this->actingAs($this->admin)->patch(route('users.update', $this->staf), ['name' => '', 'email' => 'bukan-email'])
            ->assertSessionHasErrors(['name', 'email']);
    }

    public function test_hanya_superadmin_boleh_mengubah_data_akun(): void
    {
        $this->actingAs($this->staf)->patch(route('users.update', $this->admin), ['name' => 'Diganti', 'email' => 'diganti@x.test']);

        $this->assertNotSame('Diganti', $this->admin->fresh()->name);
    }

    // ── Seeder master awal ───────────────────────────────────────────────

    public function test_seeder_mengisi_sumber_lead_dan_template_pemberkasan_sesuai_daftar_perusahaan(): void
    {
        $this->seed(MasterAwalSeeder::class);

        $this->assertSame(
            ['Instagram', 'Facebook', 'Thread', 'Tiktok', 'Banner', 'Pameran', 'Freelance', 'Agen', 'Allowance', 'Walk In', 'Referral'],
            SumberLead::ordered()->pluck('nama')->all(),
        );
        $this->assertSame(['Referral'], SumberLead::where('is_referral', true)->pluck('nama')->all());

        $per = DokumenTemplate::selectRaw('cara_bayar, count(*) as n')->groupBy('cara_bayar')->pluck('n', 'cara_bayar')->all();
        $this->assertEquals(['kpr_subsidi' => 24, 'kpr_komersil' => 19, 'cash' => 9, 'cash_bertahap' => 9], $per);

        // Urutan dokumen mengikuti daftar; Cash dan Cash Bertahap memakai daftar yang sama.
        $this->assertSame('KTP Pemohon', DokumenTemplate::forCaraBayar('kpr_subsidi')->first()->nama_dokumen);
        $this->assertSame('SiKasep/Tapera', DokumenTemplate::forCaraBayar('kpr_subsidi')->get()->last()->nama_dokumen);
        $this->assertSame(
            DokumenTemplate::forCaraBayar('cash')->pluck('nama_dokumen')->all(),
            DokumenTemplate::forCaraBayar('cash_bertahap')->pluck('nama_dokumen')->all(),
        );
        $this->assertSame('SiKasep/Tapera', DokumenTemplate::where('nama_dokumen', 'SiKasep/Tapera')->value('nama_dokumen'));
        $this->assertSame(0, DokumenTemplate::whereIn('cara_bayar', ['kpr_komersil', 'cash', 'cash_bertahap'])->where('nama_dokumen', 'SiKasep/Tapera')->count());
    }

    public function test_seeder_aman_diulang_dan_tidak_menimpa_perubahan_di_layar(): void
    {
        $this->seed(MasterAwalSeeder::class);
        SumberLead::where('nama', 'Instagram')->update(['keterangan' => 'IG resmi']);
        DokumenTemplate::where('cara_bayar', 'cash')->where('nama_dokumen', 'KK')->update(['sifat' => 'opsional']);
        $sumber = SumberLead::count();
        $dokumen = DokumenTemplate::count();

        $this->seed(MasterAwalSeeder::class);

        $this->assertSame($sumber, SumberLead::count());
        $this->assertSame($dokumen, DokumenTemplate::count());
        $this->assertSame('IG resmi', SumberLead::where('nama', 'Instagram')->value('keterangan'));
        $this->assertSame('opsional', DokumenTemplate::where('cara_bayar', 'cash')->where('nama_dokumen', 'KK')->value('sifat'));
    }

    public function test_file_master_awal_sah_dan_bisa_diimpor_lewat_halaman_pindah_master_dengan_pratinjau(): void
    {
        $payload = json_decode(file_get_contents(database_path('data/master-awal.json')), true);
        $this->assertNull(PindahMaster::periksaPayload($payload));

        $this->actingAs($this->admin)->post(route('pengaturan.pindah-master.periksa'), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('master-awal.json', json_encode($payload)),
            'master' => ['sumber_lead', 'dokumen_template'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, SumberLead::count()); // pratinjau belum mengubah apa pun
        $baris = collect(session('pindah_master.preview.baris'))->keyBy('key');
        $this->assertSame(11, $baris['sumber_lead']['baru']);
        $this->assertSame(61, $baris['dokumen_template']['baru']);
    }
}
