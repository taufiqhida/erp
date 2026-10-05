<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alamat halaman diseragamkan per menu (4 Okt 2026): /proyek/..., /pemasaran/..., /keuangan/...
 * Alamat lama harus tetap hidup sebagai pengalihan 301 (bookmark/tautan yang sudah beredar).
 */
class AlamatHalamanTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_alamat_baru_tiap_menu(): void
    {
        $user = $this->admin();

        $this->assertSame('/proyek/create', route('projects.create', [], false));
        $this->assertSame('/proyek/5', route('projects.show', 5, false));
        $this->assertSame('/proyek/5/tipe-unit', route('projects.tipe-unit.index', 5, false));
        $this->assertSame('/proyek/pembatalan', route('cancellation-requests.index', [], false));
        $this->assertSame('/pemasaran/penjualan/5', route('penjualan.project', 5, false));
        $this->assertSame('/pemasaran/konsumen', route('konsumens.index', [], false));
        $this->assertSame('/pemasaran/konsumen/7', route('konsumens.show', 7, false));
        $this->assertSame('/pemasaran/rencana-akad', route('rencana-akad.index', [], false));
        $this->assertSame('/pemasaran/transaksi/3/dokumen', route('dokumen.index', 3, false));
        $this->assertSame('/keuangan/kuitansi/9', route('pembayaran.kuitansi', 9, false));

        // /pemasaran/penjualan (pilih proyek) sengaja mengalihkan ke Beranda — bukan halaman sendiri.
        $this->actingAs($user)->get('/pemasaran/penjualan')->assertRedirect('/beranda');

        foreach (['/proyek/create', '/proyek/pembatalan', '/pemasaran/konsumen', '/pemasaran/rencana-akad', '/keuangan'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_alamat_lama_dialihkan_301_dengan_query_string(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get('/projects/create')->assertStatus(301)->assertRedirect('/proyek/create');
        $this->actingAs($user)->get('/projects/12/tipe-unit')->assertStatus(301)->assertRedirect('/proyek/12/tipe-unit');
        $this->actingAs($user)->get('/cancellation-requests?type=cancellation')->assertStatus(301)->assertRedirect('/proyek/pembatalan?type=cancellation');
        $this->actingAs($user)->get('/penjualan/4')->assertStatus(301)->assertRedirect('/pemasaran/penjualan/4');
        $this->actingAs($user)->get('/konsumens')->assertStatus(301)->assertRedirect('/pemasaran/konsumen');
        $this->actingAs($user)->get('/konsumens/8?transaksi=3')->assertStatus(301)->assertRedirect('/pemasaran/konsumen/8?transaksi=3');
        $this->actingAs($user)->get('/rencana-akad')->assertStatus(301)->assertRedirect('/pemasaran/rencana-akad');
        $this->actingAs($user)->get('/kavling-konsumen/6/dokumen')->assertStatus(301)->assertRedirect('/pemasaran/transaksi/6/dokumen');
        $this->actingAs($user)->get('/pembayaran/2/kuitansi')->assertStatus(301)->assertRedirect('/keuangan/kuitansi/2');
    }

    public function test_simpan_dan_ubah_proyek_lewat_alamat_baru(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->post(route('projects.store'), ['nama' => 'Proyek Uji', 'kode' => 'PU-1', 'kota' => 'Bandung'])
            ->assertSessionDoesntHaveErrors();
        $project = \App\Models\Project::where('kode', 'PU-1')->firstOrFail();
        $this->assertSame('/proyek/' . $project->id, route('projects.show', $project, false));

        $this->actingAs($user)
            ->put(route('projects.update', $project), ['nama' => 'Proyek Uji 2', 'kode' => 'PU-1', 'kota' => 'Bandung'])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('Proyek Uji 2', $project->fresh()->nama);
    }
}
