<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pengaturan yang didelegasikan ke peran tertentu (selain superadmin): siapa boleh membuka halaman master mana. */
class PengaturanDidelegasikanTest extends TestCase
{
    use RefreshDatabase;

    private const HALAMAN = [
        'pengaturan.sales-agents', 'pengaturan.program-all-in', 'pengaturan.status-bangun', 'pengaturan.kontraktor',
        'pengaturan.bank-rekanan', 'pengaturan.notaris', 'pengaturan.dajam-sbum', 'pengaturan.profil-developer', 'pengaturan.skema-dp',
    ];

    /** Peran => halaman yang BOLEH dibuka (selebihnya ditolak). */
    private const BOLEH = [
        'leader'          => ['pengaturan.sales-agents', 'pengaturan.program-all-in'],
        'admin_proyek'    => ['pengaturan.status-bangun', 'pengaturan.kontraktor'],
        'admin_keuangan'  => ['pengaturan.bank-rekanan', 'pengaturan.notaris', 'pengaturan.dajam-sbum'],
        'admin_sales'     => ['pengaturan.sales-agents'],
        'pelaksana_lapangan' => [],
        'direktur'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_tiap_peran_hanya_bisa_membuka_halaman_pengaturan_yang_didelegasikan_kepadanya(): void
    {
        foreach (self::BOLEH as $role => $boleh) {
            $u = User::factory()->create();
            $u->assignRole($role);
            foreach (self::HALAMAN as $nama) {
                $r = $this->actingAs($u)->get(route($nama));
                in_array($nama, $boleh, true)
                    ? $r->assertOk()
                    : $r->assertRedirect(); // ditolak: dialihkan (tanpa izin)
            }
        }
    }

    public function test_superadmin_bisa_membuka_semua_halaman_pengaturan(): void
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        foreach (self::HALAMAN as $nama) {
            $this->actingAs($u)->get(route($nama))->assertOk();
        }
    }

    public function test_tombol_pengaturan_di_beranda_ditentukan_oleh_izin_pengguna(): void
    {
        Project::create(['nama' => 'P', 'kode' => 'P1', 'kota' => 'X', 'is_active' => true]);
        $izin = fn (string $role) => (function () use ($role) {
            $u = User::factory()->create();
            $u->assignRole($role);
            $html = $this->actingAs($u)->get(route('beranda'))->getContent();
            preg_match('/data-page="([^"]+)"/', $html, $m);

            return json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props']['auth']['user']['permissions'];
        })();

        // Beranda memutuskan tombol "Pengaturan" dari izin ini (lihat PENGATURAN_ROUTES di Projects/Index.vue).
        $this->assertContains('manage sales agent', $izin('leader'));
        $this->assertContains('manage status bangun master', $izin('admin_proyek'));
        $this->assertContains('manage bank rekanan', $izin('admin_keuangan'));
        $this->assertContains('manage sales agent', $izin('admin_sales'));
        $this->assertNotContains('manage bank rekanan', $izin('admin_sales'));
    }
}
