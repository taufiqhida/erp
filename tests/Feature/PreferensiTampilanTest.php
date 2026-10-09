<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ukuran teks per akun + penjaga aksesibilitas dasar pada kode tampilan. */
class PreferensiTampilanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole('superadmin');
    }

    private function atributUkuran(string $html): ?string
    {
        preg_match('/<html[^>]*data-ukuran="([^"]*)"/', $html, $m);

        return $m[1] ?? null;
    }

    public function test_bawaan_normal_untuk_tamu_dan_pengguna_baru(): void
    {
        $this->assertSame('normal', $this->atributUkuran($this->get('/login')->getContent()));
        $this->assertSame('normal', $this->user->ukuranFont());
    }

    public function test_ukuran_tersimpan_di_akun_dan_dirender_server_tanpa_berkedip(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['ukuran_font' => 'besar'])->assertSessionDoesntHaveErrors();

        $this->assertSame('besar', $this->user->fresh()->ukuranFont());
        $html = $this->actingAs($this->user)->get(route('beranda'))->getContent();
        $this->assertSame('besar', $this->atributUkuran($html));
    }

    public function test_ukuran_ikut_ke_perangkat_lain_karena_disimpan_di_server(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['ukuran_font' => 'lebih-besar']);

        auth()->logout();
        $html = $this->actingAs($this->user->fresh())->get(route('beranda'))->getContent();
        $this->assertSame('lebih-besar', $this->atributUkuran($html));
    }

    public function test_nilai_tidak_dikenal_ditolak_dan_tidak_menimpa(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['ukuran_font' => 'besar']);
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['ukuran_font' => 'raksasa'])->assertSessionHasErrors('ukuran_font');
        $this->actingAs($this->user)->patch(route('preferensi.update'), [])->assertSessionHasErrors('ukuran_font');

        $this->assertSame('besar', $this->user->fresh()->ukuranFont());
    }

    public function test_tamu_tidak_bisa_mengubah_preferensi(): void
    {
        $this->patch(route('preferensi.update'), ['ukuran_font' => 'besar'])->assertRedirect(route('login'));
    }

    public function test_data_preferensi_rusak_di_database_jatuh_ke_normal(): void
    {
        $this->user->forceFill(['preferences' => ['ukuran_font' => 'sembarang']])->save();

        $this->assertSame('normal', $this->user->fresh()->ukuranFont());
    }

    public function test_tidak_ada_lagi_ukuran_teks_tetap_dalam_piksel_agar_semua_teks_ikut_membesar(): void
    {
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS));
        $pelanggar = [];
        foreach ($dir as $f) {
            if ($f->getExtension() !== 'vue') continue;
            if (preg_match('/text-\[\d+px\]/', file_get_contents($f->getPathname()))) $pelanggar[] = $f->getFilename();
        }

        $this->assertSame([], $pelanggar, 'Pakai rem (mis. text-[0.6875rem]) supaya ikut preferensi ukuran teks.');
    }

    public function test_tombol_yang_hanya_berisi_ikon_punya_nama_aksesibel(): void
    {
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS));
        $pelanggar = [];
        foreach ($dir as $f) {
            if ($f->getExtension() !== 'vue') continue;
            $isi = file_get_contents($f->getPathname());
            preg_match_all('#<button\b([^>]*)>\s*<svg\b(?:(?!</svg>|<button|</button>).)*</svg>\s*</button>#s', $isi, $m, PREG_SET_ORDER);
            foreach ($m as $tombol) {
                if (!preg_match('/aria-label|:aria-label|\btitle=|:title=/', $tombol[1])) $pelanggar[] = $f->getFilename();
            }
        }

        $this->assertSame([], $pelanggar, 'Tombol ikon harus punya aria-label.');
    }

    // ── Tema (gelap / terang / sistem) ───────────────────────────────────

    private function atribut(string $html, string $nama): ?string
    {
        preg_match('/<html[^>]*' . preg_quote($nama, '/') . '="([^"]*)"/s', $html, $m);

        return $m[1] ?? null;
    }

    public function test_tema_bawaan_gelap_untuk_tamu_dan_pengguna_baru(): void
    {
        $html = $this->get('/login')->getContent();
        $this->assertSame('gelap', $this->atribut($html, 'data-tema'));
        $this->assertSame('gelap', $this->atribut($html, 'data-pilihan-tema'));
        $this->assertSame('gelap', $this->user->temaPilihan());
    }

    public function test_tema_terang_dirender_server_dan_tersimpan_di_akun(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['tema' => 'terang'])->assertSessionDoesntHaveErrors();

        $html = $this->actingAs($this->user)->get(route('beranda'))->getContent();
        $this->assertSame('terang', $this->atribut($html, 'data-tema'));
        $this->assertSame('terang', $this->atribut($html, 'data-pilihan-tema'));
    }

    public function test_tema_sistem_dirender_gelap_dulu_lalu_diputuskan_skrip_di_browser(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['tema' => 'sistem']);

        $html = $this->actingAs($this->user)->get(route('beranda'))->getContent();
        $this->assertSame('gelap', $this->atribut($html, 'data-tema'));
        $this->assertSame('sistem', $this->atribut($html, 'data-pilihan-tema'));
        $this->assertStringContainsString("matchMedia('(prefers-color-scheme: light)')", $html);
    }

    public function test_tema_dan_ukuran_teks_tidak_saling_menimpa(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['ukuran_font' => 'besar']);
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['tema' => 'terang']);

        $u = $this->user->fresh();
        $this->assertSame('besar', $u->ukuranFont());
        $this->assertSame('terang', $u->temaPilihan());
    }

    public function test_tema_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['tema' => 'pelangi'])->assertSessionHasErrors('tema');
        $this->assertSame('gelap', $this->user->fresh()->temaPilihan());
    }

    public function test_pilihan_tema_dikirim_ke_halaman_lewat_props(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['tema' => 'terang']);

        $html = $this->actingAs($this->user)->get(route('beranda'))->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $props = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
        $this->assertSame('terang', $props['auth']['user']['tema']);
    }

    // ── Bentuk menu samping ──────────────────────────────────────────────

    public function test_menu_samping_bawaan_terbuka_dan_pilihan_ringkas_tersimpan_di_akun(): void
    {
        $this->assertSame('terbuka', $this->user->sidebarPilihan());

        $this->actingAs($this->user)->patch(route('preferensi.update'), ['sidebar' => 'ringkas'])->assertSessionDoesntHaveErrors();

        $html = $this->actingAs($this->user)->get(route('beranda'))->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $props = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];
        $this->assertSame('ringkas', $props['auth']['user']['sidebar']);
        $this->assertSame('ringkas', $this->user->fresh()->sidebarPilihan());
    }

    public function test_pilihan_menu_samping_tidak_valid_ditolak_dan_tidak_menimpa_preferensi_lain(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['tema' => 'terang']);
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['sidebar' => 'ringkas']);
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['sidebar' => 'hilang'])->assertSessionHasErrors('sidebar');

        $u = $this->user->fresh();
        $this->assertSame('ringkas', $u->sidebarPilihan());
        $this->assertSame('terang', $u->temaPilihan());
    }

    public function test_menu_samping_rusak_di_database_jatuh_ke_terbuka(): void
    {
        $this->user->forceFill(['preferences' => ['sidebar' => 'sembarang']])->save();

        $this->assertSame('terbuka', $this->user->fresh()->sidebarPilihan());
    }

    // ── Sinkron tampilan setelah login/logout ────────────────────────────

    public function test_halaman_ditandai_masuk_hanya_untuk_pengguna_login(): void
    {
        $this->assertStringNotContainsString('data-masuk', $this->get('/login')->getContent());

        $html = $this->actingAs($this->user)->get(route('beranda'))->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-masuk="1"/s', $html);
    }

    public function test_halaman_masuk_untuk_tamu_membaca_pilihan_terakhir_perangkat_sebelum_tampil(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString("localStorage.getItem('ssid-tema-perangkat')", $html);
        // Pengguna login memakai pilihan akun dari server, jadi skrip perangkat tidak menimpanya.
        $this->assertStringContainsString("if(!d.dataset.masuk)", $html);
    }

    public function test_tema_dan_ukuran_akun_ikut_dikirim_di_setiap_halaman_agar_bisa_disinkronkan(): void
    {
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['tema' => 'terang']);
        $this->actingAs($this->user)->patch(route('preferensi.update'), ['ukuran_font' => 'besar']);

        $html = $this->actingAs($this->user)->get(route('dashboard'))->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $user = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props']['auth']['user'];

        $this->assertSame('terang', $user['tema']);
        $this->assertSame('besar', $user['ukuran_font']);
    }
}

