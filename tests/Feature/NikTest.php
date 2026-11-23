<?php

namespace Tests\Feature;

use App\Models\Konsumen;
use App\Support\Nik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Aturan NIK (App\Support\Nik) dan perintah nik:audit. */
class NikTest extends TestCase
{
    use RefreshDatabase;

    public function test_nik_valid_laki_laki_dan_perempuan(): void
    {
        $this->assertNull(Nik::periksa('3171024505850002'));          // hari 45 = perempuan lahir tanggal 5
        $this->assertNull(Nik::periksa('3201010101900001'));
        $this->assertNull(Nik::periksa('3201012902960003'));          // 29 Feb dibiarkan lolos (tahun 2 digit ambigu)
        $this->assertNull(Nik::periksa(null));
        $this->assertNull(Nik::periksa(''));
    }

    public function test_panjang_dan_karakter(): void
    {
        $this->assertStringContainsString('16 digit', Nik::periksa('320101010190000'));
        $this->assertStringContainsString('16 digit', Nik::periksa('32010101019000012'));
        $this->assertStringContainsString('angka saja', Nik::periksa('32010101019000AB'));
        $this->assertStringContainsString('notasi ilmiah', Nik::periksa('3.2010101019E+15'));
    }

    public function test_semua_digit_sama_dan_nomor_urut_0000(): void
    {
        $this->assertStringContainsString('semua digit sama', Nik::periksa('1111111111111111'));
        $this->assertStringContainsString('semua digit sama', Nik::periksa('0000000000000000'));
        $this->assertStringContainsString('0000', Nik::periksa('3201010101900000'));
        $this->assertNull(Nik::periksa('3201010101900010'));
    }

    public function test_kode_provinsi_bulan_dan_tanggal(): void
    {
        $this->assertStringContainsString('provinsi', Nik::periksa('9901010101900001'));
        $this->assertStringContainsString('provinsi', Nik::periksa('0001010101900001'));
        $this->assertStringContainsString('bulan lahir', Nik::periksa('3201010113900001'));      // bulan 13
        $this->assertStringContainsString('bulan lahir', Nik::periksa('3201010100900001'));      // bulan 00
        $this->assertStringContainsString('tanggal lahir', Nik::periksa('3201010001900001'));    // hari 00
        $this->assertStringContainsString('tanggal lahir', Nik::periksa('3201013202900001'));    // 32
        $this->assertStringContainsString('tanggal lahir', Nik::periksa('3201013102900001'));    // 31 Februari
        $this->assertStringContainsString('tanggal lahir', Nik::periksa('3201017201900001'));    // 72 (perempuan 32)
        $this->assertNull(Nik::periksa('9101010101900001'));                                     // Papua (kode lama) dikenal
        $this->assertNull(Nik::periksa('9601010101900001'));                                     // Papua Barat Daya
    }

    public function test_bersihkan_membuang_pemisah_kosmetik(): void
    {
        $this->assertSame('3201010101900001', Nik::bersihkan(' 3201 0101.0190-0001 '));
        $this->assertNull(Nik::bersihkan('  '));
        $this->assertNull(Nik::bersihkan(null));
    }

    public function test_peringatan_usia_tidak_wajar(): void
    {
        $this->assertNull(Nik::peringatan('3201010101900001'));                                  // lahir 1990
        $this->assertNull(Nik::peringatan('3201010101000001'));                                  // 1900/2000: 2000 berusia 26
        $yy = str_pad((string) ((int) now()->format('y') - 5), 2, '0', STR_PAD_LEFT);   // lahir 5 tahun lalu (atau 105 tahun lalu)
        $this->assertStringContainsString('usia', (string) Nik::peringatan('3201010101' . $yy . '0001'));
    }

    public function test_audit_melaporkan_tanpa_mengubah_lalu_terapkan_hanya_kosmetik(): void
    {
        $a = Konsumen::create(['nama' => 'Kosmetik', 'nik' => '3201 0101 0190 0001']);
        $b = Konsumen::create(['nama' => 'Rusak', 'nik' => '12345']);
        $c = Konsumen::create(['nama' => 'Valid', 'nik' => '3171024505850002']);
        Konsumen::create(['nama' => 'Tanpa NIK']);

        $this->artisan('nik:audit')->assertSuccessful();
        $this->assertSame('3201 0101 0190 0001', $a->fresh()->nik, 'tanpa --terapkan tidak ada yang berubah');

        $this->artisan('nik:audit', ['--terapkan' => true])->assertSuccessful();
        $this->assertSame('3201010101900001', $a->fresh()->nik);
        $this->assertSame('12345', $b->fresh()->nik, 'NIK rusak tidak disentuh');
        $this->assertSame('3171024505850002', $c->fresh()->nik);
    }

    public function test_audit_tidak_merapikan_bila_nik_bersih_dipakai_konsumen_lain(): void
    {
        Konsumen::create(['nama' => 'Pemilik', 'nik' => '3201010101900001']);
        $dobel = Konsumen::create(['nama' => 'Dobel', 'nik' => '3201-0101-0190-0001']);

        $this->artisan('nik:audit', ['--terapkan' => true])->assertSuccessful();
        $this->assertSame('3201-0101-0190-0001', $dobel->fresh()->nik);
    }
}
