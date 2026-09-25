<?php

namespace App\Support;

use App\Models\BankRekananPreset;
use App\Models\BiayaTambahanPreset;
use App\Models\DajamSbumPreset;
use App\Models\DokumenTemplate;
use App\Models\Konsumen;
use App\Models\NotarisPreset;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\SumberLead;

/**
 * Definisi kolom Excel Import Konsumen — SATU sumber kebenaran dipakai baik
 * oleh generator template (KonsumenController::downloadImportTemplate) MAUPUN
 * importer (KonsumenSheetImport), supaya urutan kolom selalu sinkron. Import
 * membaca baris berdasarkan POSISI kolom (bukan nama header hasil parsing),
 * jadi urutan dari columns() harus selalu persis sama dengan urutan ditulis
 * ke sheet.
 *
 * req: 'w' wajib, 'o' opsional, 'k' kondisional (lihat kolom lain).
 */
class KonsumenImportSpec
{
    public const CARA_BAYAR_SHEETS = [
        'kpr_subsidi'   => 'KPR Subsidi',
        'kpr_komersil'  => 'KPR Komersil',
        'cash'          => 'Cash',
        'cash_bertahap' => 'Cash Bertahap',
    ];

    public static function isKpr(string $caraBayar): bool
    {
        return in_array($caraBayar, ['kpr_subsidi', 'kpr_komersil'], true);
    }

    public static function tahapOptions(string $caraBayar): array
    {
        return self::isKpr($caraBayar)
            ? ['Booking', 'Pemberkasan', 'Proses Bank', 'SP3K', 'Rencana Akad', 'Akad', 'BAST']
            : ['Booking', 'Pemberkasan', 'Rencana Akad', 'Akad', 'BAST'];
    }

    public static function tahapMap(string $caraBayar): array
    {
        $map = [
            'Booking' => 'booking', 'Pemberkasan' => 'pemberkasan', 'Proses Bank' => 'proses_bank',
            'SP3K' => 'sp3k', 'Rencana Akad' => 'rencana_akad', 'Akad' => 'akad', 'BAST' => 'bast',
        ];

        return array_intersect_key($map, array_flip(self::tahapOptions($caraBayar)));
    }

    public static function statusSp3kMap(): array
    {
        return ['Approved' => 'approved', 'Turun Plafon' => 'turun_plafon', 'Ditolak' => 'ditolak'];
    }

    public static function statusDokumenMap(): array
    {
        return ['Belum Ada' => 'belum_ada', 'Sudah Ada' => 'sudah_ada', 'Perlu Revisi' => 'perlu_revisi', 'Ditolak' => 'ditolak'];
    }

    public static function modeMap(): array
    {
        return ['Persen' => 'persen', 'Nominal' => 'nominal'];
    }

    public static function tanahModeMap(): array
    {
        return ['Per M2' => 'per_m2', 'Nominal' => 'nominal'];
    }

    public static function pekerjaanMap(): array
    {
        return array_flip(Konsumen::jenisPekerjaanLabel());
    }

    public static function statusPernikahanMap(): array
    {
        return array_flip(Konsumen::statusPernikahanLabel());
    }

    /** Preset Biaya Tambahan Lain aktif, urutan sesuai master (★ krusial — jumlah kolom ikut ini). */
    public static function biayaTambahanPresets(): array
    {
        return BiayaTambahanPreset::ordered()->pluck('nama')->all();
    }

    public static function sbumPresets(): array
    {
        return DajamSbumPreset::where('kategori', 'sbum')->orderBy('nama')->pluck('nama')->all();
    }

    public static function dajamPresets(): array
    {
        return DajamSbumPreset::where('kategori', 'dajam')->orderBy('nama')->pluck('nama')->all();
    }

    /** Dokumen Template Pemberkasan untuk 1 cara bayar, urutan sesuai master (★ krusial). */
    public static function dokumenTemplates(string $caraBayar): array
    {
        return DokumenTemplate::where('cara_bayar', $caraBayar)->orderBy('urutan')->orderBy('id')
            ->get(['nama_dokumen', 'sifat'])->all();
    }

