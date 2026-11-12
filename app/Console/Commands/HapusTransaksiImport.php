<?php

namespace App\Console\Commands;

use App\Enums\StatusJual;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Support\FinanceCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan transaksi hasil IMPORT yang salah masuk (mis. insiden 9 Nov: transaksi Depi menempel ke konsumen Dicky karena NIK sama).
 * Penerus resmi skrip sekali pakai. Tanpa --terapkan hanya pratinjau. Menolak (semua atau tidak sama sekali) bila ada jejak operasi
 * nyata atau uang yang dicatat di luar impor, karena menghapusnya berarti menghilangkan riwayat sungguhan.
 */
class HapusTransaksiImport extends Command
{
    protected $signature = 'transaksi:hapus-import {--id=* : ID transaksi yang dihapus (boleh berulang)} {--terapkan : Hapus (tanpa opsi ini hanya pratinjau)} {--alasan= : Alasan (wajib saat --terapkan)} {--hapus-konsumen : Hapus juga konsumennya bila tidak punya transaksi atau riwayat lain}';

    protected $description = 'Pratinjau/hapus transaksi hasil import yang salah masuk (pratinjau dulu, --terapkan --id=... --alasan=...)';

    /** Jejak operasi nyata: bila ada, transaksi tidak boleh dihapus lewat perintah ini. */
    private const PENAHAN = ['cancellation_requests', 'alih_pembeli_riwayats', 'bast_records', 'unit_swap_histories'];

    /** Tabel anak yang ikut terhapus (CASCADE) dan ditampilkan di pratinjau. */
    private const ANAK = ['jadwal_tagihan', 'pembayaran_konsumens', 'kavling_konsumen_biaya_tambahan', 'kavling_konsumen_dajam_sbum', 'pencairan_kpr_tahap', 'dokumen_konsumens', 'catatan_transaksis'];

    private const KETERANGAN_IMPOR = 'Saldo Awal (Import)';

