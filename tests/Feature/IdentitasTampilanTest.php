<?php

namespace Tests\Feature;

use App\Models\DeveloperProfile;
use App\Models\User;
use App\Support\Branding;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tahap A (identitas): logo/nama developer di login, sidebar, favicon; tautan "Lupa password"
 * mengikuti status email; halaman error berbahasa Indonesia.
 */
class IdentitasTampilanTest extends TestCase
{
    use RefreshDatabase;

    private function halaman(string $url): array
    {
        $html = $this->get($url)->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    }

    public function test_bawaan_saat_logo_dan_nama_belum_diisi(): void
    {
        $page = $this->halaman('/login');

        $this->assertNull($page['props']['branding']['logo_url']);
        $this->assertNull($page['props']['branding']['nama_developer']); // placeholder "Nama Developer" dianggap kosong
        $this->assertSame('Sedaya Sistem Informasi Developer', $page['props']['branding']['nama_sistem']);
        $this->assertSame('/favicon.svg', $page['props']['branding']['favicon_url']);
    }

    public function test_tautan_lupa_password_hanya_saat_email_aktif(): void
    {
        config(['mail.default' => 'log']);
        $this->assertFalse($this->halaman('/login')['props']['canResetPassword']);

        config(['mail.default' => 'smtp']);
        $this->assertTrue($this->halaman('/login')['props']['canResetPassword']);
        $this->assertTrue(Branding::data()['email_enabled']);
    }

    public function test_logo_dan_nama_dari_profil_developer_dipakai_di_seluruh_aplikasi(): void
    {
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->patch(route('pengaturan.profil-developer.update'), [
            'nama_developer' => 'PT Sedaya Griya',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120), // logo lebar
        ])->assertSessionDoesntHaveErrors();

        $profile = DeveloperProfile::first();
        Storage::disk('public')->assertExists($profile->logo_path);
        Storage::disk('public')->assertExists('developer/favicon.png'); // favicon persegi dibuat otomatis

        $img = imagecreatefromstring(Storage::disk('public')->get('developer/favicon.png'));
        $this->assertSame([64, 64], [imagesx($img), imagesy($img)]);

        auth()->logout();
        $branding = $this->halaman('/login')['props']['branding'];
        $this->assertSame('PT Sedaya Griya', $branding['nama_developer']);
        $this->assertStringContainsString('/media/developer/', $branding['logo_url']);
        $this->assertStringContainsString('favicon.png', $branding['favicon_url']);

        // Logo harus bisa dimuat halaman login (tanpa login).
        $this->get(parse_url($branding['logo_url'], PHP_URL_PATH))->assertOk();
    }

    public function test_halaman_error_berbahasa_indonesia_untuk_akses_langsung(): void
    {
        $page = $this->halaman('/alamat-yang-tidak-ada-sama-sekali');

        $this->assertSame('Error', $page['component']);
        $this->assertSame(404, $page['props']['status']);
        $this->get('/alamat-yang-tidak-ada-sama-sekali')->assertStatus(404);
    }

    public function test_akses_ditolak_karena_permission_tetap_dialihkan_dengan_pesan(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(); // tanpa role

        // Perilaku lama dipertahankan: bukan halaman Error penuh, tapi kembali ke Dashboard + pesan.
        $this->actingAs($user)->get('/audit-trail')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }
}