    /** Daftar isi dropdown untuk sheet "Daftar" — nama list => array opsi. */
    public static function lists(): array
    {
        return [
            'Pekerjaan'           => array_values(Konsumen::jenisPekerjaanLabel()),
            'Status Pernikahan'   => array_values(Konsumen::statusPernikahanLabel()),
            'Sumber Lead'         => SumberLead::ordered()->pluck('nama')->all(),
            'Skema KPR Subsidi'   => SkemaDpPreset::where('cara_bayar', 'kpr_subsidi')->pluck('nama')->all(),
            'Skema KPR Komersil'  => SkemaDpPreset::where('cara_bayar', 'kpr_komersil')->pluck('nama')->all(),
            'Skema Cash'          => SkemaDpPreset::where('cara_bayar', 'cash')->pluck('nama')->all(),
            'Skema Cash Bertahap' => SkemaDpPreset::where('cara_bayar', 'cash_bertahap')->pluck('nama')->all(),
            'Sales / Agent'       => SalesAgent::where('is_active', true)->ordered()->pluck('nama')->all(),
            'Notaris'             => NotarisPreset::where('is_active', true)->ordered()->pluck('nama')->all(),
            'Promo'               => PromoPreset::pluck('nama')->all(),
            'Mode Diskon'         => ['Persen', 'Nominal'],
            'Mode Biaya Tanah'    => ['Per M2', 'Nominal'],
            'Program All In'      => ProgramAllInPreset::ordered()->pluck('nama')->all(),
            'Bank Rekanan'        => BankRekananPreset::where('is_active', true)->ordered()->pluck('nama')->all(),
            'Status SP3K'         => array_keys(self::statusSp3kMap()),
            'Status Dokumen'      => array_keys(self::statusDokumenMap()),
            'Tahap (KPR)'         => self::tahapOptions('kpr_subsidi'),
            'Tahap (Cash)'        => self::tahapOptions('cash'),
        ];
    }

    /** Nama list (sheet "Daftar") yang jadi sumber dropdown tiap key kolom. */
    public static function dropdownListFor(string $key, string $caraBayar): ?string
    {
        $skemaList = match ($caraBayar) {
            'kpr_subsidi'   => 'Skema KPR Subsidi',
            'kpr_komersil'  => 'Skema KPR Komersil',
            'cash'          => 'Skema Cash',
            'cash_bertahap' => 'Skema Cash Bertahap',
        };
        $tahapList = self::isKpr($caraBayar) ? 'Tahap (KPR)' : 'Tahap (Cash)';

        $map = [
            'kerja' => 'Pekerjaan', 'nikah' => 'Status Pernikahan', 'lead' => 'Sumber Lead',
            'skema' => $skemaList, 'tahap' => $tahapList, 'sales' => 'Sales / Agent',
            'promo' => 'Promo', 'diskon_mode' => 'Mode Diskon', 'tanah_mode' => 'Mode Biaya Tanah',
            'all_in' => 'Program All In', 'bank' => 'Bank Rekanan', 'sp3k' => 'Status SP3K',
            'notaris' => 'Notaris',
        ];

        if (isset($map[$key])) return $map[$key];
        if (str_starts_with($key, 'dok:')) return 'Status Dokumen';

        return null;
    }

