<?php

namespace Tests\Feature;

use App\Models\BankRekananPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\NotarisPreset;
use App\Models\Project;
use App\Models\SalesAgent;
use App\Models\StatusBangunStage;
use App\Models\SumberLead;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Models\Wilayah;
use App\Support\KonsumenPerbaruiSpec;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Mode Perbarui: unduhan terisi data, kolom dikenali lewat judul, isi-kosong vs Timpa, verifikasi nama/NIK, domisili, dan saran alamat. */
class PerbaruiKonsumenTest extends TestCase
{
    use RefreshDatabase;

    private const NIK_A = '3201010101900001';
    private const NIK_B = '3171024505850002';
    private const SEMUA = ['kontak', 'pekerjaan', 'ktp', 'domisili', 'notaris', 'penjualan'];

    private User $admin;
    private Project $project;
    private TipeUnitPreset $tipe;
    private NotarisPreset $notaris;
    private array $tmp = [];
    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Proyek Uji', 'kode' => 'PU1', 'kota' => 'X', 'is_active' => true]);
        $this->tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->notaris = NotarisPreset::create(['nama' => 'Notaris Satu', 'is_active' => true]);
        foreach ([
            ['32', 'Jawa Barat', null], ['33', 'Jawa Tengah', null],
            ['32.73', 'Kota Bandung', '32'], ['32.04', 'Kabupaten Bandung', '32'], ['32.73.01', 'Sukasari', '32.73'], ['32.73.01.1001', 'Sukarasa', '32.73.01'],
            ['33.74', 'Kota Semarang', '33'], ['33.22', 'Kabupaten Semarang', '33'], ['33.74.10', 'Tembalang', '33.74'], ['33.74.10.1001', 'Bulusan', '33.74.10'],
        ] as [$kode, $nama, $induk]) {
            Wilayah::create(['kode' => $kode, 'level' => substr_count($kode, '.') + 1, 'induk' => $induk, 'nama' => $nama]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        parent::tearDown();
    }

    private function transaksi(array $konsumen = [], array $trx = []): KavlingKonsumen
    {
        $this->nomor++;
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $this->tipe->id, 'status_bangun_stage_id' => $stage->id,
            'blok' => 'A1', 'nomor_kavling' => (string) $this->nomor, 'harga' => 100000000, 'status_jual' => 'booked']);
        $k = Konsumen::create($konsumen + ['nama' => 'Budi Santoso']);

