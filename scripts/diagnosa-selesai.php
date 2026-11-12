<?php
// Diagnosis transaksi berstatus Selesai (completed) yang mungkin belum benar-benar lunas. Hanya membaca (SELECT).
// Semua transaksi Selesai:   docker compose exec -T app php artisan tinker --execute="$(cat /root/diagnosa.php)"
// Satu unit (rinci):         docker compose exec -T -e DIAG_UNIT=B7-13 app php artisan tinker --execute="$(cat /root/diagnosa.php)"
// Status Selesai diberikan bila TOTAL terbayar >= TOTAL piutang (konsumen dan bank) — perbandingan agregat, bukan per komponen.
// Skrip ini membuka rinciannya: komponen mana yang belum terbayar atau terbayar berlebih, dan kondisi sisi bank untuk KPR.

$unit = getenv('DIAG_UNIT') ?: null;
$rp = fn ($v) => number_format((float) $v, 0, '', '');
$ringkas = ['selesai' => 0, 'curiga' => 0, 'belum_lunas_per_komponen' => 0, 'terbayar_berlebih' => 0, 'bank_belum' => 0];

App\Models\KavlingKonsumen::query()->with(['kavling', 'konsumen', 'jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
    ->where('status', 'completed')->orderBy('id')
    ->chunkById(100, function ($semua) use ($unit, $rp, &$ringkas) {
        foreach ($semua as $kk) {
            $nomor = $kk->kavling->blok . '-' . $kk->kavling->nomor_kavling;
            $ringkas['selesai']++;
            $b = $kk->kartuPiutangBreakdown();
            $pk = $b['pencairan_kpr'] ?? null;

            $baris = [];
            $flag = ['belum' => false, 'lebih' => false, 'bank' => false];
            foreach ($b['kartu_piutang_static'] as $r) {
                if (!array_key_exists('jumlah_dibayar', $r)) continue;
                $selisih = (float) $r['nominal'] - (float) $r['jumlah_dibayar'];
                $tanda = $selisih > 0.5 ? 'BELUM' : ($selisih < -0.5 ? 'LEBIH' : '');
                if ($tanda === 'BELUM') $flag['belum'] = true;
                if ($tanda === 'LEBIH') $flag['lebih'] = true;
                $baris[] = 'ROW|' . $nomor . '|' . $r['nama'] . '|nominal=' . $rp($r['nominal']) . '|dibayar=' . $rp($r['jumlah_dibayar']) . '|' . ($r['status'] ?? '') . ($tanda ? '|' . $tanda : '');
            }
            if ($pk) {
                $bankBelum = $pk['pencairan_status'] !== 'lunas' || $pk['belum_dirinci'] > 0.5 || $pk['belum_cair'] > 0.5;
                if ($bankBelum) $flag['bank'] = true;
                $baris[] = 'KPR|' . $nomor . '|plafon_hitung=' . $rp($pk['plafon_hitung']) . '|plafon_disetujui=' . $rp($pk['plafon_disetujui']) . '|cair_tercatat=' . $rp($pk['pencairan_tercatat'])
                    . '|pencairan_nominal=' . $rp($pk['pencairan_nominal']) . '|retensi=' . $rp($pk['retensi']) . '|dajam_dirinci=' . $rp($pk['dajam_dirinci']) . '|dajam_cair=' . $rp($pk['dajam_cair'])
                    . '|belum_dirinci=' . $rp($pk['belum_dirinci']) . '|belum_cair=' . $rp($pk['belum_cair']) . '|status=' . $pk['pencairan_status'] . ($bankBelum ? '|BANK_BELUM' : '');
            }

            $curiga = $flag['belum'] || $flag['lebih'] || $flag['bank'];
            if ($curiga) $ringkas['curiga']++;
            if ($flag['belum']) $ringkas['belum_lunas_per_komponen']++;
            if ($flag['lebih']) $ringkas['terbayar_berlebih']++;
            if ($flag['bank']) $ringkas['bank_belum']++;

            if ($unit ? $nomor === $unit : $curiga) {
                echo 'TRX|' . $kk->id . '|' . $nomor . '|' . $kk->konsumen?->nama . '|' . $kk->cara_bayar . '|' . $kk->status_penjualan
                    . '|piutang_konsumen=' . $rp($b['total_piutang_konsumen']) . '|terbayar_konsumen=' . $rp($b['total_terbayar_konsumen'])
                    . '|piutang_bank=' . $rp($b['total_piutang_bank']) . '|terbayar_bank=' . $rp($b['total_terbayar_bank']) . "\n";
                foreach ($baris as $l) echo $l . "\n";
            }
        }
    });

echo 'RINGKAS|' . collect($ringkas)->map(fn ($v, $k) => $k . '=' . $v)->implode('|') . "\n";
echo "SELESAI\n";
