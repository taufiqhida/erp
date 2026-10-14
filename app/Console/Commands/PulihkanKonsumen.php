<?php

namespace App\Console\Commands;

use App\Models\Konsumen;
use Illuminate\Console\Command;

/**
 * Memulihkan konsumen yang terhapus padahal masih punya transaksi (mis. admin menekan ikon sampah mengira itu membatalkan booking).
 * Konsumen yang terhapus sengaja dan tidak punya transaksi (calon pembeli) tidak disentuh. Aman dijalankan berulang.
 */
class PulihkanKonsumen extends Command
{
    protected $signature = 'konsumens:pulihkan
        {--pulihkan : Benar-benar memulihkan. Tanpa opsi ini hanya menampilkan daftar konsumen yang akan dipulihkan}';

    protected $description = 'Tampilkan/pulihkan konsumen yang terhapus tetapi masih punya transaksi atau riwayat peralihan.';

    public function handle(): int
    {
        $daftar = Konsumen::onlyTrashed()
            ->where(fn ($q) => $q->whereHas('kavlingKonsumens')->orWhereHas('riwayatDialihKeluar')->orWhereHas('riwayatDialihMasuk'))
            ->withCount('kavlingKonsumens')
            ->orderBy('nama')->get();

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada konsumen terhapus yang masih punya transaksi. Tidak ada yang perlu dipulihkan.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Nama', 'NIK', 'No. HP', 'Transaksi'], $daftar->map(fn ($k) => [$k->id, $k->nama, $k->nik ?? '-', $k->no_hp ?? '-', $k->kavling_konsumens_count])->all());

        if (!$this->option('pulihkan')) {
            $this->warn($daftar->count() . ' konsumen di atas terhapus tetapi masih punya transaksi. Jalankan dengan --pulihkan untuk memulihkannya.');

            return self::SUCCESS;
        }

        foreach ($daftar as $k) $k->restore();
        $this->info($daftar->count() . ' konsumen dipulihkan.');

        return self::SUCCESS;
    }
}
