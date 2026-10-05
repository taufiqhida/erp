<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * Pengaman regresi untuk pengerasan keamanan (4 Okt 2026): header keamanan,
 * pembatas laju endpoint berat, dan aturan password.
 */
class KeamananTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_keamanan_ada_di_respons_web(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('Strict-Transport-Security'); // HTTP biasa: tidak dikirim
    }

    public function test_hsts_hanya_dikirim_lewat_https(): void
    {
        $response = $this->get('https://localhost/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    }

    public function test_endpoint_export_dibatasi_laju_per_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($user)->get(route('keuangan.export'))->assertOk();
        }

        $this->actingAs($user)->get(route('keuangan.export'))->assertStatus(429);
    }

    public function test_password_baru_minimal_10_karakter_dengan_huruf_dan_angka(): void
    {
        $cek = fn (string $pw) => Validator::make(['password' => $pw], ['password' => [Password::defaults()]])->passes();

        $this->assertFalse($cek('abc12345'));      // 8 karakter
        $this->assertFalse($cek('abcdefghijkl'));  // tanpa angka
        $this->assertFalse($cek('1234567890123')); // tanpa huruf
        $this->assertTrue($cek('RahasiaUji123'));
    }
}
