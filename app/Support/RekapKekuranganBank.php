<?php

namespace App\Support;

use App\Models\KavlingKonsumen;
use Illuminate\Support\Collection;

/**
 * Bahan tab "Rekap Kekurangan Pencairan": per transaksi KPR yang sudah akad, status tiap komponen dana bank — item Dajam (per nama),
 * SBUM, Pencairan netto, dan retensi yang belum dirinci. Tiap komponen berupa "sel":
 *   nominal (tagihan), cair (yang sudah diterima), sisa (nominal - cair), status belum|sebagian|lunas, tanggal (terakhir cair).
 *
 * Dihitung di PHP per transaksi (kartuPiutangBreakdown), di-cache dan kedaluwarsa otomatis bersama data keuangan (FinanceCache).
 * Mode & kolom terpilih diterapkan di susun(), jadi isi sel, kolom Total, dan baris yang tampil selalu konsisten dengan yang terlihat.
 */
class RekapKekuranganBank
{
    public const MODE_BELUM = 'belum_cair';
    public const MODE_SUDAH = 'sudah_cair';
    public const MODE_SEMUA = 'semua';
    public const MODE = [self::MODE_BELUM, self::MODE_SUDAH, self::MODE_SEMUA];

    public const KOLOM_SBUM = 'sbum';
    public const KOLOM_PENCAIRAN = 'pencairan';
    public const KOLOM_BELUM_DIRINCI = 'belum_dirinci';
    private const PREFIX_ITEM = 'item:';

    /** Label kolom bukan-item Dajam. */
    public const LABEL_LAINNYA = [
        self::KOLOM_SBUM => 'SBUM',
        self::KOLOM_PENCAIRAN => 'Pencairan netto',
        self::KOLOM_BELUM_DIRINCI => 'Belum dirinci',
    ];

    /**
     * @return array{sel: array<int, array<string, array>>, label: array<string, string>}
     *   sel[id_transaksi][kunci_kolom] = sel; label[kunci_item] = nama item apa adanya (pertama yang ditemui).
     */
    public static function dataset(): array
    {
        return FinanceCache::remember('rekap-kekurangan-bank', function () {
            $sel = [];
            $label = [];
            KavlingKonsumen::query()
                ->whereIn('cara_bayar', ['kpr_subsidi', 'kpr_komersil'])
                ->where('status', '!=', 'cancelled')
                ->whereIn('status_penjualan', ['akad', 'bast'])
                ->with(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps'])
                ->chunkById(200, function ($semua) use (&$sel, &$label) {
                    foreach ($semua as $kk) {
                        $sel[$kk->id] = self::selTransaksi($kk, $label);
                    }
                });

            return ['sel' => $sel, 'label' => $label];
        });
    }

    /** @return array<string, array> kunci kolom => sel (hanya komponen yang ada pada transaksi ini) */
    private static function selTransaksi(KavlingKonsumen $kk, array &$label): array
    {
        $hasil = [];
        $items = $kk->rincianBiayaAkad;

        foreach ($items->where('kategori', 'dajam')->groupBy(fn ($i) => self::kunciItem($i->nama)) as $kunci => $kelompok) {
            if ($sel = self::buatSel($kelompok)) {
                $hasil[$kunci] = $sel;
                $label[$kunci] ??= trim((string) $kelompok->first()->nama);
            }
        }
        if ($sel = self::buatSel($items->where('kategori', 'sbum'))) {
            $hasil[self::KOLOM_SBUM] = $sel;
        }

        $pk = $kk->kartuPiutangBreakdown()['pencairan_kpr'];
        if ($pk) {
            $nominal = (float) $pk['pencairan_nominal'];
            if ($nominal > 0.5) {
                $cair = min((float) $pk['pencairan_tercatat'], $nominal);
                $tanggal = $kk->pencairanKprTahaps->map(fn ($t) => $t->tanggal_cair)->filter()->max();
                $hasil[self::KOLOM_PENCAIRAN] = self::sel($nominal, $cair, $tanggal?->format('d M Y'));
            }
            if ($pk['retensi_aktif'] && (float) $pk['belum_dirinci'] > 0.5) {
                $hasil[self::KOLOM_BELUM_DIRINCI] = self::sel((float) $pk['belum_dirinci'], 0.0, null);
            }
        }

        return $hasil;
    }

    private static function kunciItem(?string $nama): string
    {
        return self::PREFIX_ITEM . mb_strtolower(trim((string) $nama));
    }

    private static function buatSel(Collection $items): ?array
    {
        $nominal = (float) $items->sum('nominal');
        if ($items->isEmpty() || $nominal <= 0.5) return null;

        $cair = min($nominal, (float) $items->sum(fn ($i) => (float) ($i->pembayaran?->jumlah ?? 0)));
        $tanggal = $items->map(fn ($i) => $i->pembayaran?->tanggal_bayar)->filter()->max();

        return self::sel($nominal, $cair, $tanggal?->format('d M Y'));
    }

    private static function sel(float $nominal, float $cair, ?string $tanggal): array
    {
        $sisa = round(max(0, $nominal - $cair), 2);

        return [
            'nominal' => round($nominal, 2),
            'cair'    => round($cair, 2),
            'sisa'    => $sisa,
            'status'  => $sisa <= 0.5 ? 'lunas' : ($cair > 0 ? 'sebagian' : 'belum'),
            'tanggal' => $tanggal,
        ];
    }

    /**
     * Daftar kolom yang bisa dipilih untuk sekumpulan transaksi (checkbox): item Dajam yang benar-benar ada pada mereka, lalu kolom lainnya.
     *
     * @param  int[]  $ids
     * @return array<int, array{key: string, label: string, grup: string, jumlah: int}>
     */
    public static function kolomTersedia(array $ids): array
    {
        $data = self::dataset();
        $jumlah = [];
        foreach ($ids as $id) {
            foreach (array_keys($data['sel'][$id] ?? []) as $kunci) {
                $jumlah[$kunci] = ($jumlah[$kunci] ?? 0) + 1;
            }
        }

        $item = [];
        foreach ($jumlah as $kunci => $n) {
            if (str_starts_with($kunci, self::PREFIX_ITEM)) {
                $item[] = ['key' => $kunci, 'label' => $data['label'][$kunci] ?? substr($kunci, strlen(self::PREFIX_ITEM)), 'grup' => 'dajam', 'jumlah' => $n];
            }
        }
        usort($item, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));

        $lain = [];
        foreach (self::LABEL_LAINNYA as $kunci => $label) {
            $lain[] = ['key' => $kunci, 'label' => $label, 'grup' => 'lainnya', 'jumlah' => $jumlah[$kunci] ?? 0];
        }

        return [...$item, ...$lain];
    }