    public function handle(): int
    {
        $ids = array_values(array_unique(array_map('intval', array_filter((array) $this->option('id')))));
        $terapkan = (bool) $this->option('terapkan');
        if (!$ids) {
            $this->error('Sebutkan --id=<id transaksi> (boleh berulang).');
            return self::FAILURE;
        }
        if ($terapkan && !filled($this->option('alasan'))) {
            $this->error('--alasan wajib diisi saat --terapkan.');
            return self::FAILURE;
        }

        $this->info($terapkan ? 'MENERAPKAN' : 'PRATINJAU (belum ada yang dihapus)');
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
        $rencana = [];
        $ditolak = 0;

        foreach ($ids as $id) {
            $trx = KavlingKonsumen::with(['konsumen' => fn ($q) => $q->withTrashed(), 'kavling'])->find($id);
            if (!$trx) {
                $this->error("  #{$id}: tidak ditemukan (mungkin sudah dihapus).");
                $ditolak++;
                continue;
            }

            $alasanTolak = $this->alasanTolak($trx);
            $this->line("  Transaksi #{$trx->id}: {$trx->kavling->blok}-{$trx->kavling->nomor_kavling} · {$trx->konsumen?->nama} (NIK " . ($trx->konsumen?->nik ?? '-') . ") · {$trx->cara_bayar} · {$trx->status_penjualan} · status {$trx->status} · dibuat {$trx->created_at}");
            foreach (self::ANAK as $tabel) {
                $this->line(sprintf('    %-34s %d baris', $tabel, DB::table($tabel)->where('kavling_konsumen_id', $id)->count()));
            }
            $this->line('    total pembayaran tercatat         ' . $rp(DB::table('pembayaran_konsumens')->where('kavling_konsumen_id', $id)->sum('jumlah')));

            $lainAktif = KavlingKonsumen::where('kavling_id', $trx->kavling_id)->where('id', '!=', $id)->where('status', '!=', 'cancelled')->count();
            $this->line('    unit: ' . ($lainAktif ? "ada {$lainAktif} transaksi aktif lain, status unit TIDAK diubah" : 'status unit akan dikembalikan ke Tersedia'));

            $lainKonsumen = KavlingKonsumen::where('konsumen_id', $trx->konsumen_id)->where('id', '!=', $id)->count();
            $adaRiwayat = $this->konsumenPunyaRiwayat($trx->konsumen_id);
            $bolehHapusKonsumen = !$lainKonsumen && !$adaRiwayat && $trx->konsumen;
            $this->line('    konsumen: ' . ($bolehHapusKonsumen
                ? ($this->option('hapus-konsumen') ? 'AKAN dihapus (tidak punya transaksi atau riwayat lain)' : 'tidak punya transaksi lain; ikut dihapus hanya dengan --hapus-konsumen')
                : "TIDAK dihapus (masih punya {$lainKonsumen} transaksi lain atau riwayat terkait)"));

            if ($alasanTolak) {
                $this->error("    DITOLAK — {$alasanTolak}");
                $ditolak++;
                continue;
            }
            $rencana[] = ['trx' => $trx, 'lainAktif' => $lainAktif, 'hapusKonsumen' => $bolehHapusKonsumen && $this->option('hapus-konsumen')];
        }

        if ($ditolak) {
            $this->error("{$ditolak} transaksi tidak bisa diproses, jadi TIDAK ADA yang dihapus (semua atau tidak sama sekali).");
            return self::FAILURE;
        }
        if (!$terapkan) {
            $this->line('Untuk menghapus: ulangi dengan --terapkan --alasan="..." (tambahkan --hapus-konsumen bila konsumennya juga salah masuk).');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($rencana) {
            foreach ($rencana as $r) {
                $trx = $r['trx'];
                $kavling = $trx->kavling;
                activity('koreksi_import')->performedOn($kavling)->withProperties([
                    'transaksi_id' => $trx->id, 'konsumen_id' => $trx->konsumen_id, 'status_penjualan' => $trx->status_penjualan, 'alasan' => $this->option('alasan'),
                ])->log("Transaksi {$trx->id} hasil import dihapus lewat perintah. Alasan: {$this->option('alasan')}");

                $konsumenId = $trx->konsumen_id;
                $trx->delete();
                if (!$r['lainAktif']) $kavling->update(['status_jual' => StatusJual::Available]);
                if ($r['hapusKonsumen']) Konsumen::withTrashed()->whereKey($konsumenId)->forceDelete();   // NIK unik: yang terhapus lunak masih menghalangi import ulang
            }
        });
        FinanceCache::bump();

        $this->info(count($rencana) . ' transaksi dihapus dan dicatat di log (koreksi_import).');
        return self::SUCCESS;
    }

    /** Alasan transaksi TIDAK boleh dihapus, atau null bila aman. */
    private function alasanTolak(KavlingKonsumen $trx): ?string
    {
        foreach (self::PENAHAN as $tabel) {
            if (DB::table($tabel)->where('kavling_konsumen_id', $trx->id)->exists()) {
                return "punya data di {$tabel} (jejak operasi nyata: pembatalan, alih pembeli, BAST, atau tukar unit). Periksa manual.";
            }
        }
        $manual = DB::table('pembayaran_konsumens')->where('kavling_konsumen_id', $trx->id)->where(fn ($q) => $q->whereNull('keterangan')->orWhere('keterangan', 'not like', self::KETERANGAN_IMPOR . '%'))->count()
            + DB::table('pencairan_kpr_tahap')->where('kavling_konsumen_id', $trx->id)->where(fn ($q) => $q->whereNull('keterangan')->orWhere('keterangan', 'not like', self::KETERANGAN_IMPOR . '%'))->count();
        if ($manual > 0) {
            return "ada {$manual} pembayaran/pencairan yang dicatat di luar impor (bukan \"" . self::KETERANGAN_IMPOR . '"). Menghapusnya menghilangkan catatan uang sungguhan.';
        }

        return null;
    }

    private function konsumenPunyaRiwayat(?int $konsumenId): bool
    {
        if (!$konsumenId) return false;

        return DB::table('alih_pembeli_riwayats')->where(fn ($q) => $q->where('dari_konsumen_id', $konsumenId)->orWhere('ke_konsumen_id', $konsumenId))->exists()
            || DB::table('cancellation_requests')->where(fn ($q) => $q->where('konsumen_id', $konsumenId)->orWhere('konsumen_baru_id', $konsumenId))->exists();
    }
}
