<?php

namespace App\Support;

use App\Models\KavlingKonsumen;
use Illuminate\Support\Collection;

/**
 * Bahan tab "Rekap Kekurangan" dengan tiga tampilan:
 *   - Piutang Konsumen KPR : Booking Fee, DP, biaya tanah, biaya tambahan (per nama), Tambahan UM, Titipan Biaya Akad
 *   - Pencairan Bank       : item Dajam (per nama), SBUM, Pencairan netto, retensi belum dirinci (hanya yang sudah akad/BAST)
 *   - Piutang Konsumen Cash: Booking Fee, DP, Pelunasan, Titipan Biaya Akad
 *
 * Tiap komponen berupa "sel": nominal (tagihan), cair (yang sudah masuk), sisa, status belum|sebagian|lunas, tanggal (terakhir masuk),
 * dan jatuh_tempo (tanggal mentah cicilan terdekat yang belum lunas; hanya Booking Fee/DP/Pelunasan). Terlambat tidak disimpan di cache,
 * dihitung saat susun() supaya tidak basi saat lewat tengah malam.
 *
 * Dataset dihitung di PHP per transaksi (kartuPiutangBreakdown), dipecah per kelompok (kpr / cash), di-cache dan kedaluwarsa otomatis
 * bersama data keuangan (FinanceCache). Status & kolom terpilih diterapkan di susun(), jadi isi sel, Total, dan baris yang tampil
 * selalu konsisten dengan yang terlihat.
 */
class RekapKekuranganBank
{
    public const MODE_BELUM = 'belum_cair';
    public const MODE_SUDAH = 'sudah_cair';
    public const MODE_SEMUA = 'semua';
    public const MODE = [self::MODE_BELUM, self::MODE_SUDAH, self::MODE_SEMUA];

    public const TAMPILAN_KPR = 'piutang_kpr';
    public const TAMPILAN_BANK = 'pencairan_bank';
    public const TAMPILAN_CASH = 'piutang_cash';
    public const TAMPILAN = [self::TAMPILAN_KPR, self::TAMPILAN_BANK, self::TAMPILAN_CASH];

    /** Urutan pipeline penjualan (tanpa batal). */
    public const TAHAP = ['booking', 'pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad', 'akad', 'bast'];
    /** Tahap yang normal untuk tiap tampilan. Cash tidak melewati Proses Bank/SP3K (lihat alur status di BookingController). */
    private const TAHAP_STANDAR = [
        self::TAMPILAN_KPR  => self::TAHAP,
        self::TAMPILAN_BANK => ['akad', 'bast'],
        self::TAMPILAN_CASH => ['booking', 'pemberkasan', 'rencana_akad', 'akad', 'bast'],
    ];
    public const CARA_BAYAR = [
        self::TAMPILAN_KPR  => ['kpr_subsidi', 'kpr_komersil'],
        self::TAMPILAN_BANK => ['kpr_subsidi', 'kpr_komersil'],
        self::TAMPILAN_CASH => ['cash', 'cash_bertahap'],
    ];

    public const KOLOM_SBUM = 'sbum';
    public const KOLOM_PENCAIRAN = 'pencairan';
    public const KOLOM_BELUM_DIRINCI = 'belum_dirinci';
    private const PREFIX_ITEM = 'item:';
    private const PREFIX_BT = 'k:bt:';

    /** Label kolom bank selain item Dajam. */
    public const LABEL_LAINNYA = [
        self::KOLOM_SBUM => 'SBUM',
        self::KOLOM_PENCAIRAN => 'Pencairan netto',
        self::KOLOM_BELUM_DIRINCI => 'Belum dirinci',
    ];

    /** Kolom piutang konsumen yang tetap (selalu bisa dipilih), berurutan seperti Kartu Piutang. */
    public const LABEL_KONSUMEN = [
        'k:booking_fee' => 'Booking Fee',
        'k:dp'          => 'DP',
        'k:biaya_tanah' => 'Biaya Penambahan Tanah',
        'k:tum'         => 'Tambahan Uang Muka',
        'k:titipan'     => 'Titipan Biaya Akad',
        'k:pelunasan'   => 'Pelunasan',
    ];

    /** Kolom tetap per tampilan (yang selalu ada di panel kolom, juga bila belum ada konsumen yang memilikinya). */
    private const KONSUMEN_TETAP = [
        self::TAMPILAN_KPR  => ['k:booking_fee', 'k:dp', 'k:biaya_tanah', 'k:tum', 'k:titipan'],
        self::TAMPILAN_CASH => ['k:booking_fee', 'k:dp', 'k:pelunasan', 'k:titipan'],
    ];

    private const TIPE_KARTU = [
        'booking_fee_group'         => 'k:booking_fee',
        'dp_group'                  => 'k:dp',
        'biaya_tanah_group'         => 'k:biaya_tanah',
        'tambahan_um_group'         => 'k:tum',
        'titipan_biaya_akad_group'  => 'k:titipan',
        'pelunasan_group'           => 'k:pelunasan',
    ];

