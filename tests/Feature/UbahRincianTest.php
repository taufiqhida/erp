<?php

namespace Tests\Feature;

use App\Models\BiayaTambahanPreset;
use App\Models\CancellationRequest;
use App\Models\CatatanTransaksi;
use App\Models\JadwalTagihan;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\PromoPreset;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\KoreksiTanggalBooking;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Perubahan Rincian Pemesanan (termasuk Tanggal Booking dan harga dasar mengikuti unit): SPV/Leader/Superadmin langsung,
 * Admin Sales lewat usulan yang disetujui SPV/Leader.
 */
class UbahRincianTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Kavling $unit;
    private KavlingKonsumen $trx;
    private BiayaTambahanPreset $hook;
    private PromoPreset $promo;
    private PembayaranKonsumen $utj;
    /** @var JadwalTagihan[] */
    private array $j = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $this->unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'booked', 'harga' => 320000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $konsumen = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        // Harga dasar tercatat 300 jt (unit sudah 320 jt), tanpa biaya tambahan/diskon; harga deal 300 jt. Booking tercatat 10 Okt 2026.
        $this->trx = KavlingKonsumen::create(['kavling_id' => $this->unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
            'cara_bayar' => 'cash_bertahap', 'tanggal_booking' => '2026-10-10', 'harga_dasar' => 300000000, 'total_biaya_tambahan' => 0, 'harga_deal' => 300000000]);
        $this->hook = BiayaTambahanPreset::create(['nama' => 'Biaya Hook', 'is_active' => true]);
        $this->promo = PromoPreset::create(['nama' => 'Free Kanopi', 'is_active' => true]);

        $this->utj = PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => 'booking_fee', 'jumlah' => 5000000, 'tanggal_bayar' => '2026-10-10',
            'keterangan' => KoreksiTanggalBooking::KETERANGAN_UTJ_OTOMATIS]);
        $dpDibayar = PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => 'dp', 'jumlah' => 5000000, 'tanggal_bayar' => '2026-10-12', 'keterangan' => 'DP #1']);
        $this->j['utj'] = $this->jadwal('booking_fee', 1, '2026-10-10', 'lunas', $this->utj->id);
        $this->j['dp1'] = $this->jadwal('dp', 1, '2026-10-10', 'lunas', $dpDibayar->id);
        $this->j['dp2'] = $this->jadwal('dp', 2, '2026-11-10', 'belum_bayar');
    }

    private function jadwal(string $jenis, int $no, string $tempo, string $status, ?int $bayar = null): JadwalTagihan
    {
        return JadwalTagihan::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => $jenis, 'nomor_cicilan' => $no, 'jumlah' => 5000000,
            'tanggal_jatuh_tempo' => $tempo, 'status' => $status, 'pembayaran_konsumen_id' => $bayar]);
    }

    private function user(string $role, bool $ditugaskan = true): User
    {
        $u = User::factory()->create(['name' => ucfirst($role) . ' Uji']);
        $u->assignRole($role);
        if ($ditugaskan) $u->projects()->attach($this->project->id);

        return $u;
    }

    /** Isi form lengkap (frontend selalu mengirim semua kolom rincian). */
    private function form(array $ubah = []): array
    {
        return array_replace([
            'biaya_kelebihan_tanah_aktif' => false, 'biaya_kelebihan_tanah_mode' => 'per_m2', 'biaya_tambahan' => [], 'promo_preset_id' => null,
            'diskon_mode' => null, 'diskon_nilai' => null, 'program_all_in_preset_id' => null,
        ], $ubah);
    }

    private function kirim(array $data, ?User $u = null, bool $json = false)
    {
        $req = $this->actingAs($u ?? $this->user('admin_sales'));

        return $json ? $req->patchJson(route('bookings.rincian-pesanan', $this->trx), $data) : $req->patch(route('bookings.rincian-pesanan', $this->trx), $data);
    }

    private function tgl(JadwalTagihan $j): string
    {
        return $j->fresh()->tanggal_jatuh_tempo->format('Y-m-d');
    }

    // ── Jalur langsung (SPV/Leader/Superadmin) ───────────────────────────

    public function test_spv_mengubah_rincian_langsung_dan_harga_deal_dihitung_ulang(): void
    {
        $this->kirim($this->form([
            'biaya_tambahan' => [['id' => null, 'preset_id' => $this->hook->id, 'nominal' => 10000000]], 'promo_preset_id' => $this->promo->id,
            'diskon_mode' => 'persen', 'diskon_nilai' => 2,
        ]), $this->user('spv'))->assertSessionHas('success');

        $trx = $this->trx->fresh();
        $this->assertEquals(10000000, $trx->total_biaya_tambahan);
        $this->assertEquals(6200000, $trx->diskon_nominal);                                    // 2% × (300 jt + 10 jt)
        $this->assertEquals(303800000, $trx->harga_deal);
        $this->assertSame($this->promo->id, $trx->promo_preset_id);
        $this->assertSame(1, $trx->biayaTambahans()->count());
        $this->assertTrue(CatatanTransaksi::where('tahap', 'Ubah rincian')->exists());
        $this->assertTrue(Activity::where('log_name', 'transaksi')->where('description', 'like', 'Rincian pemesanan diubah langsung')->exists());
    }

    public function test_harga_dasar_bisa_mengikuti_harga_unit_saat_ini_dan_butuh_alasan(): void
    {
        $spv = $this->user('spv');

        $this->kirim($this->form(['harga_dasar_ke_unit' => true]), $spv)->assertSessionHasErrors('alasan');
        $this->assertEquals(300000000, $this->trx->fresh()->harga_dasar);

        $this->kirim($this->form(['harga_dasar_ke_unit' => true, 'alasan' => 'Menyesuaikan harga unit sesudah tukar']), $spv)->assertSessionHas('success');
        $this->assertEquals(320000000, $this->trx->fresh()->harga_dasar);
        $this->assertEquals(320000000, $this->trx->fresh()->harga_deal);
    }

    public function test_tanggal_booking_menggeser_utj_bawaan_dan_cicilan_belum_dibayar_serta_mengunci_yang_sudah_dibayar(): void
    {
        $this->kirim($this->form(['tanggal_booking' => '2026-10-05', 'alasan' => 'Salah ketik tanggal saat input booking']), $this->user('spv'))->assertSessionHas('success');

        $this->assertSame('2026-10-05', $this->trx->fresh()->tanggal_booking->format('Y-m-d'));
        $this->assertSame('2026-10-05', $this->utj->fresh()->tanggal_bayar->format('Y-m-d'));       // UTJ bawaan ikut
        $this->assertSame('2026-10-05', $this->tgl($this->j['utj']));
        $this->assertSame('2026-11-05', $this->tgl($this->j['dp2']));                               // belum dibayar: digeser −5 hari
        $this->assertSame('2026-10-10', $this->tgl($this->j['dp1']));                               // dibayar manual: terkunci
        $this->assertEquals(5000000, $this->utj->fresh()->jumlah);                                  // uang tidak berubah
    }

    public function test_tanpa_perubahan_tidak_menyimpan_apa_pun(): void
    {
        $this->kirim($this->form(), $this->user('spv'))->assertSessionHas('warning');

        $this->assertSame(0, CatatanTransaksi::count());
        $this->assertSame(0, CancellationRequest::count());
    }

    public function test_komponen_yang_sudah_dibayar_tetap_terkunci(): void
    {
        $item = $this->trx->biayaTambahans()->create(['biaya_tambahan_preset_id' => $this->hook->id, 'nama' => 'Biaya Hook', 'nominal' => 8000000]);
        PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'kavling_konsumen_biaya_tambahan_id' => $item->id, 'jenis' => 'biaya_tambahan', 'jumlah' => 8000000, 'tanggal_bayar' => '2026-10-12']);

        $this->kirim($this->form(['biaya_tambahan' => [['id' => $item->id, 'preset_id' => $this->hook->id, 'nominal' => 1000]]]), $this->user('spv'))->assertSessionHasErrors('rincian');

        $this->assertEquals(8000000, $item->fresh()->nominal);                                        // tidak berubah
    }

    // ── Jalur usulan (Admin Sales) ───────────────────────────────────────

    public function test_admin_sales_mengirim_usulan_yang_belum_berlaku_sebelum_disetujui(): void
    {
        $this->kirim($this->form(['promo_preset_id' => $this->promo->id, 'diskon_mode' => 'nominal', 'diskon_nilai' => 5000000, 'alasan' => 'Konsumen dijanjikan free kanopi dan potongan']))->assertSessionHas('success');

        $p = CancellationRequest::where('type', 'rincian_change')->firstOrFail();
        $this->assertSame('pending', $p->status->value);
        $this->assertSame($this->promo->id, $p->payload['rincian']['promo_preset_id']);
        $this->assertSame($this->trx->konsumen_id, $p->konsumen_id);
        $this->assertNull($this->trx->fresh()->promo_preset_id);                                    // belum berlaku
        $this->assertEquals(300000000, $this->trx->fresh()->harga_deal);
        $this->assertSame('booked', $this->unit->fresh()->status_jual->value);                       // status unit tidak disentuh
    }

    public function test_usulan_wajib_beralasan_dan_hanya_satu_yang_aktif_per_transaksi(): void
    {
        $data = $this->form(['diskon_mode' => 'nominal', 'diskon_nilai' => 1000000]);

        $this->kirim($data)->assertSessionHasErrors('alasan');
        $this->kirim($data + ['alasan' => 'Tambahan diskon dari manajemen'])->assertSessionHas('success');
        $this->kirim($data + ['alasan' => 'Diajukan sekali lagi'])->assertSessionHasErrors('rincian');
        $this->assertSame(1, CancellationRequest::where('type', 'rincian_change')->count());
    }

    public function test_usulan_tidak_mengunci_pemberkasan_dan_pengajuan_lain_tetap_bisa_dibuat(): void
    {
        $this->kirim($this->form(['diskon_mode' => 'nominal', 'diskon_nilai' => 1000000, 'alasan' => 'Tambahan diskon dari manajemen']))->assertSessionHas('success');

        $this->actingAs($this->user('spv'))->get(route('dokumen.index', $this->trx))->assertInertia(fn ($page) => $page->where('transaksi.has_pending_rincian', true)->where('transaksi.has_pending_request', false));

        $sales = $this->user('admin_sales');
        $this->actingAs($sales)->post(route('cancellation-requests.store'), ['type' => 'cancellation', 'kavling_id' => $this->unit->id, 'kavling_konsumen_id' => $this->trx->id, 'alasan' => 'Konsumen mundur dari pembelian'])->assertSessionHas('success');
    }

    public function test_persetujuan_menerapkan_usulan_dan_mencatat_pengaju_dan_penyetuju(): void
    {
        $sales = $this->user('admin_sales');
        $this->kirim($this->form(['biaya_tambahan' => [['id' => null, 'preset_id' => $this->hook->id, 'nominal' => 10000000]], 'tanggal_booking' => '2026-10-05', 'alasan' => 'Tanggal dan biaya hook perlu dikoreksi']), $sales)->assertSessionHas('success');
        $spv = $this->user('spv');
        $p = CancellationRequest::where('type', 'rincian_change')->firstOrFail();

        $this->actingAs($spv)->patch(route('cancellation-requests.approve', $p), ['catatan_reviewer' => 'setuju'])->assertSessionHas('success');

        $trx = $this->trx->fresh();
        $this->assertEquals(10000000, $trx->total_biaya_tambahan);
        $this->assertEquals(310000000, $trx->harga_deal);
        $this->assertSame('2026-10-05', $trx->tanggal_booking->format('Y-m-d'));
        $this->assertSame('2026-11-05', $this->tgl($this->j['dp2']));
        $this->assertSame('approved', $p->fresh()->status->value);
        $this->assertSame($spv->id, $p->fresh()->reviewed_by);
        $catatan = CatatanTransaksi::where('tahap', 'Ubah rincian')->firstOrFail();
        $this->assertStringContainsString("diusulkan {$sales->name}, disetujui {$spv->name}", $catatan->isi);
    }

    public function test_komponen_yang_terkunci_pembayaran_selama_menunggu_tidak_ikut_berubah_saat_disetujui(): void
    {
        $this->kirim($this->form(['biaya_kelebihan_tanah_aktif' => true, 'biaya_kelebihan_tanah_mode' => 'nominal', 'biaya_kelebihan_tanah_nominal_input' => 4000000, 'alasan' => 'Ada kelebihan tanah 10 m2']))->assertSessionHas('success');
        $p = CancellationRequest::where('type', 'rincian_change')->firstOrFail();
        // Selama menunggu, ada pembayaran Biaya Tanah → komponen kelebihan tanah terkunci; usulan tidak lagi mengubahnya.
        PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => 'biaya_tanah', 'jumlah' => 1000000, 'tanggal_bayar' => '2026-10-13']);

        $this->actingAs($this->user('spv'))->patch(route('cancellation-requests.approve', $p), [])->assertStatus(302);

        // Nilai kelebihan tanah lama (tidak aktif) dipertahankan, jadi tidak ada perubahan yang berlaku.
        $this->assertFalse((bool) $this->trx->fresh()->biaya_kelebihan_tanah_aktif);
    }

    public function test_penolakan_tidak_mengubah_apa_pun(): void
    {
        $this->kirim($this->form(['diskon_mode' => 'nominal', 'diskon_nilai' => 1000000, 'alasan' => 'Tambahan diskon dari manajemen']))->assertSessionHas('success');
        $p = CancellationRequest::where('type', 'rincian_change')->firstOrFail();

        $this->actingAs($this->user('leader'))->patch(route('cancellation-requests.reject', $p), ['catatan_reviewer' => 'Tidak disetujui manajemen'])->assertSessionHas('success');

        $this->assertSame('rejected', $p->fresh()->status->value);
        $this->assertEquals(300000000, $this->trx->fresh()->harga_deal);
        $this->assertSame('booked', $this->unit->fresh()->status_jual->value);
    }

    public function test_daftar_pengajuan_menampilkan_perbedaan_sebelum_sesudah_untuk_spv(): void
    {
        $this->kirim($this->form(['promo_preset_id' => $this->promo->id, 'diskon_mode' => 'nominal', 'diskon_nilai' => 5000000, 'tanggal_booking' => '2026-10-05', 'alasan' => 'Free kanopi dan tanggal booking']))->assertSessionHas('success');

        $this->actingAs($this->user('spv'))->get(route('cancellation-requests.index'))->assertOk()->assertInertia(function ($page) {
            $r = collect($page->toArray()['props']['requests']['data'])->firstWhere('type', 'rincian_change');
            $this->assertSame('Ubah Rincian', $r['type_label']);
            $baris = collect($r['perubahan']['baris'])->keyBy('label');
            $this->assertSame(['10 Oct 2026', '05 Oct 2026'], [$baris['Tanggal booking']['lama'], $baris['Tanggal booking']['baru']]);
            $this->assertSame('Free Kanopi', $baris['Promo']['baru']);
            $this->assertSame('Rp 295.000.000', $baris['Harga deal']['baru']);
            $this->assertNull($r['perubahan']['konflik']);

            return $page;
        });
        $this->assertSame('2026-10-10', $this->trx->fresh()->tanggal_booking->format('Y-m-d'));      // melihat daftar tidak mengubah data
        $this->assertNull($this->trx->fresh()->promo_preset_id);
    }

    // ── Izin ─────────────────────────────────────────────────────────────

    public function test_izin_per_peran_dan_tahap(): void
    {
        $data = $this->form(['diskon_mode' => 'nominal', 'diskon_nilai' => 1000000, 'alasan' => 'Tambahan diskon dari manajemen']);

        foreach (['admin_pemberkasan', 'admin_keuangan', 'pelaksana_lapangan', 'direktur'] as $peran) {
            $this->kirim($data, $this->user($peran))->assertStatus(403);
        }
        $this->kirim($data, $this->user('admin_sales', false))->assertStatus(403);                   // bukan proyeknya

        // Setelah Akad: Admin Sales ditolak, SPV (langsung) boleh.
        $this->trx->update(['status_penjualan' => 'akad']);
        $this->kirim($data)->assertStatus(403);
        $this->kirim($data, $this->user('spv'))->assertSessionHas('success');
        $this->assertEquals(299000000, $this->trx->fresh()->harga_deal);

        // Transaksi Selesai (terkunci): Admin Sales ditolak.
        $this->trx->update(['status' => 'completed']);
        $this->kirim($data)->assertStatus(403);
    }

    public function test_transaksi_batal_dan_validasi_tanggal(): void
    {
        $spv = $this->user('spv');
        $this->kirim($this->form(['tanggal_booking' => now()->addDays(2)->format('Y-m-d'), 'alasan' => 'Tanggal di masa depan']), $spv)->assertSessionHasErrors('tanggal_booking');
        $this->kirim($this->form(['tanggal_booking' => '2026-10-05']), $spv, true)->assertStatus(422);   // alasan wajib untuk perubahan tanggal

        $this->trx->update(['status' => 'cancelled', 'status_penjualan' => 'batal']);
        $this->kirim($this->form(['diskon_mode' => 'nominal', 'diskon_nilai' => 1000]), $spv)->assertStatus(422);
    }

    public function test_pratinjau_json_tidak_menyimpan_dan_profil_membawa_izin_tombol(): void
    {
        $r = $this->kirim($this->form(['promo_preset_id' => $this->promo->id, 'simulasi' => true]), $this->user('admin_sales'), true)->assertOk();

        $this->assertSame('Free Kanopi', collect($r->json('baris'))->firstWhere('label', 'Promo')['baru']);
        $this->assertFalse($r->json('langsung'));
        $this->assertNull($this->trx->fresh()->promo_preset_id);
        $this->assertSame(0, CancellationRequest::count());

        $this->actingAs($this->user('admin_sales'))->get(route('konsumens.show', $this->trx->konsumen))->assertInertia(fn ($page) => $page->where('transaksis.0.can_ubah_rincian', true)->where('transaksis.0.rincian_langsung', false));
        $this->actingAs($this->user('spv'))->get(route('konsumens.show', $this->trx->konsumen))->assertInertia(fn ($page) => $page->where('transaksis.0.can_ubah_rincian', true)->where('transaksis.0.rincian_langsung', true));
        $this->actingAs($this->user('admin_pemberkasan'))->get(route('konsumens.show', $this->trx->konsumen))->assertInertia(fn ($page) => $page->where('transaksis.0.can_ubah_rincian', false));
    }
}
