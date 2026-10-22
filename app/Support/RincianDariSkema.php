<?php

namespace App\Support;

use App\Models\KavlingKonsumen;
use App\Models\SkemaDpPreset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Melengkapi transaksi BATAL hasil import (yang hanya punya harga deal, skema, dan satu pembayaran "Saldo Awal") dengan rincian yang bisa
 * dihitung dari Skema Pembayaran: harga dasar, booking fee, dan jadwal tagihan (booking fee, DP, serta pelunasan untuk cash). Plafon KPR
 * dan pencairan KPR dihitung sendiri oleh KavlingKonsumen::kartuPiutangBreakdown() dari harga dasar + skema, jadi ikut muncul.
 *
 * Aturan inti:
 *  - Jadwal hanya RENCANA (belum_bayar). Uang yang diterima tetap satu pembayaran dan TIDAK dibagi ke tagihan, supaya tidak ada hitungan ganda.
 *  - Biaya tambahan, diskon, biaya tanah, SBUM, dan Dajam dianggap tidak ada (tidak diketahui dari data import).
 *  - Aman diulang: transaksi yang sudah punya jadwal dilewati. Pembayaran dan pengajuan pembatalan tidak pernah disentuh.
 */
class RincianDariSkema
{
    /** Transaksi batal yang masih bisa dilengkapi (belum punya jadwal) — dipakai pratinjau massal dan tombol per transaksi. */
    public static function kandidat(?int $projectId = null): Builder
    {
        return KavlingKonsumen::query()
            ->where('status', 'cancelled')
            ->whereNotNull('skema_dp_preset_id')
            ->whereDoesntHave('jadwalTagihans')
            ->when($projectId, fn (Builder $q) => $q->whereHas('kavling', fn ($k) => $k->where('project_id', $projectId)));
    }

    /** Alasan transaksi ini TIDAK bisa dilengkapi, atau null kalau bisa. */
    public static function alasanTidakBisa(KavlingKonsumen $kk): ?string
    {
        if ($kk->status !== 'cancelled' && $kk->status_penjualan !== 'batal') return 'Bukan transaksi batal.';
        if (!$kk->skema_dp_preset_id) return 'Skema pembayaran belum dipilih.';
        if ($kk->jadwalTagihans()->exists()) return 'Sudah punya jadwal tagihan.';
        if (self::hargaDasar($kk) <= 0) return 'Harga dasar tidak diketahui (harga deal dan harga unit kosong).';

        return null;
    }

    /**
     * Hitung tanpa menyimpan. Mengembalikan harga dasar, booking fee, DP, pelunasan, dan jadwal yang akan dibuat
     * (['jenis','nomor_cicilan','jumlah','tanggal_jatuh_tempo']).
     */
    public static function hitung(KavlingKonsumen $kk): array
    {
        $skema = $kk->skemaDpPreset ?? SkemaDpPreset::find($kk->skema_dp_preset_id);
        $hargaDasar = self::hargaDasar($kk);
        $netto = (float) ($kk->harga_deal ?: $hargaDasar);

        $basis = fn (?string $b) => $b === 'harga_dasar' ? $hargaDasar : $netto;
        $nominal = function (?string $tipe, $nilai, ?string $b) use ($basis) {
            if (!$nilai) return 0.0;

            return $tipe === 'persen' ? round(((float) $nilai / 100) * $basis($b), 2) : (float) $nilai;
        };

        $bookingFee = $skema->booking_fee_aktif ? $nominal($skema->booking_fee_tipe, $skema->booking_fee_nilai, $skema->booking_fee_basis) : 0.0;
        $dp = $skema->dp_aktif ? $nominal($skema->dp_tipe, $skema->dp_nilai, $skema->dp_basis) : 0.0;

        $isKpr = in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil'], true);
        $pelunasan = 0.0;
        if (!$isKpr) {
            $bfPengurang = $skema->booking_fee_masuk_harga_jual ? $bookingFee : 0;
            $dpPengurang = $skema->dp_masuk_harga_jual ? $dp : 0;
            $pelunasan = max(0, $netto - $bfPengurang - $dpPengurang);
        }

        $tglBooking = $kk->tanggal_booking ?? now();
        $jadwal = [];
        $buat = function (string $jenis, float $total, int $tenor) use (&$jadwal, $tglBooking) {
            if ($total <= 0 || $tenor < 1) return;
            $per = round($total / $tenor, 2);
            $sisa = $total;
            for ($i = 0; $i < $tenor; $i++) {
                $jumlah = $i === $tenor - 1 ? $sisa : $per;
                $sisa -= $jumlah;
                $jadwal[] = ['jenis' => $jenis, 'nomor_cicilan' => $i + 1, 'jumlah' => $jumlah, 'tanggal_jatuh_tempo' => $tglBooking->copy()->addMonths($i)];
            }
        };
        $buat('booking_fee', $bookingFee, max(1, $skema->booking_fee_aktif ? (int) $skema->booking_fee_tenor : 1));
        $buat('dp', $dp, max(1, $skema->dp_aktif ? (int) $skema->dp_tenor : 1));
        if (!$isKpr) $buat('pelunasan', $pelunasan, max(1, (int) ($kk->cicilan_kali ?: 1)));

        return ['harga_dasar' => $hargaDasar, 'harga_deal' => $netto, 'booking_fee' => $bookingFee, 'dp' => $dp, 'pelunasan' => $pelunasan, 'jadwal' => $jadwal];
    }

