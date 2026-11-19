<?php

namespace Tests\Feature;

use App\Support\SvgBersih;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SvgBersihTest extends TestCase
{
    private const SVG_BERBAHAYA = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)" viewBox="0 0 10 10">'
        .'<script>alert(1)</script><style>.a{fill:red}</style>'
        .'<g id="A-1"><path id="B-2" class="a" d="M0 0h5v5z" onclick="x()"/></g>'
        .'<a href="javascript:alert(2)"><rect id="C-3" width="1" height="1"/></a>'
        .'<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>';

    public function test_skrip_dan_atribut_berbahaya_dibuang_id_unit_tetap(): void
    {
        $bersih = SvgBersih::bersihkan(self::SVG_BERBAHAYA);

        $this->assertNotNull($bersih);
        foreach (['<script', 'onload', 'onclick', 'javascript:', 'foreignObject'] as $berbahaya) {
            $this->assertStringNotContainsString($berbahaya, $bersih);
        }

        // Pencocokan unit di siteplan memakai id elemen; bentuk dan gaya juga harus tetap.
        foreach (['id="A-1"', 'id="B-2"', 'id="C-3"', 'd="M0 0h5v5z"', 'fill:red'] as $dipertahankan) {
            $this->assertStringContainsString($dipertahankan, $bersih);
        }
    }

    public function test_isi_yang_bukan_xml_svg_ditolak(): void
    {
        $this->assertNull(SvgBersih::bersihkan('bukan xml <<< <img src=x onerror=alert(1)>'));
        $this->assertNull(SvgBersih::bersihkan(''));
    }

    public function test_simpan_membersihkan_svg_yang_diunggah(): void
    {
        Storage::fake('public');

        $path = SvgBersih::simpan(
            UploadedFile::fake()->createWithContent('siteplan.svg', self::SVG_BERBAHAYA),
            'siteplan',
            'siteplan_image',
        );

        $isi = Storage::disk('public')->get($path);
        $this->assertStringEndsWith('.svg', $path);
        $this->assertStringNotContainsString('<script', $isi);
        $this->assertStringNotContainsString('onload', $isi);
        $this->assertStringContainsString('id="B-2"', $isi);
    }

    public function test_simpan_menolak_dan_menghapus_svg_rusak(): void
    {
        Storage::fake('public');

        try {
            SvgBersih::simpan(
                UploadedFile::fake()->createWithContent('rusak.svg', '<svg><g></svg> <img src=x onerror=alert(1)>'),
                'siteplan',
                'siteplan_image',
            );
            $this->fail('ValidationException seharusnya dilempar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('siteplan_image', $e->errors());
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_gambar_raster_tidak_disentuh(): void
    {
        Storage::fake('public');

        $path = SvgBersih::simpan(UploadedFile::fake()->image('foto.png', 20, 20), 'siteplan', 'siteplan_image');

        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_media_svg_dikirim_dengan_sandbox_csp(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('siteplan/a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        Storage::disk('public')->put('siteplan/a.png', 'x');

        $this->get('/media/siteplan/a.svg')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");

        $this->assertNull($this->get('/media/siteplan/a.png')->headers->get('Content-Security-Policy'));
    }

    public function test_perintah_svg_bersihkan_membersihkan_file_lama(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('siteplan/lama.svg', self::SVG_BERBAHAYA);

        $this->artisan('svg:bersihkan')->assertSuccessful();

        $this->assertStringNotContainsString('<script', Storage::disk('public')->get('siteplan/lama.svg'));
    }

    public function test_perintah_svg_bersihkan_melaporkan_file_tak_terbaca_tanpa_mengubahnya(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('siteplan/rusak.svg', 'bukan xml <<<');

        $this->artisan('svg:bersihkan')->assertFailed();

        $this->assertSame('bukan xml <<<', Storage::disk('public')->get('siteplan/rusak.svg'));
    }
}
