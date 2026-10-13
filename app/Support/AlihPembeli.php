<?php

namespace App\Support;

use App\Enums\CancellationStatus;
use App\Models\AlihPembeliRiwayat;
use App\Models\CancellationRequest;
use App\Models\CatatanTransaksi;
use App\Models\DokumenKonsumen;
use App\Models\DokumenTemplate;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\User;

/**
 * Alih Pembeli: mengganti pembeli sebuah transaksi tanpa membatalkannya. Transaksi, UTJ, kartu piutang, pembayaran, dan jadwal
 * tagihan TETAP; yang berganti hanya pembeli. Data yang melekat pada orangnya (berkas pemberkasan, hasil bank/SLIK, SP3K, rencana
 * akad) tidak boleh terbawa ke pembeli baru: berkas lama diarsipkan, hasil bank/SP3K dikosongkan, tahap kembali ke Pemberkasan.
 */
class AlihPembeli
{
    /** Tahap yang masih boleh dialihkan: sebelum Akad. */
    public const TAHAP_BOLEH = ['pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad'];

    /** Kolom transaksi yang menyimpan hasil proses pembeli lama — dikosongkan saat dialihkan. */
    private const KOLOM_DIRESET = [
        'status_bank', 'tanggal_pengajuan_bank', 'tanggal_keputusan_bank', 'catatan_bank',
        'status_sp3k', 'tanggal_sp3k', 'tanggal_expired_sp3k', 'catatan_sp3k',
        'tanggal_rencana_akad',
    ];

    /** Pesan alasan transaksi tidak bisa dialihkan, atau null bila boleh. */
    public static function alasanTidakBoleh(KavlingKonsumen $kk): ?string
    {
        if ($kk->status !== 'active' || $kk->status_penjualan === 'batal') return 'Transaksi ini tidak aktif.';
        if (!in_array($kk->status_penjualan, self::TAHAP_BOLEH, true)) return 'Pembeli hanya bisa dialihkan sebelum Akad.';

        return null;
    }

    /** Laksanakan pengajuan yang disetujui. Dipanggil di dalam transaksi database. */
    public static function laksanakan(CancellationRequest $pengajuan, User $penyetuju): AlihPembeliRiwayat
    {
        $kk = KavlingKonsumen::whereKey($pengajuan->kavling_konsumen_id)->lockForUpdate()->firstOrFail();
        abort_if(self::alasanTidakBoleh($kk) !== null, 422, self::alasanTidakBoleh($kk));

        $lama = Konsumen::withTrashed()->findOrFail($kk->konsumen_id);
        $baru = Konsumen::findOrFail($pengajuan->konsumen_baru_id);
        abort_if($lama->id === $baru->id, 422, 'Pembeli baru sama dengan pembeli saat ini.');
        $tahapSebelum = $kk->status_penjualan;

        $riwayat = AlihPembeliRiwayat::create([
            'kavling_konsumen_id' => $kk->id, 'dari_konsumen_id' => $lama->id, 'ke_konsumen_id' => $baru->id,
            'hubungan' => $pengajuan->hubungan, 'alasan' => $pengajuan->alasan, 'tahap_sebelum' => $tahapSebelum,
            'cancellation_request_id' => $pengajuan->id, 'diajukan_oleh' => $pengajuan->requested_by,
            'disetujui_oleh' => $penyetuju->id, 'disetujui_at' => now(),
        ]);

        // Berkas pembeli lama diarsipkan (tetap ada di database, tidak tampil dan tidak dihitung).
        DokumenKonsumen::where('kavling_konsumen_id', $kk->id)->whereNull('diarsipkan_at')
            ->update(['diarsipkan_at' => now(), 'diarsipkan_untuk_konsumen_id' => $lama->id]);

        // Pembeli diganti; hasil proses milik pembeli lama dikosongkan; pemberkasan dimulai dari awal untuk pembeli baru.
        $kk->update(array_fill_keys(self::KOLOM_DIRESET, null) + ['konsumen_id' => $baru->id, 'status_penjualan' => 'pemberkasan']);
        $kk->unsetRelation('dokumens');

        foreach (DokumenTemplate::where('cara_bayar', $kk->cara_bayar)->orderBy('urutan')->orderBy('id')->get() as $template) {
            DokumenKonsumen::create(['kavling_konsumen_id' => $kk->id] + DokumenKonsumen::atributDariTemplate($template, $baru));
        }

        $hubungan = AlihPembeliRiwayat::hubunganLabel()[$pengajuan->hubungan] ?? $pengajuan->hubungan;
        CatatanTransaksi::tulis($kk, 'pemberkasan',
            "Pembeli dialihkan dari {$lama->nama} ke {$baru->nama}" . ($hubungan ? " ({$hubungan})" : '') . ". Alasan: {$pengajuan->alasan}. Tahap sebelumnya: {$tahapSebelum}; transaksi kembali ke Pemberkasan.",
            'Alih pembeli', $penyetuju);

        $pengajuan->update(['status' => CancellationStatus::Approved, 'reviewed_by' => $penyetuju->id, 'reviewed_at' => now()]);

        return $riwayat;
    }

    /** Riwayat peralihan satu transaksi (terbaru terakhir) untuk banner di halaman transaksi. */
    public static function riwayatTransaksi(KavlingKonsumen $kk): array
    {
        return AlihPembeliRiwayat::with(['dari', 'ke', 'pengaju', 'penyetuju', 'transaksi.kavling.project'])
            ->where('kavling_konsumen_id', $kk->id)->orderBy('id')->get()->map->untukTampil()->all();
    }

    /** Riwayat yang menyangkut satu konsumen, baik sebagai pembeli lama maupun baru. */
    public static function riwayatKonsumen(Konsumen $konsumen): array
    {
        return AlihPembeliRiwayat::with(['dari', 'ke', 'pengaju', 'penyetuju', 'transaksi.kavling.project'])
            ->where(fn ($q) => $q->where('dari_konsumen_id', $konsumen->id)->orWhere('ke_konsumen_id', $konsumen->id))
            ->orderByDesc('id')->get()->map->untukTampil()->all();
    }
}
