<?php

namespace App\Support;

use App\Models\CatatanTransaksi;
use App\Models\KavlingKonsumen;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi Tanggal Booking (salah input tanggal). Tanggal booking menjadi dasar jatuh tempo UTJ/DP dan statistik Dashboard, jadi
 * mengubahnya juga menggeser yang turunan darinya: pembayaran UTJ bawaan booking dan jatuh tempo cicilan yang BELUM dibayar ikut
 * bergeser sebesar selisih hari; cicilan yang sudah ada pembayarannya tetap terkunci (kecuali UTJ bawaan booking, yang memang
 * dicatat otomatis di tanggal booking).
 */
class KoreksiTanggalBooking
{
    /** Keterangan pembayaran UTJ yang dibuat otomatis saat booking (lihat BookingController::store). */
    public const KETERANGAN_UTJ_OTOMATIS = 'Booking fee otomatis dibayar saat booking';

    private const TAHAP_BOLEH = ['booking', 'pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad'];

    /** Bolehkah pengguna ini mengoreksi tanggal booking transaksi ini? null = boleh, selain itu pesan alasannya. */
    public static function alasanTidakBoleh(KavlingKonsumen $kk, ?User $user): ?string
    {
        if (!$user || !($user->can('kelola pipeline sales') || $user->can('override transaction lock'))) return 'Anda tidak berwenang mengoreksi tanggal booking.';
        if ($kk->status === 'cancelled' || $kk->status_penjualan === 'batal') return 'Transaksi ini sudah dibatalkan.';
        $override = $user->can('override transaction lock');
        if ($kk->is_locked && !$override) return 'Transaksi sudah Selesai dan terkunci. Hanya pemegang wewenang override yang bisa mengoreksi.';
        if (!in_array($kk->status_penjualan, self::TAHAP_BOLEH, true) && !$override) return 'Tanggal booking hanya bisa dikoreksi sebelum Akad (setelahnya perlu SPV/Leader).';

        return null;
    }

    /** Rencana perubahan (tanpa menyimpan): ringkasan + id baris yang akan digeser. */
    public static function rencana(KavlingKonsumen $kk, Carbon $baru): array
    {
        $lama = $kk->tanggal_booking->copy()->startOfDay();
        $selisih = (int) $lama->diffInDays($baru->copy()->startOfDay(), false);

        $utjOtomatis = $kk->pembayarans()->where('jenis', 'booking_fee')->where('keterangan', self::KETERANGAN_UTJ_OTOMATIS)
            ->whereDate('tanggal_bayar', $lama->toDateString())->get();
        $idUtj = $utjOtomatis->pluck('id')->all();

        $digeser = [];
        $terkunci = 0;
        foreach ($kk->jadwalTagihans()->get() as $j) {
            if ($j->status === 'belum_bayar' || in_array($j->pembayaran_konsumen_id, $idUtj, true)) $digeser[] = $j->id;
            else $terkunci++;
        }

        return ['lama' => $lama, 'baru' => $baru->copy()->startOfDay(), 'selisih_hari' => $selisih, 'utj_ids' => $idUtj, 'jadwal_ids' => $digeser, 'terkunci' => $terkunci];
    }

    public static function ringkas(array $r): array
    {
        return [
            'tanggal_lama' => $r['lama']->format('Y-m-d'), 'tanggal_baru' => $r['baru']->format('Y-m-d'), 'selisih_hari' => $r['selisih_hari'],
            'utj_ikut' => count($r['utj_ids']) > 0, 'cicilan_digeser' => count($r['jadwal_ids']), 'cicilan_terkunci' => $r['terkunci'],
        ];
    }

    public static function terapkan(KavlingKonsumen $kk, Carbon $baru, string $alasan, User $pengguna): array
    {
        return DB::transaction(function () use ($kk, $baru, $alasan, $pengguna) {
            $r = self::rencana($kk, $baru);

            if ($r['utj_ids']) {
                $kk->pembayarans()->whereIn('id', $r['utj_ids'])->update(['tanggal_bayar' => $r['baru']->toDateString()]);
            }
            foreach ($kk->jadwalTagihans()->whereIn('id', $r['jadwal_ids'])->get() as $j) {
                $j->update(['tanggal_jatuh_tempo' => $j->tanggal_jatuh_tempo->copy()->addDays($r['selisih_hari'])]);
            }
            $kk->update(['tanggal_booking' => $r['baru']->toDateString()]);

            $ringkas = self::ringkas($r);
            activity('transaksi')->causedBy($pengguna)->performedOn($kk)->withProperties($ringkas + ['alasan' => $alasan])
                ->log("Tanggal booking dikoreksi {$ringkas['tanggal_lama']} → {$ringkas['tanggal_baru']} ({$ringkas['cicilan_digeser']} jadwal cicilan digeser)");
            CatatanTransaksi::tulis($kk, 'booking',
                "Tanggal booking dikoreksi dari {$r['lama']->format('d M Y')} ke {$r['baru']->format('d M Y')}. Alasan: {$alasan}. "
                . "{$ringkas['cicilan_digeser']} jadwal cicilan digeser " . ($r['selisih_hari'] >= 0 ? '+' : '') . "{$r['selisih_hari']} hari"
                . ($ringkas['utj_ikut'] ? '; tanggal bayar UTJ bawaan booking ikut diubah' : '') . '.', 'Koreksi tanggal booking', $pengguna);

            return $ringkas;
        });
    }
}
