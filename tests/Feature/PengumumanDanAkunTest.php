<?php

namespace Tests\Feature;

use App\Models\Pengumuman;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Pengumuman login (diatur superadmin) dan penonaktifan/penghapusan akun pengguna. */
class PengumumanDanAkunTest extends TestCase
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
        $this->staf = User::factory()->create(['password' => Hash::make('PasswordStaf123')]);
        $this->staf->assignRole('admin_keuangan');
    }

    private function loginProps(): array
    {
        $html = $this->get('/login')->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
    }

    // ── Pengumuman ───────────────────────────────────────────────────────

    public function test_pengumuman_tampil_di_login_disematkan_dulu_lalu_terbaru_dan_hanya_yang_aktif(): void
    {
        Pengumuman::create(['judul' => 'Lama', 'isi' => 'a', 'tanggal' => '2026-01-01']);
        Pengumuman::create(['judul' => 'Baru', 'isi' => 'b', 'tanggal' => '2026-10-01']);
        Pengumuman::create(['judul' => 'Sematan', 'isi' => 'c', 'tanggal' => '2026-02-01', 'disematkan' => true]);
        Pengumuman::create(['judul' => 'Disembunyikan', 'isi' => 'd', 'tanggal' => '2026-11-01', 'aktif' => false]);

        $judul = array_column($this->loginProps()['pengumuman'], 'judul');

        $this->assertSame(['Sematan', 'Baru', 'Lama'], $judul);
    }

    public function test_hanya_superadmin_mengelola_pengumuman(): void
    {
        $data = ['judul' => 'Fitur baru', 'isi' => 'Ada reset password.', 'tanggal' => '2026-10-06', 'disematkan' => true, 'aktif' => true];

        $this->actingAs($this->staf)->post(route('pengaturan.pengumuman.store'), $data)->assertRedirect(route('dashboard')); // ditolak (tanpa hak akses)
        $this->assertSame(0, Pengumuman::count());

        $this->actingAs($this->admin)->post(route('pengaturan.pengumuman.store'), $data)->assertSessionDoesntHaveErrors();
        $p = Pengumuman::firstOrFail();
        $this->assertTrue($p->disematkan);

        $this->actingAs($this->admin)->patch(route('pengaturan.pengumuman.update', $p), [...$data, 'aktif' => false])->assertSessionDoesntHaveErrors();
        $this->assertFalse($p->fresh()->aktif);

        $this->actingAs($this->admin)->delete(route('pengaturan.pengumuman.destroy', $p));
        $this->assertSame(0, Pengumuman::count());
    }

    public function test_validasi_pengumuman(): void
    {
        $this->actingAs($this->admin)->post(route('pengaturan.pengumuman.store'), ['judul' => '', 'isi' => '', 'tanggal' => 'bukan-tanggal'])
            ->assertSessionHasErrors(['judul', 'isi', 'tanggal']);
    }

    public function test_isi_pengumuman_dikirim_sebagai_teks_biasa(): void
    {
        Pengumuman::create(['judul' => '<script>alert(1)</script>', 'isi' => "Baris 1\nBaris 2", 'tanggal' => '2026-10-06']);

        // Dikirim apa adanya sebagai data; Vue menampilkannya sebagai teks (bukan HTML), jadi aman.
        $this->assertSame('<script>alert(1)</script>', $this->loginProps()['pengumuman'][0]['judul']);
    }

    // ── Nonaktif / hapus user ────────────────────────────────────────────

    public function test_user_nonaktif_tidak_bisa_login_dan_sesinya_dikeluarkan(): void
    {
        DB::table('sessions')->insert(['id' => 'sesi-staf', 'user_id' => $this->staf->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->patch(route('users.toggle-aktif', $this->staf))->assertSessionHas('success');

        $this->assertFalse($this->staf->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-staf']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'akun', 'subject_id' => $this->staf->id]);

        auth()->logout();
        $this->post('/login', ['email' => $this->staf->email, 'password' => 'PasswordStaf123'])
            ->assertSessionHasErrors(['email' => 'Akun Anda dinonaktifkan. Hubungi administrator.']);
        $this->assertGuest();
    }

    public function test_sesi_user_yang_dinonaktifkan_saat_sedang_login_langsung_ditutup(): void
    {
        $this->actingAs($this->staf);
        $this->staf->forceFill(['is_active' => false])->save();

        $this->get('/beranda')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_aktifkan_kembali_dan_larangan_menonaktifkan_diri_sendiri(): void
    {
        $this->staf->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->patch(route('users.toggle-aktif', $this->staf));
        $this->assertTrue($this->staf->fresh()->is_active);

        $this->actingAs($this->admin)->patch(route('users.toggle-aktif', $this->admin))->assertStatus(422);
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_hanya_akun_tanpa_riwayat_yang_boleh_dihapus(): void
    {
        $baru = User::factory()->create();

        $this->actingAs($this->admin)->delete(route('users.destroy', $baru))->assertSessionHas('success');
        $this->assertNull(User::find($baru->id));

        // Akun yang pernah bekerja (punya jejak di Audit Trail) tidak boleh dihapus.
        activity('uji')->causedBy($this->staf)->log('pernah bekerja');
        $this->actingAs($this->admin)->delete(route('users.destroy', $this->staf))->assertSessionHas('error');
        $this->assertNotNull(User::find($this->staf->id));
    }

    public function test_user_biasa_tidak_bisa_menonaktifkan(): void
    {
        $this->actingAs($this->staf)->patch(route('users.toggle-aktif', $this->admin));
        $this->assertTrue($this->admin->fresh()->is_active);
    }
}
