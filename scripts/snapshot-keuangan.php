<?php
// Snapshot keuangan + promo, dijalankan lewat: php artisan tinker --execute="$(cat scripts/snapshot-keuangan.php)"
// Dibuat SAMA untuk sebelum dan sesudah deploy. Hanya membaca (SELECT), tidak mengubah data, dan hanya memakai kolom yang sudah
// ada sebelum Rilis 1, jadi aman dijalankan di kode lama. Bandingkan dua keluaran dengan `diff`.
// Baris "T" = satu transaksi; "TOTAL" = jumlah seluruhnya; "PROMO" = sebaran promo lama; "UNIT" = unit terjual/dipesan tanpa kartu pembeli.

$kolom = ['status', 'cara_bayar', 'harga_dasar', 'harga_deal', 'diskon_nominal', 'biaya_kelebihan_tanah_nominal', 'biaya_kelebihan_tanah_potongan',
    'total_biaya_tambahan', 'booking_fee', 'titipan_biaya_akad_nominal', 'promo_preset_id',
    'fin_piutang_konsumen', 'fin_terbayar_konsumen', 'fin_sisa_konsumen', 'fin_piutang_bank', 'fin_terbayar_bank', 'fin_sisa_bank'];
$angka = fn ($v) => number_format((float) $v, 2, '.', '');

$total = array_fill_keys(['harga_deal', 'diskon_nominal', 'biaya_kelebihan_tanah_potongan', 'total_biaya_tambahan', 'bt_nominal', 'bt_potongan',
    'fin_piutang_konsumen', 'fin_terbayar_konsumen', 'fin_sisa_konsumen', 'fin_piutang_bank', 'fin_terbayar_bank', 'fin_sisa_bank', 'kartu_piutang_konsumen', 'kartu_piutang_bank'], 0.0);

$bt = DB::table('kavling_konsumen_biaya_tambahan')->selectRaw('kavling_konsumen_id, SUM(nominal) n, SUM(potongan) p')->groupBy('kavling_konsumen_id')->get()->keyBy('kavling_konsumen_id');

App\Models\KavlingKonsumen::query()
    ->with(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
    ->orderBy('id')
    ->chunkById(100, function ($semua) use ($kolom, $angka, $bt, &$total) {
        foreach ($semua as $kk) {
            $b = $kk->kartuPiutangBreakdown();
            $baris = ['T', $kk->id];
            foreach ($kolom as $k) {
                $baris[] = is_numeric($kk->$k) ? $angka($kk->$k) : (string) $kk->$k;
            }
            $baris[] = $angka($bt[$kk->id]->n ?? 0);
            $baris[] = $angka($bt[$kk->id]->p ?? 0);
            $baris[] = $angka($b['total_piutang_konsumen']);
            $baris[] = $angka($b['total_piutang_bank']);
            echo implode('|', $baris) . "\n";

            foreach (['harga_deal', 'diskon_nominal', 'biaya_kelebihan_tanah_potongan', 'total_biaya_tambahan', 'fin_piutang_konsumen', 'fin_terbayar_konsumen',
                'fin_sisa_konsumen', 'fin_piutang_bank', 'fin_terbayar_bank', 'fin_sisa_bank'] as $k) {
                $total[$k] += (float) $kk->$k;
            }
            $total['bt_nominal'] += (float) ($bt[$kk->id]->n ?? 0);
            $total['bt_potongan'] += (float) ($bt[$kk->id]->p ?? 0);
            $total['kartu_piutang_konsumen'] += (float) $b['total_piutang_konsumen'];
            $total['kartu_piutang_bank'] += (float) $b['total_piutang_bank'];
        }
    });

echo 'TOTAL|' . collect($total)->map(fn ($v, $k) => $k . '=' . $angka($v))->implode('|') . "\n";

foreach (DB::table('kavling_konsumen')->selectRaw('promo_preset_id, COUNT(*) n, SUM(diskon_nominal > 0) diskon, SUM(biaya_kelebihan_tanah_potongan > 0) tanah')
    ->groupBy('promo_preset_id')->orderBy('promo_preset_id')->get() as $r) {
    echo 'PROMO|' . ($r->promo_preset_id ?? 'tanpa') . '|transaksi=' . $r->n . '|ada_diskon=' . $r->diskon . '|ada_potongan_tanah=' . $r->tanah . "\n";
}

// Unit berstatus Dipesan/Terjual yang tidak punya kartu pembeli di siteplan (relasi yang sama dengan yang dipakai siteplan).
$tanpaKartu = App\Models\Kavling::with('activeTransaction')->whereIn('status_jual', ['booked', 'sold'])->get()->filter(fn ($k) => !$k->activeTransaction);
echo 'UNIT_TANPA_KARTU|jumlah=' . $tanpaKartu->count() . "\n";
foreach ($tanpaKartu->sortBy('id') as $k) {
    echo 'UNIT|' . $k->id . '|' . $k->blok . '-' . $k->nomor_kavling . '|' . $k->status_jual->value . "\n";
}
echo "SELESAI\n";
