<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_profil_tampil_dan_hanya_menampilkan_data_akun(): void
    {
        $user = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@sedayarealty.com']);

        $this->actingAs($user)->get('/profile')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Profile/Edit')
                ->where('auth.user.name', 'Budi Santoso')
                ->where('auth.user.email', 'budi@sedayarealty.com'));
    }

    public function test_pengguna_tidak_bisa_mengubah_nama_atau_email_sendiri(): void
    {
        $user = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@sedayarealty.com']);

        $this->actingAs($user)->patch('/profile', ['name' => 'Pak Direktur', 'email' => 'direktur@sedayarealty.com'])->assertForbidden();

        $user->refresh();
        $this->assertSame('Budi Santoso', $user->name);
        $this->assertSame('budi@sedayarealty.com', $user->email);
    }

    public function test_pengguna_tidak_bisa_menghapus_akunnya_sendiri(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertForbidden();

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }
}
