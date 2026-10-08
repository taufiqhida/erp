<?php

namespace Tests\Feature;

use App\Enums\StatusJual;
use App\Models\CancellationRequest;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\KonsumenImportSpec;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Import Konsumen — sheet "Batal" (konsumen batal, uang hangus, riwayat & audit). */
class ImportKonsumenBatalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private Kavling $kavling;
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->project = Project::create(['nama' => 'Proyek Uji', 'kode' => 'PU1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $stage = StatusBangunStage::first() ?? StatusBangunStage::create(['nama' => 'Belum Mulai', 'bobot' => 100, 'urutan' => 1, 'warna' => 'slate', 'is_default' => true]);
        $this->kavling = Kavling::create([
            'project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'status_bangun_stage_id' => $stage->id, 'kluster' => 'Melati', 'blok' => 'A1', 'nomor_kavling' => '1',
            'harga' => 300000000, 'status_jual' => StatusJual::Available,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        parent::tearDown();
    }

    /** Baris sheet Batal dengan urutan kolom spec. Nilai default = baris valid. */
    private function baris(array $override = []): array
    {
        $dasar = [
            'nama' => 'Budi Santoso', 'nik' => '', 'hp' => '081200000001', 'kluster' => 'Melati', 'blok' => 'A1', 'unit' => '1',
            'tgl_booking' => '2026-03-01', 'tgl_batal' => '2026-04-15', 'cara_bayar' => '', 'skema' => '', 'harga_deal' => '',
            'total_bayar' => 10000000, 'tgl_bayar' => '2026-03-20', 'dikembalikan' => '', 'alasan' => '', 'rincian' => '',
        ];
        $m = array_merge($dasar, $override);

        return array_map(fn ($c) => $m[$c[0]], KonsumenImportSpec::batalColumns());
    }

    private function fileBatal(array ...$barisList): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Batal');
        $sheet->fromArray([array_map(fn ($c) => $c[1], KonsumenImportSpec::batalColumns())], null, 'A1');
        foreach ($barisList as $i => $b) {
            $sheet->fromArray([$b], null, 'A' . ($i + 2), true);
        }
        $path = tempnam(sys_get_temp_dir(), 'batal') . '.xlsx';
        (new Xlsx($ss))->save($path);
        $this->tmp[] = $path;

        return new UploadedFile($path, 'batal.xlsx', null, null, true);
    }

    private function impor(UploadedFile $file, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('konsumens.import', $this->project), ['file' => $file]);
    }

    public function test_template_punya_sheet_batal_dengan_tanggal_bayar_terakhir_di_samping_total_dibayar(): void
    {
        $r = $this->actingAs($this->admin)->get(route('konsumens.import-template', $this->project));
        $r->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        $this->tmp[] = $path;
        file_put_contents($path, $r->streamedContent());

        $sheet = IOFactory::load($path)->getSheetByName('Batal');
        $this->assertNotNull($sheet);
        $headers = [];
        foreach (range(1, count(KonsumenImportSpec::batalColumns())) as $c) $headers[] = $sheet->getCell([$c, 1])->getValue();

        $iTotal = array_search('Total Dibayar *', $headers, true);
        $this->assertNotFalse($iTotal);
        $this->assertSame('Tanggal Bayar Terakhir', $headers[$iTotal + 1]);
        $this->assertSame('Dikembalikan', $headers[$iTotal + 2]);
    }

    public function test_batal_sebagian_hangus_tercatat_seperti_pembatalan_yang_disetujui(): void
    {
        $this->impor($this->fileBatal($this->baris([
            'nik' => '3201010101010001', 'total_bayar' => 15000000, 'dikembalikan' => 5000000,
            'alasan' => 'Mundur sendiri', 'rincian' => 'UTJ 5jt hangus, DP 5jt kembali 20 Apr',
        ])))->assertSessionHas('success');

        $konsumen = Konsumen::where('nik', '3201010101010001')->firstOrFail();
        $trx = KavlingKonsumen::where('konsumen_id', $konsumen->id)->firstOrFail();
        $this->assertSame('cancelled', $trx->status);
        $this->assertSame('batal', $trx->status_penjualan);
        $this->assertSame($this->kavling->id, $trx->kavling_id);

        $bayar = $trx->pembayarans()->get();
        $this->assertCount(1, $bayar);
        $this->assertSame('uang_masuk_batal', $bayar[0]->jenis);
        $this->assertEquals(15000000, $bayar[0]->jumlah);
        $this->assertSame('2026-03-20', $bayar[0]->tanggal_bayar->toDateString());
        $this->assertSame('Pembayaran Diterima (Batal)', $bayar[0]->jenis_label);

        $c = CancellationRequest::where('kavling_konsumen_id', $trx->id)->firstOrFail();
        $this->assertTrue($c->isApproved());
        $this->assertSame('cancellation', $c->type->value);
        $this->assertEquals(15000000, $c->nominal_diterima);
        $this->assertEquals(5000000, $c->nominal_dikembalikan);
        $this->assertEquals(10000000, $c->nominal_hangus);
        $this->assertSame('Mundur sendiri', $c->alasan);
        $this->assertSame('UTJ 5jt hangus, DP 5jt kembali 20 Apr', $c->catatan_reviewer);
        $this->assertSame('2026-04-15', $c->reviewed_at->toDateString());

        // Status kavling tidak disentuh.
        $this->assertSame(StatusJual::Available, $this->kavling->fresh()->status_jual);
    }

    public function test_batal_dengan_refund_penuh_hangusnya_nol_dan_alasan_default_dan_tanggal_bayar_cadangan(): void
    {
        $this->impor($this->fileBatal($this->baris(['total_bayar' => 8000000, 'dikembalikan' => 8000000, 'tgl_bayar' => ''])));

        $c = CancellationRequest::firstOrFail();
        $this->assertEquals(0, $c->nominal_hangus);
        $this->assertSame('Import data lama', $c->alasan);
        $this->assertSame('2026-03-01', KavlingKonsumen::firstOrFail()->pembayarans()->first()->tanggal_bayar->toDateString());
    }

    public function test_dikembalikan_kosong_berarti_seluruhnya_hangus(): void
    {
        $this->impor($this->fileBatal($this->baris(['total_bayar' => 5000000])));

        $c = CancellationRequest::firstOrFail();
        $this->assertEquals(0, $c->nominal_dikembalikan);
        $this->assertEquals(5000000, $c->nominal_hangus);
    }

    public function test_total_nol_membuat_transaksi_batal_tanpa_pembayaran(): void
    {
        $this->impor($this->fileBatal($this->baris(['total_bayar' => 0])));

        $trx = KavlingKonsumen::firstOrFail();
        $this->assertCount(0, $trx->pembayarans);
        $this->assertEquals(0, CancellationRequest::firstOrFail()->nominal_hangus);
    }

    public function test_kavling_yang_sudah_terjual_ke_konsumen_lain_tidak_terganggu(): void
    {
        $this->kavling->update(['status_jual' => StatusJual::Sold]);
        $lain = Konsumen::create(['nama' => 'Pembeli Baru']);
        $aktif = KavlingKonsumen::create([
            'kavling_id' => $this->kavling->id, 'konsumen_id' => $lain->id, 'status' => 'active',
            'tanggal_booking' => '2026-06-01', 'status_penjualan' => 'akad',
        ]);

        $this->impor($this->fileBatal($this->baris()))->assertSessionHas('success');

        $this->assertSame(StatusJual::Sold, $this->kavling->fresh()->status_jual);
        $this->assertSame('active', $aktif->fresh()->status);
        $this->assertSame(2, KavlingKonsumen::where('kavling_id', $this->kavling->id)->count());
    }

    public function test_impor_ulang_tidak_menggandakan(): void
    {
        $this->impor($this->fileBatal($this->baris()));
        $r = $this->impor($this->fileBatal($this->baris()));

        $this->assertSame(1, KavlingKonsumen::where('status', 'cancelled')->count());
        $this->assertSame(1, CancellationRequest::count());
        $r->assertSessionHas('importErrors', fn ($e) => str_contains(implode(' ', $e), 'Sudah ada konsumen batal'));
    }

    public function test_nik_yang_sama_memakai_konsumen_yang_ada(): void
    {
        $ada = Konsumen::create(['nama' => 'Budi Santoso', 'nik' => '3201010101010009']);

        $this->impor($this->fileBatal($this->baris(['nik' => '3201010101010009'])));

        $this->assertSame(1, Konsumen::count());
        $this->assertSame($ada->id, KavlingKonsumen::firstOrFail()->konsumen_id);
    }

    public function test_cara_bayar_dan_skema_opsional_dikenali_dan_diisi(): void
    {
        $skema = SkemaDpPreset::create(['nama' => 'DP 10%', 'cara_bayar' => 'kpr_subsidi']);

        $this->impor($this->fileBatal($this->baris(['cara_bayar' => 'KPR Subsidi', 'skema' => 'DP 10%', 'harga_deal' => 310000000])));

        $trx = KavlingKonsumen::firstOrFail();
        $this->assertSame('kpr_subsidi', $trx->cara_bayar);
        $this->assertSame($skema->id, $trx->skema_dp_preset_id);
        $this->assertEquals(310000000, $trx->harga_deal);
    }

    public function test_baris_tidak_valid_dilewati_dengan_pesan_dan_tidak_membuat_apa_apa(): void
    {
        $r = $this->impor($this->fileBatal(
            $this->baris(['nama' => 'A', 'total_bayar' => 1000000, 'dikembalikan' => 2000000]),               // refund > dibayar
            $this->baris(['nama' => 'B', 'tgl_booking' => '2026-05-01', 'tgl_batal' => '2026-04-01']),         // batal < booking
            $this->baris(['nama' => 'C', 'blok' => 'Z9']),                                                     // unit tidak ada
            $this->baris(['nama' => 'D', 'total_bayar' => '']),                                                // total kosong
            $this->baris(['nama' => 'E', 'tgl_bayar' => '2026-09-01']),                                        // bayar setelah batal
            $this->baris(['nama' => 'F', 'cara_bayar' => 'Barter']),                                           // cara bayar tak dikenal
        ));

        $this->assertSame(0, KavlingKonsumen::count());
        $this->assertSame(0, CancellationRequest::count());
        $this->assertSame(0, Konsumen::count());
        $r->assertSessionHas('importErrors', function ($e) {
            $t = implode("\n", $e);
            return str_contains($t, 'Dikembalikan melebihi Total Dibayar')
                && str_contains($t, 'Tanggal Batal lebih awal')
                && str_contains($t, 'tidak ditemukan di Stok Kavling')
                && str_contains($t, 'Total Dibayar kosong')
                && str_contains($t, 'melewati Tanggal Batal')
                && str_contains($t, "Cara Bayar 'Barter' tidak dikenal");
        });
    }

    public function test_hanya_yang_punya_izin_review_pembatalan_boleh_memakai_sheet_batal(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('admin_sales');
        $sales->projects()->attach($this->project->id); // boleh Import Konsumen (book kavling) tapi tanpa review cancellation

        $r = $this->impor($this->fileBatal($this->baris()), $sales);

        $this->assertSame(0, KavlingKonsumen::count());
        $r->assertSessionHas('importErrors', fn ($e) => str_contains(implode(' ', $e), 'izin'));
    }

    public function test_uang_hangus_masuk_pendapatan_dashboard(): void
    {
        $this->impor($this->fileBatal($this->baris(['total_bayar' => 12000000, 'dikembalikan' => 2000000])));

        $html = $this->actingAs($this->admin)->withSession(['current_project_id' => $this->project->id])
            ->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/data-page="([^"]+)"/', $html, $m);
        $props = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];

        $this->assertEquals(10000000, $props['financials']['hangus_dari_pembatalan']);
        $this->assertEquals(10000000, $props['financials']['total_pendapatan']);
    }

    public function test_halaman_terkait_transaksi_batal_impor_tetap_terbuka(): void
    {
        $this->impor($this->fileBatal($this->baris(['total_bayar' => 6000000, 'dikembalikan' => 1000000])));
        $trx = KavlingKonsumen::firstOrFail();
        $sesi = ['current_project_id' => $this->project->id];

        $this->actingAs($this->admin)->withSession($sesi)->get(route('keuangan.detail', $trx))->assertOk();
        $this->actingAs($this->admin)->withSession($sesi)->get(route('cancellation-requests.index'))->assertOk();
        $this->actingAs($this->admin)->withSession($sesi)->get(route('konsumens.show', $trx->konsumen_id))->assertOk();
    }
}
