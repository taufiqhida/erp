<?php
// Dampak gerbang BAST baru: transaksi yang SUDAH Akad (belum BAST) tetapi masih punya tagihan konsumen belum lunas akan tertahan
// saat konfirmasi BAST (kecuali override SPV/Leader/Superadmin dengan alasan). Juga memeriksa transaksi cash yang jumlah cicilan
// Pelunasannya tidak sama dengan Pelunasan seharusnya (sisa jadwal lama yang belum dirapikan perintah pelunasan:pisah-komponen).
// Hanya membaca (SELECT):  docker compose exec -T app php artisan tinker --execute="$(cat /root/dampak_bast.php)"

$rp = fn ($v) => number_format((float) $v, 0, '', '');
$ringkas = ['akad_belum_bast' => 0, 'akan_tertahan' => 0, 'cash_selisih_pelunasan' => 0];

App\Models\KavlingKonsumen::query()->with(['kavling', 'konsumen', 'jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
    ->where('status', 'active')->where('status_penjualan', '!=', 'batal')->orderBy('id')
    ->chunkById(100, function ($semua) use ($rp, &$ringkas) {
        foreach ($semua as $kk) {
            $nomor = $kk->kavling->blok . '-' . $kk->kavling->nomor_kavling;
            $b = $kk->kartuPiutangBreakdown();

            foreach ($b['kartu_piutang_static'] as $r) {
                if (($r['type'] ?? '') === 'pelunasan_group' && abs((float) ($r['selisih_jadwal'] ?? 0)) > 0.5) {
                    $ringkas['cash_selisih_pelunasan']++;
                    echo 'SELISIH|' . $kk->id . '|' . $nomor . '|' . $kk->konsumen?->nama . '|' . $kk->cara_bayar . '|jumlah_cicilan=' . $rp($r['nominal'])
                        . '|seharusnya=' . $rp($r['nominal_seharusnya']) . '|selisih=' . $rp($r['selisih_jadwal']) . "\n";
                }
            }

            if ($kk->status_penjualan !== 'akad') continue;
            $ringkas['akad_belum_bast']++;
            $belum = $kk->komponenKonsumenBelumLunas($b);
            if (!$belum) continue;
            $ringkas['akan_tertahan']++;
            echo 'TAHAN|' . $kk->id . '|' . $nomor . '|' . $kk->konsumen?->nama . '|' . $kk->cara_bayar . '|'
                . collect($belum)->map(fn ($x) => $x['label'] . '=' . $rp($x['sisa']))->implode(';') . "\n";
        }
    });

echo 'RINGKAS|' . collect($ringkas)->map(fn ($v, $k) => $k . '=' . $v)->implode('|') . "\n";
echo "SELESAI\n";
