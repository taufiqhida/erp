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
}