        return KavlingKonsumen::create($trx + ['kavling_id' => $unit->id, 'konsumen_id' => $k->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
            'cara_bayar' => 'cash', 'tanggal_booking' => '2026-03-01', 'harga_dasar' => 100000000, 'harga_deal' => 100000000]);
    }

    /** Baris: pengenal unit dari transaksi + isian, sebagai [key => nilai]. */
    private function baris(KavlingKonsumen $kk, array $o = []): array
    {
        return $o + ['blok' => 'A1', 'unit' => $kk->kavling->nomor_kavling, 'nama' => $kk->konsumen->nama];
    }

    /** File berjudul sesuai definisi; kolom = gabungan semua key yang dipakai baris (urutan bebas, importer membaca judul). */
    private function file(array $barisList, ?array $judul = null): UploadedFile
    {
        $kunci = array_values(array_unique(array_merge(...array_map('array_keys', $barisList))));
        $def = KonsumenPerbaruiSpec::definisi();
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(KonsumenPerbaruiSpec::SHEET);
        $sheet->fromArray([$judul ?? array_map(fn ($k) => $def[$k][0], $kunci)], null, 'A1');
        foreach ($barisList as $i => $b) {
            foreach ($kunci as $c => $k) $sheet->setCellValueExplicit([$c + 1, $i + 2], (string) ($b[$k] ?? ''), DataType::TYPE_STRING);
        }

        return $this->simpan($ss);
    }

    private function simpan(Spreadsheet $ss): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pb') . '.xlsx';
        (new Xlsx($ss))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, 'perbarui.xlsx', null, null, true);
    }

    private function kirim(UploadedFile $file, string $mode, bool $timpa = false, ?User $oleh = null)
    {
        return $this->actingAs($oleh ?? $this->admin)->post(route('konsumens.perbarui', $this->project), ['file' => $file, 'mode' => $mode, 'timpa' => $timpa ? 1 : 0]);
    }

    /** Cek dulu lalu import sungguhan; mengembalikan laporan terakhir. */
    private function perbarui(array $barisList, bool $timpa = false, bool $simpan = true, ?User $oleh = null): array
    {
        $file = $this->file($barisList);
        $laporan = $this->kirim($file, 'cek', $timpa, $oleh)->getSession()->get('importLaporan');
        if (!$simpan) return $laporan;

        return $this->kirim($file, 'import', $timpa, $oleh)->getSession()->get('importLaporan') ?? $laporan;
    }

    /** Unduh file Perbarui lewat HTTP dan muat sebagai Spreadsheet. */
    private function unduh(array $kelompok, array $opsi = []): Spreadsheet
    {
        $r = $this->actingAs($this->admin)->get(route('konsumens.perbarui-template', ['project' => $this->project->id, 'kelompok' => $kelompok] + $opsi));
        $r->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'ud') . '.xlsx';
        $this->tmp[] = $path;
        file_put_contents($path, $r->streamedContent());

        return IOFactory::load($path);
    }

    private function judul(Spreadsheet $ss): array
    {
        $s = $ss->getSheetByName('Perbarui');

        return $s->rangeToArray('A1:' . $s->getHighestColumn() . '1')[0];
    }

    private function sel(Spreadsheet $ss, string $key, int $baris = 2): string
    {
        $label = KonsumenPerbaruiSpec::definisi()[$key][0];
        $i = array_search($label, array_map(fn ($j) => rtrim((string) $j, ' *'), $this->judul($ss)), true);
        $this->assertNotFalse($i, "kolom {$label} tidak ada");

        return (string) $ss->getSheetByName('Perbarui')->getCell([$i + 1, $baris])->getValue();
    }

    private function tulis(Spreadsheet $ss, string $key, string $nilai, int $baris = 2): void
    {
        $i = array_search(KonsumenPerbaruiSpec::definisi()[$key][0], array_map(fn ($j) => rtrim((string) $j, ' *'), $this->judul($ss)), true);
        $ss->getSheetByName('Perbarui')->setCellValueExplicit([$i + 1, $baris], $nilai, DataType::TYPE_STRING);
    }

    // ── Unduhan ──

    public function test_unduhan_hanya_memuat_kolom_kelompok_terpilih_dan_terisi_data_sekarang(): void
    {
        $kk = $this->transaksi(['nik' => self::NIK_A, 'no_hp' => '081200000001'], ['notaris_preset_id' => $this->notaris->id]);
        $ss = $this->unduh(['notaris']);

        $this->assertSame(['Kluster', 'Blok *', 'Nomor Kavling *', 'Nama Konsumen *', 'NIK', 'Notaris'], $this->judul($ss));
        $this->assertSame('Budi Santoso', $this->sel($ss, 'nama'));
        $this->assertSame(self::NIK_A, $this->sel($ss, 'nik'));
        $this->assertSame('Notaris Satu', $this->sel($ss, 'notaris'));
        $this->assertSame($kk->kavling->nomor_kavling, $this->sel($ss, 'unit'));
        $this->assertSame(['Perbarui', 'Petunjuk', 'Daftar'], $ss->getSheetNames());
    }

    public function test_unduhan_hanya_yang_kosong(): void
    {
        $this->transaksi(['nama' => 'Sudah Punya'], ['notaris_preset_id' => $this->notaris->id]);
        $this->transaksi(['nama' => 'Belum Punya']);

        $ss = $this->unduh(['notaris'], ['baris' => 'kosong']);

        $this->assertSame('Belum Punya', $this->sel($ss, 'nama'));
        $this->assertSame('', $this->sel($ss, 'nama', 3), 'hanya satu baris data');
    }

    public function test_unduhan_wajib_memilih_kelompok(): void
    {
        $this->actingAs($this->admin)->get(route('konsumens.perbarui-template', ['project' => $this->project->id]))->assertSessionHasErrors('kelompok');
    }

    public function test_file_unduhan_diunggah_tanpa_diubah_tidak_mengubah_apa_pun(): void
    {
        $bank = BankRekananPreset::create(['nama' => 'Bank Satu', 'is_active' => true]);
        $this->transaksi(['nik' => '12345', 'no_hp' => '081200000001', 'email' => 'bukan-email', 'npwp' => '123', 'alamat' => 'Jl. Lama 1',
            'pekerjaan' => 'wirausaha', 'status_pernikahan' => 'menikah', 'domisili_sama_ktp' => true] + \App\Support\Wilayah::atribut('32.73.01.1001'),
            ['cara_bayar' => 'kpr_subsidi', 'bank_rekanan_preset_id' => $bank->id, 'notaris_preset_id' => $this->notaris->id]);

        $file = $this->simpan($this->unduh(self::SEMUA));
        $lap = $this->kirim($file, 'cek', true)->getSession()->get('importLaporan');

        // Data lama yang tidak valid (NIK, email, NPWP) tidak membuat baris ditolak selama selnya tidak diubah.
        $this->assertSame([0, 1, 0], [$lap['imported'], $lap['tanpaPerubahan'], $lap['skipped']], implode(' | ', array_merge($lap['errors'], $lap['warnings'], $lap['perubahan'])));
    }

    // ── Isi kosong vs Timpa ──

    public function test_tanpa_timpa_hanya_mengisi_yang_kosong(): void
    {
        $kk = $this->transaksi(['no_hp' => '081111111111', 'nik' => self::NIK_A]);

        $lap = $this->perbarui([$this->baris($kk, ['hp' => '082222222222', 'email' => 'budi@contoh.com', 'alamat' => 'Jl. Mawar 1', 'notaris' => 'Notaris Satu'])]);

        $kk->refresh()->load('konsumen');
        $this->assertSame('081111111111', $kk->konsumen->no_hp);
        $this->assertSame('budi@contoh.com', $kk->konsumen->email);
        $this->assertSame('Jl. Mawar 1', $kk->konsumen->alamat);
        $this->assertSame($this->notaris->id, $kk->notaris_preset_id);
        $this->assertCount(1, $lap['warnings']);
        $this->assertStringContainsString('tidak ditimpa', $lap['warnings'][0]);
    }

    public function test_dengan_timpa_mengganti_yang_sudah_terisi_dan_sel_kosong_tidak_menghapus(): void
    {
        $dua = NotarisPreset::create(['nama' => 'Notaris Dua', 'is_active' => true]);
        $kk = $this->transaksi(['no_hp' => '081111111111', 'email' => 'lama@contoh.com', 'alamat' => 'Jl. Panjang, Sukarasa, Sukasari, Kota Bandung'], ['notaris_preset_id' => $this->notaris->id]);

        $lap = $this->perbarui([$this->baris($kk, ['hp' => '082222222222', 'email' => '', 'alamat' => 'Jl. Pendek 5', 'notaris' => 'Notaris Dua'])], true);

        $kk->refresh()->load('konsumen');
        $this->assertSame('082222222222', $kk->konsumen->no_hp);
        $this->assertSame('lama@contoh.com', $kk->konsumen->email, 'sel kosong tidak menghapus');
        $this->assertSame('Jl. Pendek 5', $kk->konsumen->alamat);
        $this->assertSame($dua->id, $kk->notaris_preset_id);
        $this->assertStringContainsString('081111111111 → 082222222222', $lap['perubahan'][0]);
    }

    public function test_timpa_hanya_untuk_pemegang_override_dan_cek_harus_sama_pilihannya(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id);
        $kk = $this->transaksi(['email' => 'lama@contoh.com']);
        $file = $this->file([$this->baris($kk, ['email' => 'baru@contoh.com'])]);

        $this->kirim($file, 'cek', true, $sales)->assertForbidden();
        $this->assertSame('lama@contoh.com', $kk->konsumen->fresh()->email);
        $this->assertNull(session('importLaporan'));

        // Dicek tanpa Timpa, lalu "import" dengan Timpa: ditolak.
        $this->kirim($file, 'cek', false);
        $this->kirim($file, 'import', true)->assertSessionHas('error');
        $this->assertSame('lama@contoh.com', $kk->konsumen->fresh()->email);
    }

    public function test_cek_dulu_tidak_menyimpan_dan_import_wajib_cek(): void
    {
        $kk = $this->transaksi();
        $lap = $this->perbarui([$this->baris($kk, ['notaris' => 'Notaris Satu', 'email' => 'a@b.co'])], false, false);

        $this->assertSame(['cek', 1], [$lap['mode'], $lap['imported']]);
        $this->assertNull($kk->fresh()->notaris_preset_id);

        $this->kirim($this->file([$this->baris($kk, ['email' => 'c@d.co'])]), 'import')->assertSessionHas('error');
        $this->assertNull($kk->konsumen->fresh()->email);
    }

    // ── Pengaman identitas ──

    public function test_nama_beda_jauh_unit_salah_dan_transaksi_selesai_dilewati(): void
    {
        $kk = $this->transaksi();
        $selesai = $this->transaksi(['nama' => 'Siti Aminah']);
        \DB::table('kavling_konsumen')->where('id', $selesai->id)->update(['status' => 'completed']);

        $lap = $this->perbarui([
            $this->baris($kk, ['nama' => 'Xyz Qwerty', 'email' => 'a@b.co']),
            $this->baris($kk, ['unit' => '404', 'email' => 'a@b.co']),
            $this->baris($selesai, ['email' => 'a@b.co']),
        ], true, false);

        $this->assertSame(3, $lap['skipped']);
        $this->assertStringContainsString('berbeda dengan konsumen', $lap['errors'][0]);
        $this->assertStringContainsString('tidak ditemukan', $lap['errors'][1]);
        $this->assertStringContainsString('Selesai/terkunci', $lap['errors'][2]);
    }

    public function test_nik_valid_tidak_pernah_diganti_walau_timpa_dan_nik_rusak_boleh(): void
    {
        $valid = $this->transaksi(['nama' => 'Siti Aminah', 'nik' => self::NIK_A]);
        $rusak = $this->transaksi(['nama' => 'Andi Wijaya', 'nik' => '12345']);

        $lap = $this->perbarui([
            $this->baris($valid, ['nik' => self::NIK_B]),
            $this->baris($rusak, ['nik' => '3174011502880003']),
        ], true);

        $this->assertSame(self::NIK_A, $valid->konsumen->fresh()->nik);
        $this->assertSame('3174011502880003', $rusak->konsumen->fresh()->nik);
        $this->assertStringContainsString('tidak diganti', $lap['warnings'][0]);
    }

    public function test_satu_kesalahan_melewatkan_seluruh_baris(): void
    {
        $this->transaksi(['nama' => 'Siti Aminah', 'nik' => self::NIK_A]);
        $b = $this->transaksi(['nama' => 'Andi Wijaya']);

        $lap = $this->perbarui([$this->baris($b, ['nik' => self::NIK_A, 'email' => 'a@b.co']), $this->baris($b, ['email' => 'rusak', 'hp' => '0812'])], false, false);

        $this->assertSame(2, $lap['skipped']);
        $this->assertStringContainsString('sudah dipakai konsumen lain', $lap['errors'][0]);
        $this->assertStringContainsString('tidak valid', $lap['errors'][1]);
        $this->assertNull($b->konsumen->fresh()->no_hp);
    }

    // ── Penjualan & izin ──

    public function test_sales_bank_dan_lead(): void
    {
        $sales = SalesAgent::create(['nama' => 'Sales Satu', 'is_active' => true, 'tipe' => 'inhouse']);
        $lead = SumberLead::create(['nama' => 'Instagram', 'is_active' => true]);
        $bank = BankRekananPreset::create(['nama' => 'Bank Satu', 'is_active' => true]);
        $kpr = $this->transaksi([], ['cara_bayar' => 'kpr_subsidi']);
        $cash = $this->transaksi(['nama' => 'Siti Aminah']);

        $lap = $this->perbarui([
            $this->baris($kpr, ['sales' => 'Sales Satu', 'bank' => 'Bank Satu', 'lead' => 'Instagram']),
            $this->baris($cash, ['bank' => 'Bank Satu', 'email' => 'a@b.co']),
        ]);

        $kpr->refresh();
        $this->assertSame([$sales->id, $bank->id, $lead->id], [$kpr->sales_agent_id, $kpr->bank_rekanan_preset_id, $kpr->konsumen->fresh()->sumber_lead_id]);
        $this->assertNull($cash->fresh()->bank_rekanan_preset_id);
        $this->assertStringContainsString('bukan KPR', $lap['warnings'][0]);
    }

    public function test_tanpa_izin_pipeline_notaris_diabaikan_dengan_peringatan(): void
    {
        $pemberkasan = User::factory()->create();
        $pemberkasan->assignRole('admin_pemberkasan');
        $pemberkasan->projects()->attach($this->project->id);
        $kk = $this->transaksi();

        $lap = $this->perbarui([$this->baris($kk, ['notaris' => 'Notaris Satu', 'email' => 'a@b.co'])], false, true, $pemberkasan);

        $this->assertNull($kk->fresh()->notaris_preset_id);
        $this->assertSame('a@b.co', $kk->konsumen->fresh()->email);
        $this->assertStringContainsString('tidak punya izin', $lap['warnings'][0]);
    }

    // ── Wilayah & domisili ──

    public function test_wilayah_diisi_dilengkapi_dan_konflik_butuh_timpa(): void
    {
        $kosong = $this->transaksi(['nama' => 'Siti Aminah']);
        $kabSaja = $this->transaksi(['nama' => 'Andi Wijaya'] + \App\Support\Wilayah::atribut('32.73'));
        $beda = $this->transaksi(['nama' => 'Dewi Lestari'] + \App\Support\Wilayah::atribut('32.04'));
        $barisList = [
            $this->baris($kosong, ['prov' => 'Jawa Barat', 'kab' => 'Kota Bandung', 'kec' => 'Sukasari', 'kel' => 'Sukarasa']),
            $this->baris($kabSaja, ['prov' => 'Jawa Barat', 'kab' => 'Kota Bandung', 'kec' => 'Sukasari']),
            $this->baris($beda, ['prov' => 'Jawa Barat', 'kab' => 'Kota Bandung']),
        ];

        $lap = $this->perbarui($barisList);
        $this->assertSame('Sukarasa', $kosong->konsumen->fresh()->kelurahan_nama);
        $this->assertSame('Sukasari', $kabSaja->konsumen->fresh()->kecamatan_nama);
        $this->assertSame('Kabupaten Bandung', $beda->konsumen->fresh()->kabupaten_nama);
        $this->assertStringContainsString('Wilayah KTP sudah terisi', $lap['warnings'][0]);

        $this->perbarui($barisList, true);
        $this->assertSame('Kota Bandung', $beda->konsumen->fresh()->kabupaten_nama);
    }

    public function test_wilayah_tidak_dikenal_atau_urutan_salah_dilewati(): void
    {
        $kk = $this->transaksi();
        $lap = $this->perbarui([$this->baris($kk, ['prov' => 'Jawa Barat', 'kab' => 'Kota Mars']), $this->baris($kk, ['kab' => 'Kota Bandung'])], false, false);

        $this->assertSame(2, $lap['skipped']);
        $this->assertStringContainsString('tidak dikenal', $lap['errors'][0]);
        $this->assertStringContainsString('Provinsi kosong', $lap['errors'][1]);
    }

    public function test_domisili_sama_berbeda_dan_kontradiksi(): void
    {
        $sama = $this->transaksi(['nama' => 'Siti Aminah']);
        $berbeda = $this->transaksi(['nama' => 'Andi Wijaya']);
        $salah = $this->transaksi(['nama' => 'Dewi Lestari']);

        $lap = $this->perbarui([
            $this->baris($sama, ['dom_sama' => 'Ya']),
            // "Tidak" boleh dikosongkan: mengisi kolom domisili sudah berarti berbeda.
            $this->baris($berbeda, ['dom_alamat' => 'Jl. Kos 7', 'dom_prov' => 'Jawa Tengah', 'dom_kab' => 'Kota Semarang', 'dom_kec' => 'Tembalang']),
            $this->baris($salah, ['dom_sama' => 'Ya', 'dom_alamat' => 'Jl. Lain']),
        ]);

        $this->assertTrue($sama->konsumen->fresh()->domisili_sama_ktp);
        $k = $berbeda->konsumen->fresh();
        $this->assertFalse($k->domisili_sama_ktp);
        $this->assertSame(['Jl. Kos 7', 'Kota Semarang', 'Tembalang'], [$k->domisili_alamat, $k->domisili_kabupaten_nama, $k->domisili_kecamatan_nama]);
        $this->assertNull($salah->konsumen->fresh()->domisili_sama_ktp);
        $this->assertStringContainsString('Domisili Sama KTP = Ya', $lap['errors'][0]);
    }

    public function test_domisili_tercatat_sama_dengan_ktp_hanya_diganti_dengan_timpa(): void
    {
        $kk = $this->transaksi(['domisili_sama_ktp' => true]);
        $baris = [$this->baris($kk, ['dom_sama' => 'Tidak', 'dom_alamat' => 'Jl. Kos 7'])];

        $this->perbarui($baris);
        $this->assertTrue($kk->konsumen->fresh()->domisili_sama_ktp);
        $this->assertNull($kk->konsumen->fresh()->domisili_alamat);

        $this->perbarui($baris, true);
        $this->assertFalse($kk->konsumen->fresh()->domisili_sama_ktp);
        $this->assertSame('Jl. Kos 7', $kk->konsumen->fresh()->domisili_alamat);
    }

    // ── Saran perapian alamat ──

    public function test_saran_perapian_mengisi_wilayah_dan_memangkas_alamat_di_unduhan_saja(): void
    {
        $kk = $this->transaksi(['alamat' => 'Jl. Semarang Raya No. 5 RT 01 RW 02, Kel. Bulusan, Kec. Tembalang, Kota Semarang 50277']);

        $ss = $this->unduh(['ktp'], ['saran' => 1]);

        $this->assertSame('Jl. Semarang Raya No. 5 RT 01 RW 02', $this->sel($ss, 'alamat'));
        $this->assertSame(['Jawa Tengah', 'Kota Semarang', 'Tembalang', 'Bulusan'], [$this->sel($ss, 'prov'), $this->sel($ss, 'kab'), $this->sel($ss, 'kec'), $this->sel($ss, 'kel')]);
        $this->assertStringContainsString('lengkap', $this->sel($ss, 'ref_keyakinan'));
        $this->assertStringContainsString('Kec. Tembalang', $this->sel($ss, 'ref_alamat'));
        $this->assertNull($kk->konsumen->fresh()->provinsi_kode, 'saran tidak pernah disimpan otomatis');

        // Diunggah dengan Timpa: wilayah terisi dan alamat diganti; kolom referensi diabaikan.
        $file = $this->simpan($ss);
        $this->kirim($file, 'cek', true);
        $this->kirim($file, 'import', true);
        $k = $kk->konsumen->fresh();
        $this->assertSame('Jl. Semarang Raya No. 5 RT 01 RW 02', $k->alamat);
        $this->assertSame('Bulusan', $k->kelurahan_nama);
        $this->assertSame('Jl. Semarang Raya No. 5 RT 01 RW 02, Bulusan, Tembalang, Kota Semarang, Jawa Tengah', $k->alamat_lengkap);
    }

    public function test_saran_tidak_menebak_saat_ambigu_atau_tidak_ketemu(): void
    {
        $penyaran = new \App\Support\SaranAlamat();

        $this->assertNull($penyaran->sarankan('Jl. Anggrek 3, Semarang')['kode'], 'nama kabupaten tanpa penanda tidak ditebak');
        $this->assertNull($penyaran->sarankan('Perum Entah Berantah Blok C')['kode']);
        $this->assertSame('33.74', $penyaran->sarankan('Jl. Anggrek 3, Kota Semarang, Jawa Tengah')['kode']);
        $this->assertSame('33.74.10', $penyaran->sarankan('Gang 2 Kec. Tembalang')['kode']);
    }

    // ── Header & izin ──

    public function test_judul_tidak_dikenal_atau_wajib_hilang_menolak_file_dan_urutan_kolom_bebas(): void
    {
        $kk = $this->transaksi();

        $asing = $this->file([['blok' => 'A1', 'unit' => '1', 'nama' => 'Budi Santoso']], ['Blok', 'Nomor Kavling', 'Nama Konsumen', 'Kolom Aneh']);
        $this->assertSame('ditolak', $this->kirim($asing, 'cek')->getSession()->get('importLaporan')['mode']);

        $tanpaNama = $this->file([['blok' => 'A1', 'unit' => '1']]);
        $this->assertSame('ditolak', $this->kirim($tanpaNama, 'cek')->getSession()->get('importLaporan')['mode']);

        $acak = $this->file([['email' => 'acak@contoh.com', 'nama' => 'Budi Santoso', 'unit' => $kk->kavling->nomor_kavling, 'blok' => 'A1']]);
        $this->assertSame(1, $this->kirim($acak, 'cek')->getSession()->get('importLaporan')['imported']);
    }

    public function test_pengguna_tanpa_izin_edit_konsumen_ditolak(): void
    {
        $u = User::factory()->create();
        $u->assignRole('pelaksana_lapangan');
        $u->projects()->attach($this->project->id);

        $this->actingAs($u)->get(route('konsumens.perbarui-template', ['project' => $this->project->id, 'kelompok' => ['notaris']]))->assertForbidden();
    }
}
