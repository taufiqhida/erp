<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoginThrottleIpTest extends TestCase
{
    use RefreshDatabase;

    public function test_satu_ip_yang_mencoba_banyak_email_tetap_dikunci(): void
    {
        Event::fake([Lockout::class]);

        // Password spraying: satu password umum ke banyak email, tiap email hanya sekali —
        // batas per email+IP tidak pernah tercapai, jadi harus ada batas per IP.
        for ($i = 1; $i <= 20; $i++) {
            $this->post('/login', ['email' => "korban{$i}@example.com", 'password' => 'salah'])
                ->assertSessionHasErrors('email');
        }
        Event::assertNotDispatched(Lockout::class);

        $this->post('/login', ['email' => 'korban21@example.com', 'password' => 'salah'])
            ->assertSessionHasErrors('email');
        Event::assertDispatched(Lockout::class);
    }

    public function test_login_benar_tidak_mereset_hitungan_per_ip(): void
    {
        Event::fake([Lockout::class]);
        $user = User::factory()->create();

        for ($i = 1; $i <= 19; $i++) {
            $this->post('/login', ['email' => "korban{$i}@example.com", 'password' => 'salah']);
        }

        // Penyerang yang punya satu akun sah tidak boleh bisa menyegarkan hitungan dengan login benar.
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticated();
        $this->post('/logout');

        $this->post('/login', ['email' => 'korban20@example.com', 'password' => 'salah']);
        Event::assertNotDispatched(Lockout::class);

        $this->post('/login', ['email' => 'korban21@example.com', 'password' => 'salah']);
        Event::assertDispatched(Lockout::class);
    }

    public function test_pengguna_biasa_tidak_terganggu_oleh_batas_per_ip(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 3; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticated();
    }
}