    /** Jenis jadwal tagihan untuk kolom yang punya jatuh tempo. */
    private const JADWAL = ['k:booking_fee' => 'booking_fee', 'k:dp' => 'dp', 'k:pelunasan' => 'pelunasan'];

    /**
     * Tahap yang ditawarkan di filter. Pada tampilan Cash, Proses Bank/SP3K hanya muncul bila ada transaksi cash yang benar-benar berada
     * di tahap itu (data salah input/lama), supaya konsumennya tidak tersembunyi diam-diam dari rekap tagihan.
     *
     * @param  string[]  $ditemukan  tahap tak-standar yang dipakai transaksi cash (lihat tahapCashTakStandar())
     * @return string[]  berurutan sesuai pipeline
     */
    public static function tahapTersedia(string $tampilan, array $ditemukan = []): array
    {
        $dasar = self::TAHAP_STANDAR[$tampilan];

        return array_values(array_filter(
            self::TAHAP,
            fn ($t) => in_array($t, $dasar, true) || ($tampilan === self::TAMPILAN_CASH && in_array($t, $ditemukan, true))
        ));
    }

    /** Tahap yang tercentang bila parameter tahap tidak ada: KPR hanya yang menuju/sudah akad, lainnya semua yang tersedia. */
    public static function tahapDefault(string $tampilan, array $ditemukan = []): array
    {
        return $tampilan === self::TAMPILAN_KPR ? ['sp3k', 'rencana_akad', 'akad', 'bast'] : self::tahapTersedia($tampilan, $ditemukan);
    }

    /** @return string[] tahap Proses Bank/SP3K yang ternyata dipakai transaksi cash (normalnya kosong). */
    public static function tahapCashTakStandar(): array
    {
        return KavlingKonsumen::query()
            ->whereIn('cara_bayar', ['cash', 'cash_bertahap'])
            ->where('status', '!=', 'cancelled')
            ->whereIn('status_penjualan', ['proses_bank', 'sp3k'])
            ->distinct()->pluck('status_penjualan')->all();
    }

    public static function kelompok(string $tampilan): string
    {
        return $tampilan === self::TAMPILAN_CASH ? 'cash' : 'kpr';
    }

