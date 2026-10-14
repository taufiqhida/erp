<?php

namespace Tests\Feature;

use App\Models\CatatanTransaksi;
use App\Models\JadwalTagihan;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\PembayaranKonsumen;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use App\Models\User;
use App\Support\KoreksiTanggalBooking;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Koreksi Tanggal Booking: menggeser turunan tanggal (UTJ bawaan, jatuh tempo cicilan belum dibayar) dan mengunci yang sudah dibayar. */
class KoreksiTanggalBookingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private KavlingKonsumen $trx;
    private PembayaranKonsumen $utj;
    /** @var JadwalTagihan[] */
    private array $j = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->project = Project::create(['nama' => 'Griya', 'kode' => 'G1', 'kota' => 'X', 'is_active' => true]);
        $tipe = TipeUnitPreset::create(['project_id' => $this->project->id, 'nama' => '36/72', 'is_active' => true]);
        $unit = Kavling::create(['project_id' => $this->project->id, 'tipe_unit_preset_id' => $tipe->id, 'blok' => 'A1', 'nomor_kavling' => '1',
            'status_jual' => 'booked', 'harga' => 300000000, 'status_bangun_stage_id' => StatusBangunStage::defaultStage()->id]);
        $konsumen = Konsumen::create(['nama' => 'Budi', 'no_hp' => '0812']);
        // Booking salah tercatat 10 Okt 2026; yang benar 5 Okt 2026 (mundur 5 hari).
        $this->trx = KavlingKonsumen::create(['kavling_id' => $unit->id, 'konsumen_id' => $konsumen->id, 'status' => 'active', 'status_penjualan' => 'pemberkasan',
            'cara_bayar' => 'cash_bertahap', 'tanggal_booking' => '2026-10-10']);
        $this->utj = PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => 'booking_fee', 'jumlah' => 5000000, 'tanggal_bayar' => '2026-10-10',
            'keterangan' => KoreksiTanggalBooking::KETERANGAN_UTJ_OTOMATIS]);
        $dpDibayar = PembayaranKonsumen::create(['kavling_konsumen_id' => $this->trx->id, 'jenis' => 'dp', 'jumlah' => 10000000, 'tanggal_bayar' => '2026-10-12', 'keterangan' => 'DP #1']);

        $this->j['utj'] = $this->jadwal('booking_fee', 1, '2026-10-10', 'lunas', $this->utj->id);
        $this->j['dp1'] = $this->jadwal('dp', 1, '2026-10-10', 'lunas', $dpDibayar->id);          // sudah dibayar (manual): terkunci
        $this->j['dp2'] = $this->jadwal('dp', 2, '2026-11-10', 'belum_bayar');
        $this->j['dp3'] = $this->jadwal('dp', 3, '2026-12-10', 'belum_bayar');
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

    private function koreksi(array $data, ?User $u = null, bool $json = false)
    {
        $req = $this->actingAs($u ?? $this->user('admin_sales'));

        return $json ? $req->patchJson(route('bookings.tanggal-booking', $this->trx), $data) : $req->patch(route('bookings.tanggal-booking', $this->trx), $data);
    }

    private function tgl(JadwalTagihan $j): string
    {
        return $j->fresh()->tanggal_jatuh_tempo->format('Y-m-d');
    }

    public function test_pratinjau_menghitung_dampak_tanpa_menyimpan(): void
    {
        $r = $this->koreksi(['tanggal_booking' => '2026-10-05', 'simulasi' => true], null, true)->assertOk();

        $r->assertJson(['tanggal_lama' => '2026-10-10', 'tanggal_baru' => '2026-10-05', 'selisih_hari' => -5, 'utj_ikut' => true, 'cicilan_digeser' => 3, 'cicilan_terkunci' => 1]);
        $this->assertSame('2026-10-10', $this->trx->fresh()->tanggal_booking->format('Y-m-d'));      // belum ada yang berubah
        $this->assertSame('2026-11-10', $this->tgl($this->j['dp2']));
    }

    public function test_koreksi_menggeser_utj_bawaan_dan_cicilan_belum_dibayar_tetapi_mengunci_yang_sudah_dibayar(): void
    {
        $this->koreksi(['tanggal_booking' => '2026-10-05', 'alasan' => 'Salah ketik tanggal saat input booking'])->assertSessionHas('success');

        $this->assertSame('2026-10-05', $this->trx->fresh()->tanggal_booking->format('Y-m-d'));
        $this->assertSame('2026-10-05', $this->utj->fresh()->tanggal_bayar->format('Y-m-d'));       // UTJ bawaan booking ikut
        $this->assertSame('2026-10-05', $this->tgl($this->j['utj']));                               // jatuh tempo baris UTJ ikut
        $this->assertSame('2026-11-05', $this->tgl($this->j['dp2']));                               // belum dibayar: digeser −5 hari
        $this->assertSame('2026-12-05', $this->tgl($this->j['dp3']));
        $this->assertSame('2026-10-10', $this->tgl($this->j['dp1']));                               // sudah dibayar manual: terkunci
        $this->assertSame('2026-10-12', PembayaranKonsumen::where('keterangan', 'DP #1')->firstOrFail()->tanggal_bayar->format('Y-m-d'));
    }

    public function test_koreksi_maju_menggeser_ke_depan_dan_jumlah_uang_tidak_berubah(): void
    {
        $this->koreksi(['tanggal_booking' => '2026-10-13', 'alasan' => 'Tanggal booking yang benar 13 Oktober'])->assertSessionHas('success');

        $this->assertSame('2026-11-13', $this->tgl($this->j['dp2']));
        $this->assertEquals(5000000, $this->utj->fresh()->jumlah);
        $this->assertSame(2, PembayaranKonsumen::count());
    }

    public function test_utj_yang_dicatat_manual_di_tanggal_lain_tidak_diubah(): void
    {
        $this->utj->update(['keterangan' => 'Diterima transfer', 'tanggal_bayar' => '2026-10-11']);

        $this->koreksi(['tanggal_booking' => '2026-10-05', 'alasan' => 'Salah ketik tanggal saat input booking'])->assertSessionHas('success');

        $this->assertSame('2026-10-11', $this->utj->fresh()->tanggal_bayar->format('Y-m-d'));
        $this->assertSame('2026-10-10', $this->tgl($this->j['utj']));                               // sudah dibayar manual: terkunci
    }

    public function test_tercatat_di_audit_trail_dan_catatan_transaksi(): void
    {
        $this->koreksi(['tanggal_booking' => '2026-10-05', 'alasan' => 'Salah ketik tanggal saat input booking'])->assertSessionHas('success');

        $this->assertTrue(Activity::where('log_name', 'transaksi')->where('description', 'like', 'Tanggal booking dikoreksi%')->exists());
        $c = CatatanTransaksi::where('tahap', 'Koreksi tanggal booking')->firstOrFail();
        $this->assertSame('booking', $c->jenis);
        $this->assertStringContainsString('Salah ketik tanggal saat input booking', $c->isi);
        $this->assertStringContainsString('3 jadwal cicilan digeser', $c->isi);
    }

    public function test_validasi_alasan_masa_depan_dan_tanggal_sama(): void
    {
        $this->koreksi(['tanggal_booking' => '2026-10-05'])->assertSessionHasErrors('alasan');
        $this->koreksi(['tanggal_booking' => '2026-10-05', 'alasan' => 'tdk'])->assertSessionHasErrors('alasan');
        $this->koreksi(['tanggal_booking' => now()->addDays(2)->format('Y-m-d'), 'alasan' => 'Alasan yang cukup panjang'])->assertSessionHasErrors('tanggal_booking');
        $this->koreksi(['tanggal_booking' => '2026-10-10', 'alasan' => 'Alasan yang cukup panjang'])->assertSessionHasErrors('tanggal_booking');
        $this->koreksi(['tanggal_booking' => '2026-10-05'], null, true)->assertStatus(422);        // JSON: pesan, bukan pengalihan

        $this->assertSame('2026-10-10', $this->trx->fresh()->tanggal_booking->format('Y-m-d'));
    }

    public function test_izin_tahap_dan_penguncian(): void
    {
        $data = ['tanggal_booking' => '2026-10-05', 'alasan' => 'Salah ketik tanggal saat input booking'];

        foreach (['admin_pemberkasan', 'admin_keuangan', 'pelaksana_lapangan'] as $peran) {
            $this->koreksi($data, $this->user($peran))->assertStatus(403);
        }
        $this->koreksi($data, $this->user('admin_sales', false))->assertStatus(403);                // bukan proyeknya

        // Setelah Akad: Admin Sales ditolak, SPV (override) boleh.
        $this->trx->update(['status_penjualan' => 'akad']);
        $this->koreksi($data)->assertStatus(403);
        $this->koreksi($data, $this->user('spv'))->assertSessionHas('success');

        // Transaksi Selesai (terkunci): Admin Sales tetap ditolak.
        $this->trx->update(['tanggal_booking' => '2026-10-10', 'status' => 'completed']);
        $this->koreksi($data)->assertStatus(403);
    }

    public function test_transaksi_batal_tidak_bisa_dikoreksi_dan_profil_membawa_izin_tombol(): void
    {
        $this->actingAs($this->user('admin_sales'))->get(route('konsumens.show', $this->trx->konsumen))->assertOk()
            ->assertInertia(fn ($page) => $page->where('transaksis.0.can_koreksi_tanggal_booking', true)->where('transaksis.0.tanggal_booking_raw', '2026-10-10'));
        $this->actingAs($this->user('admin_pemberkasan'))->get(route('konsumens.show', $this->trx->konsumen))
            ->assertInertia(fn ($page) => $page->where('transaksis.0.can_koreksi_tanggal_booking', false));

        $this->trx->update(['status' => 'cancelled', 'status_penjualan' => 'batal']);
        $this->koreksi(['tanggal_booking' => '2026-10-05', 'alasan' => 'Salah ketik tanggal saat input booking'], $this->user('spv'))->assertStatus(403);
    }
}