    /** Terapkan ke satu transaksi. Mengembalikan jumlah baris jadwal yang dibuat, atau null kalau dilewati. */
    public static function terapkan(KavlingKonsumen $kk): ?int
    {
        if (self::alasanTidakBisa($kk)) return null;
        $h = self::hitung($kk);

        return KavlingKonsumen::withoutFinanceRefresh(fn () => DB::transaction(function () use ($kk, $h) {
            $kk->forceFill(array_filter([
                'harga_dasar' => $kk->harga_dasar ? null : $h['harga_dasar'],
                'harga_deal'  => $kk->harga_deal ? null : $h['harga_deal'],
                'booking_fee' => $h['booking_fee'],
            ], fn ($v) => $v !== null))->save();

            foreach ($h['jadwal'] as $baris) {
                $kk->jadwalTagihans()->create($baris + ['status' => 'belum_bayar']);
            }

            return count($h['jadwal']);
        }));
    }

    /** Pratinjau massal untuk satu proyek: berapa yang bisa dilengkapi, berapa dilewati beserta alasannya, dan contohnya. */
    public static function pratinjau(int $projectId): array
    {
        $batal = KavlingKonsumen::query()
            ->where('status', 'cancelled')
            ->whereHas('kavling', fn ($k) => $k->where('project_id', $projectId))
            ->with(['konsumen:id,nama', 'kavling:id,blok,nomor_kavling,kluster', 'skemaDpPreset'])
            ->get();

        $bisa = [];
        $lewati = [];
        foreach ($batal as $kk) {
            $alasan = self::alasanTidakBisa($kk);
            $unit = trim(($kk->kavling->kluster ? $kk->kavling->kluster . ' · ' : '') . $kk->kavling->blok . '-' . $kk->kavling->nomor_kavling);
            if ($alasan) {
                $lewati[] = ['id' => $kk->id, 'konsumen' => $kk->konsumen?->nama, 'unit' => $unit, 'alasan' => $alasan];
                continue;
            }
            $h = self::hitung($kk);
            $bisa[] = ['id' => $kk->id, 'konsumen' => $kk->konsumen?->nama, 'unit' => $unit, 'harga_dasar' => $h['harga_dasar'],
                'booking_fee' => $h['booking_fee'], 'dp' => $h['dp'], 'pelunasan' => $h['pelunasan'], 'jadwal' => count($h['jadwal'])];
        }

        return ['bisa' => $bisa, 'lewati' => $lewati];
    }

    private static function hargaDasar(KavlingKonsumen $kk): float
    {
        return (float) ($kk->harga_dasar ?: $kk->harga_deal ?: $kk->kavling?->harga ?: 0);
    }
}
