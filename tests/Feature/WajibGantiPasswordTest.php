<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tahap C: reset password oleh superadmin + paksa ganti password (akun baru, hasil reset, perintah massal).
 */
class WajibGantiPasswordTest extends TestCase
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
        $this->staf = User::factory()->create(['password' => Hash::make('PasswordLama123')]);
        $this->staf->assignRole('admin_keuangan');
    }

    public function test_superadmin_reset_password_menghasilkan_password_sementara_sekali_tampil(): void
    {
        $response = $this->actingAs($this->admin)->post(route('users.reset-password', $this->staf));

        $response->assertSessionHas('tempPassword');
        $sementara = session('tempPassword')['password'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}$/', $sementara);
        $this->assertSame($this->staf->email, session('tempPassword')['email']);

        $staf = $this->staf->fresh();
        $this->assertTrue($staf->must_change_password);
        $this->assertTrue(Hash::check($sementara, $staf->password));
        $this->assertFalse(Hash::check('PasswordLama123', $staf->password)); // password lama tidak berlaku
        $this->assertNotSame($sementara, $staf->password);                  // tersimpan sebagai hash, bukan teks
        $this->assertDatabaseHas('activity_log', ['log_name' => 'akun', 'subject_id' => $staf->id]);
    }

    public function test_sesi_aktif_user_dikeluarkan_saat_reset(): void
    {
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $this->staf->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->post(route('users.reset-password', $this->staf));

        $this->assertDatabaseMissing('sessions', ['id' => 'abc']);
    }

    public function test_hanya_superadmin_yang_boleh_reset_dan_tidak_untuk_diri_sendiri(): void
    {
        $this->actingAs($this->staf)->post(route('users.reset-password', $this->admin))->assertStatus(302);
        $this->assertFalse($this->admin->fresh()->must_change_password);

        $this->actingAs($this->admin)->post(route('users.reset-password', $this->admin))->assertStatus(422);
    }

    public function test_user_wajib_ganti_hanya_bisa_membuka_halaman_ganti_password(): void
    {
        $this->staf->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->staf)->get(route('beranda'))->assertRedirect(route('password.wajib'));
        $this->actingAs($this->staf)->get('/keuangan')->assertRedirect(route('password.wajib'));
        $this->actingAs($this->staf)->get(route('password.wajib'))->assertOk();
    }

    public function test_ganti_password_menghapus_tanda_dan_membuka_akses(): void
    {
        $this->staf->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->staf)->put(route('password.wajib.update'), [
            'current_password' => 'PasswordLama123', 'password' => 'PasswordBaru456', 'password_confirmation' => 'PasswordBaru456',
        ])->assertRedirect(route('beranda'));

        $staf = $this->staf->fresh();
        $this->assertFalse($staf->must_change_password);
        $this->assertNotNull($staf->password_changed_at);
        $this->assertTrue(Hash::check('PasswordBaru456', $staf->password));
        $this->actingAs($staf)->get(route('beranda'))->assertOk();
    }

    public function test_password_baru_harus_kuat_dan_berbeda(): void
    {
        $this->staf->forceFill(['must_change_password' => true])->save();

        $kirim = fn (string $baru) => $this->actingAs($this->staf)->put(route('password.wajib.update'), [
            'current_password' => 'PasswordLama123', 'password' => $baru, 'password_confirmation' => $baru,
        ]);

        $kirim('pendek1')->assertSessionHasErrors('password');            // < 10 karakter
        $kirim('hurufsajapanjang')->assertSessionHasErrors('password');   // tanpa angka
        $kirim('PasswordLama123')->assertSessionHasErrors('password');    // sama dengan yang lama
        $this->assertTrue($this->staf->fresh()->must_change_password);
    }

    public function test_akun_baru_dari_admin_wajib_ganti_password_saat_login_pertama(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Baru', 'email' => 'baru@erp.local', 'password' => 'PasswordAwal123', 'password_confirmation' => 'PasswordAwal123',
        ])->assertSessionDoesntHaveErrors();

        $this->assertTrue(User::where('email', 'baru@erp.local')->first()->must_change_password);
    }

    public function test_perintah_massal_menandai_user_kecuali_yang_dikecualikan(): void
    {
        $this->artisan('users:wajib-ganti-password', ['--all' => true, '--except' => [$this->admin->email]])->assertSuccessful();

        $this->assertTrue($this->staf->fresh()->must_change_password);
        $this->assertFalse($this->admin->fresh()->must_change_password);
    }
}