    /** Sel yang tampil menurut mode, atau null (kosong). */
    public static function tampilkan(?array $sel, string $mode, string $kunci): ?array
    {
        if (!$sel) return null;
        if ($kunci === self::KOLOM_BELUM_DIRINCI && $mode === self::MODE_SUDAH) return null;

        return match ($mode) {
            self::MODE_SUDAH => $sel['status'] === 'lunas' ? $sel : null,
            self::MODE_BELUM => $sel['status'] !== 'lunas' ? $sel : null,
            default          => $sel,
        };
    }

    /** Angka yang dihitung ke Total dan dipakai untuk sort: yang sudah diterima pada mode Sudah cair, selain itu sisa yang belum cair. */
    public static function nilai(array $sel, string $mode): float
    {
        return (float) ($mode === self::MODE_SUDAH ? $sel['cair'] : $sel['sisa']);
    }

    /**
     * Terapkan kolom terpilih & mode ke baris, hitung Total, lalu urutkan.
     *
     * @param  Collection<int, array>  $meta  baris identitas; wajib punya 'id' dan 'akad_raw' (Y-m-d|null), 'nama', 'unit'
     * @param  string[]  $terpilih  kunci kolom yang ditampilkan
     * @return array{baris: array[], total_kolom: array<string, float>, total: float}
     */
    public static function susun(Collection $meta, array $terpilih, string $mode, string $sort, string $dir): array
    {
        $data = self::dataset();
        $baris = [];
        $totalKolom = array_fill_keys($terpilih, 0.0);
        $totalSemua = 0.0;

        foreach ($meta as $m) {
            $semua = $data['sel'][$m['id']] ?? [];
            $tampil = [];
            $total = 0.0;
            $adaIsi = false;
            foreach ($terpilih as $kunci) {
                $sel = self::tampilkan($semua[$kunci] ?? null, $mode, $kunci);
                $tampil[$kunci] = $sel;
                if ($sel) {
                    $adaIsi = true;
                    $total += self::nilai($sel, $mode);
                }
            }
            if ($mode !== self::MODE_SEMUA && !$adaIsi) continue;

            foreach ($tampil as $kunci => $sel) {
                if ($sel) $totalKolom[$kunci] += self::nilai($sel, $mode);
            }
            $totalSemua += $total;
            $baris[] = $m + ['sel' => $tampil, 'total' => round($total, 2)];
        }

        $arah = $dir === 'asc' ? 1 : -1;
        $nilaiSort = function (array $b) use ($sort, $mode) {
            return match (true) {
                $sort === 'nama'  => mb_strtolower($b['nama']),
                $sort === 'unit'  => mb_strtolower($b['unit']),
                $sort === 'akad'  => $b['akad_raw'],
                $sort === 'total' => $b['total'],
                default           => ($b['sel'][$sort] ?? null) ? self::nilai($b['sel'][$sort], $mode) : null,
            };
        };
        usort($baris, function ($a, $b) use ($nilaiSort, $arah, $sort) {
            $x = $nilaiSort($a);
            $y = $nilaiSort($b);
            if ($x === null || $y === null) {
                if ($x !== $y) return $x === null ? 1 : -1;          // kosong selalu di bawah, apa pun arahnya
            } else {
                $banding = $sort === 'unit' ? strnatcasecmp((string) $x, (string) $y) : ($x <=> $y);
                if ($banding !== 0) return $banding * $arah;
            }

            return $b['id'] <=> $a['id'];
        });

        return ['baris' => $baris, 'total_kolom' => array_map(fn ($v) => round($v, 2), $totalKolom), 'total' => round($totalSemua, 2)];
    }
}
