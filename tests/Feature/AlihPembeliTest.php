<?php

namespace Tests\Feature;

use App\Enums\CancellationStatus;
use App\Models\AlihPembeliRiwayat;
use App\Models\BankRekananPreset;
use App\Models\CancellationRequest;
use App\Models\CatatanTransaksi;
use App\Models\DokumenKonsumen;
use App\Models\DokumenTemplate;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Alih Pembeli: pembeli diganti (A → B) tanpa membatalkan transaksi; UTJ/pembayaran tetap; data milik pembeli lama tidak terbawa. */
class AlihPembeliTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Kavling $unit;
    private Konsumen $a;
    private KavlingKonsumen $trx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        DokumenTemplate::query()->delete();
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $this->a = Konsumen::create(['nama' => 'Anton', 'nik' => '3201000000000001', 'no_hp' => '0811111', 'status_pernikahan' => 'menikah', 'pekerjaan' => 'karyawan_swasta']);
        $bank = BankRekananPreset::create(['nama' => 'BTN', 'is_active' => true]);
        $this->trx = KavlingKonsumen::create([
            'kavling_id' => $this->unit->id, 'konsumen_id' => $this->a->id, 'status' => 'active', 'status_penjualan' => 'sp3k', 'cara_bayar' => 'kpr_subsidi',
            'tanggal_booking' => now()->subMonth(), 'bank_rekanan_preset_id' => $bank->id, 'tanggal_pengajuan_bank' => now()->subDays(20), 'tanggal_keputusan_bank' => now()->subDays(10),
            'status_bank' => 'disetujui', 'catatan_bank' => 'SLIK A kurang baik', 'tanggal_sp3k' => now()->subDays(5), 'tanggal_expired_sp3k' => now()->addDays(60),
            'status_sp3k' => 'approved', 'catatan_sp3k' => 'sp3k A', 'tanggal_rencana_akad' => now()->addDays(14), 'plafon_kpr' => 150000000,
        ]);
        PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => 'booking_fee', 'jumlah' => 5000000, 'tanggal_bayar' => now()->subMonth(), 'keterangan' => 'UTJ']);

        $ktp = DokumenTemplate::create(['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'KTP', 'sifat' => 'wajib', 'urutan' => 1]);
        $pasangan = DokumenTemplate::create(['cara_bayar' => 'kpr_subsidi', 'nama_dokumen' => 'KTP Pasangan', 'sifat' => 'kondisional', 'syarat_pernikahan' => ['menikah'], 'urutan' => 2]);
        foreach ([$ktp, $pasangan] as $t) {
            DokumenKonsumen::create(['kavling_konsumen_id' => $this->trx->id] + DokumenKonsumen::atributDariTemplate($t, $this->a, 'sudah_ada'));
        }
    }

    private function user(string $role, bool $ditugaskan = true): User
    {
        $u = User::factory()->create(['name' => ucfirst($role) . ' Uji']);
        $u->assignRole($role);
        if ($ditugaskan) $u->projects()->attach($this->project->id);

        return $u;
    }

    private function data(array $ubah = []): array
    {
        return array_replace_recursive([
            'baru' => ['nama' => 'Budi', 'nik' => '3201000000000002', 'no_hp' => '0822222', 'status_pernikahan' => 'belum_menikah', 'pekerjaan' => 'wirausaha'],
            'hubungan' => 'saudara', 'alasan' => 'BI Checking pembeli awal tidak lolos',
        ], $ubah);
    }

    private function ajukan(array $ubah = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user('admin_sales'))->post(route('alih-pembeli.store', $this->trx), $this->data($ubah));
    }

    private function pengajuan(): CancellationRequest
    {
        return CancellationRequest::where('type', 'buyer_transfer')->latest('id')->firstOrFail();
    }

    // ── Pengajuan ────────────────────────────────────────────────────────

    public function test_admin_sales_mengajukan_alih_pembeli_dan_belum_ada_yang_berubah_sebelum_disetujui(): void
    {
        $this->ajukan()->assertSessionHas('success');

        $p = $this->pengajuan();
        $this->assertSame('pending', $p->status->value);
        $this->assertSame('Budi', $p->konsumenBaru->nama);
        $this->assertSame('saudara', $p->hubungan);
        $this->assertSame('booked', $this->unit->fresh()->status_jual->value);                  // status unit tidak disentuh
        $this->assertSame($this->a->id, $this->trx->fresh()->konsumen_id);                      // pembeli masih A
        $this->assertSame('sp3k', $this->trx->fresh()->status_penjualan);
    }

    public function test_pengajuan_memakai_konsumen_yang_sudah_terdaftar(): void
    {
        $b = Konsumen::create(['nama' => 'Citra', 'nik' => '3201000000000009', 'no_hp' => '0833333']);

        $this->actingAs($this->user('admin_sales'))->post(route('alih-pembeli.store', $this->trx), ['konsumen_baru_id' => $b->id, 'hubungan' => 'pasangan', 'alasan' => 'Dialihkan ke pasangan'])->assertSessionHas('success');

        $this->assertSame($b->id, $this->pengajuan()->konsumen_baru_id);
        $this->assertSame(2, Konsumen::count());                                                // tidak membuat konsumen ganda
    }

    public function test_validasi_pembeli_baru_tidak_boleh_sama_dengan_pembeli_lama(): void
    {
        $this->ajukan(['baru' => ['no_hp' => '0811111']])->assertSessionHasErrors('baru.no_hp');
        $this->ajukan(['baru' => ['nik' => '3201000000000001']])->assertSessionHasErrors('baru.nik');
        $this->actingAs($this->user('admin_sales'))->post(route('alih-pembeli.store', $this->trx), ['konsumen_baru_id' => $this->a->id, 'hubungan' => 'saudara', 'alasan' => 'alasan cukup panjang'])->assertSessionHasErrors('konsumen_baru_id');
        $this->ajukan(['alasan' => 'pendek'])->assertSessionHasErrors('alasan');
        $this->ajukan(['hubungan' => 'teman'])->assertSessionHasErrors('hubungan');
        $this->assertSame(0, CancellationRequest::where('type', 'buyer_transfer')->count());
        $this->assertSame(1, Konsumen::count());                                                // konsumen baru tidak terbuat saat gagal
    }

    public function test_hanya_sebelum_akad_dan_tidak_boleh_ada_dua_pengajuan_bersamaan(): void
    {
        $this->trx->update(['status_penjualan' => 'akad']);
        $this->ajukan()->assertStatus(422);

        $this->trx->update(['status_penjualan' => 'rencana_akad']);
        $this->ajukan()->assertSessionHas('success');
        $this->ajukan(['baru' => ['nik' => '3201000000000099', 'no_hp' => '0899']])->assertStatus(422);    // sudah ada yang menunggu
        $this->assertSame(1, CancellationRequest::where('type', 'buyer_transfer')->count());
    }

    public function test_izin_mengajukan_hanya_admin_sales_di_proyeknya(): void
    {
        foreach (['admin_pemberkasan', 'spv', 'leader', 'admin_proyek', 'direktur'] as $peran) {
            $this->ajukan([], $this->user($peran))->assertStatus(403);
        }
        $this->ajukan([], $this->user('admin_sales', false))->assertStatus(403);               // tidak ditugaskan ke proyek ini
        $this->assertSame(0, CancellationRequest::count());
    }

    // ── Persetujuan ──────────────────────────────────────────────────────

    public function test_persetujuan_mengganti_pembeli_tanpa_menyentuh_uang_dan_membersihkan_data_pembeli_lama(): void
    {
        $this->ajukan()->assertSessionHas('success');
        $spv = $this->user('spv');

        $this->actingAs($spv)->patch(route('cancellation-requests.approve', $this->pengajuan()), ['catatan_reviewer' => 'oke'])->assertSessionHas('success');

        $trx = $this->trx->fresh();
        $b = Konsumen::where('nama', 'Budi')->firstOrFail();
        $this->assertSame($b->id, $trx->konsumen_id);
        $this->assertSame('pemberkasan', $trx->status_penjualan);
        $this->assertSame('active', $trx->status);

        // Uang tetap pada transaksi yang sama (UTJ tidak dihitung dua kali).
        $this->assertSame(1, $trx->pembayarans()->count());
        $this->assertEquals(5000000, $trx->pembayarans()->sum('jumlah'));

        // Hasil proses milik pembeli lama tidak terbawa ke pembeli baru.
        foreach (['status_bank', 'tanggal_pengajuan_bank', 'tanggal_keputusan_bank', 'catatan_bank', 'status_sp3k', 'tanggal_sp3k', 'tanggal_expired_sp3k', 'catatan_sp3k', 'tanggal_rencana_akad'] as $kolom) {
            $this->assertNull($trx->{$kolom}, $kolom);
        }
        $this->assertSame('booked', $this->unit->fresh()->status_jual->value);
        $this->assertSame(0, $this->a->fresh()->kavlingKonsumens()->count());                   // A tidak lagi punya transaksi

        $p = $this->pengajuan();
        $this->assertSame(CancellationStatus::Approved, $p->status);
        $this->assertSame($spv->id, $p->reviewed_by);
    }

    public function test_berkas_pembeli_lama_diarsipkan_dan_pembeli_baru_mulai_dari_daftar_baru(): void
    {
        $this->ajukan();
        $this->actingAs($this->user('spv'))->patch(route('cancellation-requests.approve', $this->pengajuan()), []);

        $trx = $this->trx->fresh();
        $this->assertSame(2, $trx->dokumensArsip()->count());
        $this->assertSame([$this->a->id], $trx->dokumensArsip()->pluck('diarsipkan_untuk_konsumen_id')->unique()->values()->all());

        $baru = $trx->dokumens()->get()->keyBy('nama_dokumen');
        $this->assertSame(2, $baru->count());
        $this->assertSame('belum_ada', $baru['KTP']->status);                                  // status 'sudah_ada' milik A tidak terbawa
        $this->assertFalse($baru['KTP Pasangan']->berlaku);                                    // B belum menikah: syarat tidak terpenuhi
        $this->assertSame(['persen' => 0.0, 'ada' => 0, 'total' => 1], array_map(fn ($v) => is_float($v) ? (float) $v : $v, $trx->progress_berkas));
    }

    public function test_riwayat_catatan_dan_tampilan_dua_arah(): void
    {
        $this->ajukan();
        $spv = $this->user('spv');
        $this->actingAs($spv)->patch(route('cancellation-requests.approve', $this->pengajuan()), []);

        $r = AlihPembeliRiwayat::firstOrFail();
        $b = Konsumen::where('nama', 'Budi')->firstOrFail();
        $this->assertSame([$this->a->id, $b->id, 'sp3k'], [$r->dari_konsumen_id, $r->ke_konsumen_id, $r->tahap_sebelum]);
        $this->assertSame($spv->id, $r->disetujui_oleh);

        $catatan = CatatanTransaksi::where('tahap', 'Alih pembeli')->firstOrFail();
        $this->assertStringContainsString('dari Anton ke Budi', $catatan->isi);

        $admin = $this->user('superadmin', false);
        // Profil A: dialihkan ke B. Profil B: peralihan dari A. Keduanya melihat riwayat yang sama.
        $this->actingAs($admin)->get(route('konsumens.show', $this->a))->assertOk()
            ->assertInertia(fn ($page) => $page->has('peralihan', 1)->where('peralihan.0.dari', 'Anton')->where('peralihan.0.ke', 'Budi'));
        $this->actingAs($admin)->get(route('konsumens.show', $b))->assertOk()
            ->assertInertia(fn ($page) => $page->where('peralihan.0.dari', 'Anton')->has('transaksis.0.peralihan', 1)->where('transaksis.0.peralihan.0.hubungan', 'Saudara kandung'));
        $this->actingAs($admin)->get(route('dokumen.index', $this->trx))->assertOk()
            ->assertInertia(fn ($page) => $page->where('transaksi.peralihan.0.ke', 'Budi')->where('transaksi.has_pending_request', false));
    }

    public function test_penolakan_tidak_mengubah_apa_pun_dan_admin_sales_tidak_bisa_menyetujui(): void
    {
        $this->ajukan();
        $p = $this->pengajuan();

        $this->actingAs($this->user('admin_sales'))->patch(route('cancellation-requests.approve', $p), [])->assertStatus(403);
        $this->assertSame($this->a->id, $this->trx->fresh()->konsumen_id);

        $this->actingAs($this->user('leader'))->patch(route('cancellation-requests.reject', $p), ['catatan_reviewer' => 'Data belum lengkap'])->assertSessionHas('success');

        $this->assertSame(CancellationStatus::Rejected, $p->fresh()->status);
        $this->assertSame($this->a->id, $this->trx->fresh()->konsumen_id);
        $this->assertSame('sp3k', $this->trx->fresh()->status_penjualan);
        $this->assertSame('SLIK A kurang baik', $this->trx->fresh()->catatan_bank);              // data pembeli lama utuh
        $this->assertSame('booked', $this->unit->fresh()->status_jual->value);
        $this->assertSame(0, AlihPembeliRiwayat::count());
    }

    public function test_persetujuan_ditolak_bila_transaksi_sudah_melewati_akad_saat_ditinjau(): void
    {
        $this->ajukan();
        $this->trx->update(['status_penjualan' => 'akad']);

        $this->actingAs($this->user('spv'))->patch(route('cancellation-requests.approve', $this->pengajuan()), [])->assertStatus(422);

        $this->assertSame($this->a->id, $this->trx->fresh()->konsumen_id);
        $this->assertSame('pending', $this->pengajuan()->status->value);
    }

    // ── Pendukung ────────────────────────────────────────────────────────

    public function test_halaman_dokumen_menampilkan_tombol_hanya_bila_boleh_dan_pencarian_konsumen_bekerja(): void
    {
        Konsumen::create(['nama' => 'Citra Dewi', 'nik' => '3201000000000009', 'no_hp' => '0833333']);
        $sales = $this->user('admin_sales');

        $this->actingAs($sales)->get(route('dokumen.index', $this->trx))->assertInertia(fn ($page) => $page->where('transaksi.can_alih_pembeli', true));
        $this->actingAs($this->user('admin_pemberkasan'))->get(route('dokumen.index', $this->trx))->assertInertia(fn ($page) => $page->where('transaksi.can_alih_pembeli', false));

        $hasil = $this->actingAs($sales)->getJson(route('alih-pembeli.cari', [$this->trx, 'q' => 'Citra']))->assertOk()->json();
        $this->assertSame(['Citra Dewi'], array_column($hasil, 'nama'));
        $this->assertSame([], $this->actingAs($sales)->getJson(route('alih-pembeli.cari', [$this->trx, 'q' => 'Anton']))->json());      // pembeli saat ini tidak muncul

        $this->ajukan();
        $this->actingAs($sales)->get(route('dokumen.index', $this->trx))->assertInertia(fn ($page) => $page->where('transaksi.can_alih_pembeli', false)->where('transaksi.pending_request_type', 'buyer_transfer'));
    }

    public function test_daftar_pembatalan_memuat_pengajuan_alih_pembeli(): void
    {
        $this->ajukan();

        $this->actingAs($this->user('spv'))->get(route('cancellation-requests.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('requests.data.0.type', 'buyer_transfer')->where('requests.data.0.type_label', 'Alih Pembeli')
                ->where('requests.data.0.konsumen', 'Anton')->where('requests.data.0.konsumen_baru', 'Budi')->where('requests.data.0.hubungan', 'Saudara kandung'));
    }
}