    /**
     * Urutan kolom final untuk 1 sheet cara bayar. Dibaca oleh importer
     * berdasar POSISI (index), jadi urutan array ini = urutan kolom di Excel.
     *
     * @return array<int, array{key:string, label:string, req:string, rule:string}>
     */
    public static function columns(string $caraBayar): array
    {
        $kpr = self::isKpr($caraBayar);
        $c = [];

        $c[] = ['nama', 'Nama Konsumen', 'w', 'Nama lengkap konsumen. Satu-satunya identitas yang wajib ada apa pun kondisi datanya.'];
        $c[] = ['nik', 'NIK', 'o', 'Kalau ada, jadi kunci pencocokan konsumen (1 konsumen 2 unit -> isi NIK sama di tiap baris). Kalau tidak ketemu di data lama, KOSONGKAN SAJA -- jangan mengarang. Baris tanpa NIK selalu dianggap konsumen baru.'];
        $c[] = ['npwp', 'NPWP', 'o', '15 atau 16 digit (titik/strip boleh). Kosongkan kalau tidak ada.'];
        $c[] = ['hp', 'No. HP', 'o', 'Kosongkan kalau tidak ada catatannya, terutama konsumen lama.'];
        $c[] = ['email', 'Email', 'o', ''];
        $c[] = ['alamat', 'Alamat', 'o', 'Dipakai otomatis di dokumen surat kalau ada.'];
        $c[] = ['kerja', 'Pekerjaan', 'o', 'Dropdown. Kosongkan kalau tidak tercatat.'];
        $c[] = ['nikah', 'Status Pernikahan', 'o', 'Dropdown. Kosongkan kalau tidak tercatat.'];
        $c[] = ['lead', 'Sumber Lead', 'o', 'Dropdown dari master Sumber Lead. Kosongkan kalau tidak tercatat.'];
        $c[] = ['referral', 'Keterangan Referral', 'k', 'WAJIB kalau Sumber Lead bertanda referral: diisi siapa yang mereferensikan.'];
        $c[] = ['kluster', 'Kluster', 'o', 'Kosongkan kalau proyek/unit tidak punya kluster. Kalau diisi harus sama persis dengan kluster di Stok Kavling.'];
        $c[] = ['blok', 'Blok', 'w', 'Blok unit (mis. A1), persis seperti di Stok Kavling.'];
        $c[] = ['unit', 'Nomor Kavling', 'w', 'Nomor di dalam bloknya, TANPA blok (mis. 1). Kombinasi Kluster+Blok+Nomor harus sudah ada di Stok Kavling dengan status Tersedia.'];
        $c[] = ['tgl_booking', 'Tanggal Booking', 'w', 'Format tanggal.'];
        $c[] = ['tahap', 'Tahap Saat Ini', 'w', 'Tahap pipeline konsumen sekarang.'];
        $c[] = ['skema', 'Skema Pembayaran', 'w', 'Dropdown skema sesuai cara bayar sheet ini.'];
        $c[] = ['sales', 'Sales / Agent', 'o', 'Dropdown dari master Sales/Agent. Kosongkan kalau tidak ketahuan siapa yang jual dulu.'];
        $c[] = ['harga_dasar', 'Harga Dasar', 'o', 'KOSONG = otomatis pakai Harga di Stok Kavling untuk unit ini. WAJIB diisi kalau harga unit di Stok Kavling juga kosong.'];
        $c[] = ['promo', 'Nama Promo', 'o', 'Dropdown master Promo.'];
        // Harga Deal TIDAK diinput -- dihitung otomatis: Harga Dasar + Biaya Tanah + Biaya Tambahan Lain - Diskon.
        $c[] = ['diskon_mode', 'Mode Diskon', 'k', 'Wajib kalau ada Nilai Diskon.'];
        $c[] = ['diskon_nilai', 'Nilai Diskon', 'k', 'Persen atau nominal sesuai Mode Diskon.'];
        $c[] = ['tanah_mode', 'Biaya Tanah - Mode', 'o', 'Isi kalau unit punya biaya kelebihan tanah.'];
        $c[] = ['tanah_luas', 'Biaya Tanah - Luas (m2)', 'k', 'Wajib kalau mode Per M2.'];
        $c[] = ['tanah_harga', 'Biaya Tanah - Harga/m2', 'k', 'Wajib kalau mode Per M2.'];
        $c[] = ['tanah_target', 'Biaya Tanah - Target', 'k', 'Wajib kalau mode Nominal.'];
        $c[] = ['tanah_bayar', 'Biaya Tanah - Terbayar', 'k', 'Rekap total terbayar (Saldo Awal).'];
        $c[] = ['tanah_tgl', 'Biaya Tanah - Tgl Terakhir', 'k', 'Wajib kalau Terbayar terisi.'];
        $c[] = ['bf_target', 'Booking Fee - Target', 'k', 'Kosong = dihitung dari Skema Pembayaran.'];
        $c[] = ['bf_bayar', 'Booking Fee - Terbayar', 'k', 'Rekap total terbayar (Saldo Awal).'];
        $c[] = ['bf_tgl', 'Booking Fee - Tgl Terakhir', 'k', 'Wajib kalau Terbayar terisi.'];
        $c[] = ['dp_target', 'DP - Target', 'k', 'Kosong = dihitung dari Skema Pembayaran.'];
        $c[] = ['dp_bayar', 'DP - Terbayar', 'k', 'Rekap total terbayar (Saldo Awal).'];
        $c[] = ['dp_tgl', 'DP - Tgl Terakhir', 'k', 'Wajib kalau Terbayar terisi.'];
        if (!$kpr) {
            if ($caraBayar === 'cash_bertahap') {
                $c[] = ['tenor', 'Tenor Pelunasan (kali)', 'k', 'Jumlah cicilan pelunasan.'];
            }
            $c[] = ['pel_target', 'Pelunasan - Target', 'k', 'Kosong = Harga Deal dikurangi komponen lain.'];
            $c[] = ['pel_bayar', 'Pelunasan - Terbayar', 'k', 'Rekap total terbayar (Saldo Awal).'];
            $c[] = ['pel_tgl', 'Pelunasan - Tgl Terakhir', 'k', 'Wajib kalau Terbayar terisi.'];
        }
        $c[] = ['all_in', 'Program All In', 'o', 'Dropdown master Program All In.'];
        $c[] = ['titipan_target', 'Titipan Biaya Akad - Target', 'k', 'Muncul kalau Program All In dipilih.'];
        $c[] = ['titipan_bayar', 'Titipan Biaya Akad - Terbayar', 'k', 'Rekap total terbayar (Saldo Awal).'];
        $c[] = ['titipan_tgl', 'Titipan Biaya Akad - Tgl Terakhir', 'k', 'Wajib kalau Terbayar terisi.'];
        foreach (self::biayaTambahanPresets() as $bt) {
            $c[] = ["bt:{$bt}:t", "* Biaya Tambahan: {$bt} - Target", 'k', 'KRUSIAL. Kosong = biaya ini tidak berlaku.'];
            $c[] = ["bt:{$bt}:b", "* Biaya Tambahan: {$bt} - Terbayar", 'k', 'Rekap total terbayar (Saldo Awal).'];
            $c[] = ["bt:{$bt}:d", "* Biaya Tambahan: {$bt} - Tgl Terakhir", 'k', 'Wajib kalau Terbayar terisi.'];
        }
        if ($kpr) {
            $c[] = ['bank', 'Bank Rekanan KPR', 'w', 'Dropdown master Bank Rekanan.'];
            $c[] = ['plafon', 'Plafon KPR', 'k', 'Wajib kalau tahap sudah Proses Bank atau lebih.'];
            $c[] = ['tgl_ajuan', 'Tanggal Pengajuan Bank', 'k', 'Wajib kalau tahap sudah Proses Bank atau lebih.'];
            $c[] = ['sp3k', 'Status SP3K', 'k', 'Wajib kalau tahap sudah SP3K atau lebih.'];
            $c[] = ['tgl_sp3k', 'Tanggal SP3K', 'k', 'Wajib kalau Status SP3K terisi.'];
            $c[] = ['tgl_exp', 'Tanggal Expired SP3K', 'k', 'Wajib kalau Status SP3K terisi.'];
            $c[] = ['tum_bayar', 'Tambahan Uang Muka - Terbayar', 'k', 'Isi kalau Plafon KPR turun & sudah dibayar sebagian/semua (Saldo Awal).'];
            $c[] = ['tum_tgl', 'Tambahan Uang Muka - Tgl Terakhir', 'k', 'Wajib kalau Terbayar terisi.'];
            foreach (self::sbumPresets() as $s) {
                $c[] = ["sbum:{$s}:n", "* SBUM: {$s} - Nominal", 'k', 'KRUSIAL. Nominal kosong = tidak berlaku.'];
                $c[] = ["sbum:{$s}:d", "* SBUM: {$s} - Tgl Cair", 'k', 'Terisi = sudah cair.'];
            }
            foreach (self::dajamPresets() as $d) {
                $c[] = ["dajam:{$d}:n", "* Dajam: {$d} - Nominal", 'k', 'KRUSIAL. Nominal kosong = tidak berlaku.'];
                $c[] = ["dajam:{$d}:d", "* Dajam: {$d} - Tgl Cair", 'k', 'Terisi = sudah cair.'];
            }
            $c[] = ['cair_bayar', 'Pencairan KPR - Total Sudah Cair', 'k', 'Total nominal yang sudah masuk dari bank ke developer (Saldo Awal).'];
            $c[] = ['cair_tgl', 'Pencairan KPR - Tgl Terakhir', 'k', 'Wajib kalau Total Sudah Cair terisi.'];
        }
        $c[] = ['tgl_rencana', 'Tanggal Rencana Akad', 'k', 'Wajib kalau tahap sudah Rencana Akad atau lebih.'];
        $c[] = ['notaris', 'Notaris', 'o', 'Dropdown master Notaris.'];
        $c[] = ['tgl_akad', 'Tanggal Akad', 'k', 'Wajib kalau tahap sudah Akad atau BAST.'];
        $c[] = ['tgl_bast', 'Tanggal BAST', 'k', 'Wajib kalau tahap BAST.'];
        foreach (self::dokumenTemplates($caraBayar) as $d) {
            $c[] = ["dok:{$d->nama_dokumen}", "* Dok: {$d->nama_dokumen}", 'o', "KRUSIAL. Sifat: {$d->sifat}. Kosong = Belum Ada."];
        }
        $c[] = ['catatan', 'Catatan', 'o', ''];

        return $c;
    }
}