    /**
     * @return array{sel: array<int, array<string, array>>, label: array<string, string>}
     *   sel[id_transaksi][kunci_kolom] = sel; label[kunci] = nama apa adanya (pertama yang ditemui; untuk item Dajam & biaya tambahan).
     */
    public static function dataset(string $kelompok): array
    {
        return FinanceCache::remember('rekap-kekurangan:' . $kelompok, function () use ($kelompok) {
            $sel = [];
            $label = [];
            KavlingKonsumen::query()
                ->whereIn('cara_bayar', $kelompok === 'cash' ? ['cash', 'cash_bertahap'] : ['kpr_subsidi', 'kpr_komersil'])
                ->where('status', '!=', 'cancelled')
                ->where('status_penjualan', '!=', 'batal')
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
        $breakdown = $kk->kartuPiutangBreakdown();
        $hasil = self::selKonsumen($kk, $breakdown['kartu_piutang_static'], $label);

        // Sisi bank hanya berarti untuk KPR yang sudah akad/BAST.
        if (in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil'], true) && in_array($kk->status_penjualan, ['akad', 'bast'], true)) {
            $hasil += self::selBank($kk, $breakdown['pencairan_kpr'], $label);
        }

        return $hasil;
    }

    /** Piutang konsumen dari baris Kartu Piutang (nominal sudah neto setelah potongan promo). */
    private static function selKonsumen(KavlingKonsumen $kk, Collection $kartu, array &$label): array
    {
        $akum = [];
        foreach ($kartu as $item) {
            $kunci = $item['type'] === 'biaya_tambahan_group'
                ? self::PREFIX_BT . mb_strtolower(trim((string) $item['nama']))
                : (self::TIPE_KARTU[$item['type']] ?? null);
            $nominal = (float) ($item['nominal'] ?? 0);
            if ($kunci === null || $nominal <= 0) continue;

            if ($item['type'] === 'biaya_tambahan_group') {
                $label[$kunci] ??= trim((string) $item['nama']);
            }
            $akum[$kunci]['nominal'] = ($akum[$kunci]['nominal'] ?? 0) + $nominal;
            $akum[$kunci]['cair'] = ($akum[$kunci]['cair'] ?? 0) + (float) ($item['jumlah_dibayar'] ?? 0);
            $tanggal = self::tanggalMasuk($kk, $kunci, $item);
            if ($tanggal && $tanggal > ($akum[$kunci]['tanggal'] ?? '')) $akum[$kunci]['tanggal'] = $tanggal;
        }

        $hasil = [];
        foreach ($akum as $kunci => $a) {
            if ($a['nominal'] <= 0.5) continue;
            $sel = self::sel($a['nominal'], min($a['cair'], $a['nominal']), isset($a['tanggal']) ? date('d M Y', strtotime($a['tanggal'])) : null);
            if ($sel['sisa'] > 0.5 && isset(self::JADWAL[$kunci])) {
                $sel['jatuh_tempo'] = $kk->jadwalTagihans->where('jenis', self::JADWAL[$kunci])->where('status', '!=', 'lunas')
                    ->map(fn ($j) => $j->tanggal_jatuh_tempo?->format('Y-m-d'))->filter()->min();
            }
            $hasil[$kunci] = $sel;
        }

        return $hasil;
    }

    /** Tanggal (Y-m-d) pembayaran terakhir untuk satu baris Kartu Piutang. */
    private static function tanggalMasuk(KavlingKonsumen $kk, string $kunci, array $item): ?string
    {
        if (isset(self::JADWAL[$kunci])) {
            return $kk->jadwalTagihans->where('jenis', self::JADWAL[$kunci])
                ->map(fn ($j) => $j->pembayaran?->tanggal_bayar?->format('Y-m-d'))->filter()->max();
        }

        return collect($item['cicilan'] ?? [])->pluck('tanggal_bayar_raw')->filter()->max();
    }

    /** Sisi bank: item Dajam per nama, SBUM, Pencairan netto, retensi belum dirinci. */
    private static function selBank(KavlingKonsumen $kk, ?array $pk, array &$label): array
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
     * Daftar kolom yang bisa dipilih untuk sekumpulan transaksi pada satu tampilan (checkbox).
     * Piutang konsumen: kolom tetap + biaya tambahan yang ada. Pencairan Bank: item Dajam yang benar-benar ada, lalu SBUM dst.
     *
     * @param  int[]  $ids
     * @return array<int, array{key: string, label: string, grup: string, jumlah: int}>
     */
    public static function kolomTersedia(array $ids, string $tampilan): array
    {
        $data = self::dataset(self::kelompok($tampilan));
        $jumlah = [];
        foreach ($ids as $id) {
            foreach (array_keys($data['sel'][$id] ?? []) as $kunci) {
                $jumlah[$kunci] = ($jumlah[$kunci] ?? 0) + 1;
            }
        }

        if ($tampilan === self::TAMPILAN_BANK) {
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

        $tetap = [];
        foreach (self::KONSUMEN_TETAP[$tampilan] as $kunci) {
            $tetap[$kunci] = ['key' => $kunci, 'label' => self::LABEL_KONSUMEN[$kunci], 'grup' => 'konsumen', 'jumlah' => $jumlah[$kunci] ?? 0];
        }
        $tambahan = [];
        foreach ($jumlah as $kunci => $n) {
            if (str_starts_with($kunci, self::PREFIX_BT)) {
                $tambahan[] = ['key' => $kunci, 'label' => $data['label'][$kunci] ?? substr($kunci, strlen(self::PREFIX_BT)), 'grup' => 'konsumen', 'jumlah' => $n];
            }
        }
        usort($tambahan, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));

        // Biaya tambahan disisipkan setelah biaya tanah (urutan Kartu Piutang), sebelum Tambahan UM / Titipan.
        $urut = [];
        foreach ($tetap as $kunci => $k) {
            $urut[] = $k;
            if ($kunci === 'k:biaya_tanah') array_push($urut, ...$tambahan);
        }
        return $urut;
    }

    /** Sel yang tampil menurut status (belum masuk / sudah masuk / semua), atau null (kosong). */
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

    /** Angka yang dihitung ke Total dan dipakai untuk sort: yang sudah masuk pada status Sudah masuk, selain itu sisa yang belum masuk. */
    public static function nilai(array $sel, string $mode): float
    {
        return (float) ($mode === self::MODE_SUDAH ? $sel['cair'] : $sel['sisa']);
    }

    /** Tambahkan label jatuh tempo & hari terlambat (dihitung saat ini) pada sel yang masih punya sisa. */
    private static function tandaiJatuhTempo(array $sel): array
    {
        if (empty($sel['jatuh_tempo']) || $sel['status'] === 'lunas') return $sel;

        $tempo = \Illuminate\Support\Carbon::parse($sel['jatuh_tempo'])->startOfDay();
        $hariIni = now()->startOfDay();
        $sel['jatuh_tempo_label'] = $tempo->format($tempo->year === $hariIni->year ? 'd M' : 'd M Y');
        $sel['terlambat_hari'] = $tempo->lt($hariIni) ? (int) $tempo->diffInDays($hariIni) : null;

        return $sel;
    }

    /**
     * Terapkan kolom terpilih & status ke baris, hitung Total, lalu urutkan.
     *
     * @param  Collection<int, array>  $meta  baris identitas; wajib punya 'id', 'nama', 'unit', 'tanggal_urut' (Y-m-d|null)
     * @param  string[]  $terpilih  kunci kolom yang ditampilkan
     * @return array{baris: array[], total_kolom: array<string, float>, total: float}
     */
    public static function susun(Collection $meta, array $terpilih, string $mode, string $sort, string $dir, string $tampilan): array
    {
        $data = self::dataset(self::kelompok($tampilan));
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
                $tampil[$kunci] = $sel ? self::tandaiJatuhTempo($sel) : null;
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
                $sort === 'akad'  => $b['tanggal_urut'],
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
