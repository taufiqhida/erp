<?php

namespace App\Imports\Konsumen;

use App\Enums\StatusJual;
use App\Models\BankRekananPreset;
use App\Models\BiayaTambahanPreset;
use App\Models\DajamSbumPreset;
use App\Models\DokumenKonsumen;
use App\Models\DokumenTemplate;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\NotarisPreset;
use App\Models\Project;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\SumberLead;
use App\Support\KonsumenImportSpec;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import 1 sheet (1 cara bayar) dari Excel Import Konsumen. Baris dibaca
 * berdasar POSISI kolom (bukan nama header) — lihat KonsumenImportSpec.
 * Filosofi sama seperti KavlingImport: baris tidak valid di-skip dengan
 * pesan jelas, tidak pernah ditebak/diisi default diam-diam.
 *
 * Pola "Saldo Awal (Import)": nominal "Terbayar" tiap komponen dicatat
 * sebagai 1 (atau beberapa, untuk komponen bercicilan) baris pembayaran
 * berketerangan "Saldo Awal (Import)", bukan rekonstruksi cicilan asli.
 * Untuk Booking Fee/DP/Pelunasan (yang jadwalnya dari jadwal_tagihan),
 * nominal terbayar dialokasikan FIFO ke cicilan paling awal — persis pola
 * yang sudah dipakai KeuanganController::payJadwalTagihan() (1 pembayaran
 * per baris cicilan), supaya jumlah_dibayar & progress "x/y lunas" di
 * Kartu Piutang terhitung benar (bukan dobel-hitung).
 */
class KonsumenSheetImport implements ToCollection, SkipsEmptyRows
{
    private array $columns;
    private array $tahapMap;
    private array $tahapOrder;
    private bool $isKpr;

    public function __construct(
        private Project $project,
        private string $caraBayar,
        private string $sheetLabel,
        private KonsumenImportResult $result,
    ) {
        $this->columns = KonsumenImportSpec::columns($caraBayar);
        $this->tahapMap = KonsumenImportSpec::tahapMap($caraBayar);
        $this->tahapOrder = array_values($this->tahapMap);
        $this->isKpr = KonsumenImportSpec::isKpr($caraBayar);
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            if ($index === 0) continue; // baris 1 = header
            $cells = $row instanceof Collection ? $row->values()->all() : array_values((array) $row);
            if (collect($cells)->filter(fn ($c) => trim((string) $c) !== '')->isEmpty()) continue;
            $this->processRow($index + 1, $cells);
        }
    }

    private function processRow(int $rowNum, array $cells): void
    {
        $v = [];
        foreach ($this->columns as $i => [$key]) {
            $raw = $cells[$i] ?? null;
            $v[$key] = is_string($raw) ? trim($raw) : $raw;
        }
        $str = fn (string $k) => $v[$k] !== null && $v[$k] !== '' ? (string) $v[$k] : null;
        $num = fn (string $k) => $this->parseAngka($v[$k]);

        $errors = [];
        $dateOf = function (string $k) use (&$errors, $v) {
            [$val, $err] = $this->parseTanggal($v[$k] ?? null);
            if ($err) $errors[] = "{$k}: {$err}";
            return $val;
        };

        // ── Wajib mutlak ──
        $nama = $str('nama');
        if (!$nama) $errors[] = 'Nama Konsumen kosong.';

        $kluster = $str('kluster') ?? '';
        $blok = $str('blok');
        $unit = $str('unit');
        if (!$blok) $errors[] = 'Blok kosong.';
        if (!$unit) $errors[] = 'Nomor Kavling kosong.';

        $tglBooking = $dateOf('tgl_booking');
        if (!$tglBooking) $errors[] = 'Tanggal Booking kosong/format tidak dikenali.';

        $tahapLabel = $str('tahap');
        $tahapKey = $tahapLabel ? ($this->tahapMap[$tahapLabel] ?? null) : null;
        if (!$tahapLabel) {
            $errors[] = 'Tahap Saat Ini kosong.';
        } elseif (!$tahapKey) {
            $errors[] = "Tahap Saat Ini '{$tahapLabel}' tidak dikenal.";
        }

        $skemaLabel = $str('skema');
        $skemaPreset = null;
        if (!$skemaLabel) {
            $errors[] = 'Skema Pembayaran kosong.';
        } else {
            $skemaPreset = SkemaDpPreset::where('cara_bayar', $this->caraBayar)
                ->whereRaw('LOWER(nama) = ?', [mb_strtolower($skemaLabel)])->first();
            if (!$skemaPreset) $errors[] = "Skema Pembayaran '{$skemaLabel}' tidak dikenal untuk cara bayar ini.";
        }

        $npwp = $str('npwp');
        if ($npwp !== null && !in_array(strlen(preg_replace('/\D/', '', $npwp)), [15, 16], true)) {
            $errors[] = "NPWP '{$npwp}' harus 15 atau 16 digit.";
        }

        // ── Lookup master opsional (skip kalau diisi tapi tidak dikenal) ──
        $sumberLead = null;
        if ($leadLabel = $str('lead')) {
            $sumberLead = SumberLead::whereRaw('LOWER(nama) = ?', [mb_strtolower($leadLabel)])->first();
            if (!$sumberLead) $errors[] = "Sumber Lead '{$leadLabel}' tidak dikenal.";
        }
        $referralKeterangan = $str('referral');
        if ($sumberLead?->is_referral && !$referralKeterangan) {
            $errors[] = 'Keterangan Referral wajib diisi karena Sumber Lead bertanda referral.';
        }

        $pekerjaanKey = null;
        if ($kerjaLabel = $str('kerja')) {
            $pekerjaanKey = KonsumenImportSpec::pekerjaanMap()[$kerjaLabel] ?? null;
            if (!$pekerjaanKey) $errors[] = "Pekerjaan '{$kerjaLabel}' tidak dikenal.";
        }
        $nikahKey = null;
        if ($nikahLabel = $str('nikah')) {
            $nikahKey = KonsumenImportSpec::statusPernikahanMap()[$nikahLabel] ?? null;
            if (!$nikahKey) $errors[] = "Status Pernikahan '{$nikahLabel}' tidak dikenal.";
        }

        $salesAgent = null;
        if ($salesLabel = $str('sales')) {
            $salesAgent = SalesAgent::whereRaw('LOWER(nama) = ?', [mb_strtolower($salesLabel)])->first();
            if (!$salesAgent) $errors[] = "Sales/Agent '{$salesLabel}' tidak dikenal.";
        }

        $notaris = null;
        if ($notarisLabel = $str('notaris')) {
            $notaris = NotarisPreset::whereRaw('LOWER(nama) = ?', [mb_strtolower($notarisLabel)])->first();
            if (!$notaris) $errors[] = "Notaris '{$notarisLabel}' tidak dikenal.";
        }

        $promo = null;
        if ($promoLabel = $str('promo')) {
            $promo = PromoPreset::whereRaw('LOWER(nama) = ?', [mb_strtolower($promoLabel)])->first();
            if (!$promo) $errors[] = "Promo '{$promoLabel}' tidak dikenal.";
        }

        $allInPreset = null;
        if ($allInLabel = $str('all_in')) {
            $allInPreset = ProgramAllInPreset::whereRaw('LOWER(nama) = ?', [mb_strtolower($allInLabel)])->first();
            if (!$allInPreset) $errors[] = "Program All In '{$allInLabel}' tidak dikenal.";
        }

        $bankNama = null;
        if ($this->isKpr) {
            $bankNama = $str('bank');
            if (!$bankNama) {
                $errors[] = 'Bank Rekanan KPR kosong (wajib untuk cara bayar KPR).';
            } elseif (!BankRekananPreset::whereRaw('LOWER(nama) = ?', [mb_strtolower($bankNama)])->exists()) {
                $errors[] = "Bank Rekanan KPR '{$bankNama}' tidak dikenal.";
            }
        }

        // ── Unit ──
        $kavling = null;
        if ($blok && $unit) {
            $kavling = Kavling::where('project_id', $this->project->id)
                ->where('kluster_key', $kluster)
                ->where('blok_key', $blok)
                ->where('nomor_kavling', $unit)
                ->lockForUpdate()
                ->first();
            $unitLabel = ($kluster !== '' ? "{$kluster} · " : '') . "{$blok}-{$unit}";
            if (!$kavling) {
                $errors[] = "Unit '{$unitLabel}' tidak ditemukan di Stok Kavling proyek ini.";
            } elseif ($kavling->status_jual !== StatusJual::Available) {
                $errors[] = "Unit '{$unitLabel}' berstatus '{$kavling->status_jual->label()}', bukan Tersedia.";
            }
        }

        // ── Harga Dasar (fallback ke harga unit) ──
        $hargaDasarInput = $num('harga_dasar');
        $hargaDasar = $hargaDasarInput ?? (float) ($kavling->harga ?? 0);
        if ($hargaDasar <= 0) {
            $errors[] = 'Harga Dasar kosong dan Harga unit di Stok Kavling juga kosong/nol — isi salah satu.';
        }

        // ── Diskon ──
        $diskonModeLabel = $str('diskon_mode');
        $diskonMode = $diskonModeLabel ? (KonsumenImportSpec::modeMap()[$diskonModeLabel] ?? null) : null;
        if ($diskonModeLabel && !$diskonMode) $errors[] = "Mode Diskon '{$diskonModeLabel}' tidak dikenal.";
        $diskonNilai = $num('diskon_nilai');
        if ($diskonMode && $diskonNilai === null) $errors[] = 'Nilai Diskon wajib diisi karena Mode Diskon terisi.';
        if (!$diskonMode && $diskonNilai !== null) $errors[] = 'Mode Diskon wajib diisi karena Nilai Diskon terisi.';

        // ── Biaya Kelebihan Tanah ──
        $tanahModeLabel = $str('tanah_mode');
        $tanahMode = $tanahModeLabel ? (KonsumenImportSpec::tanahModeMap()[$tanahModeLabel] ?? null) : null;
        if ($tanahModeLabel && !$tanahMode) $errors[] = "Biaya Tanah Mode '{$tanahModeLabel}' tidak dikenal.";
        $tanahAktif = (bool) $tanahMode;
        $tanahLuas = $num('tanah_luas');
        $tanahHarga = $num('tanah_harga');
        $tanahTargetInput = $num('tanah_target');
        $tanahNominal = 0.0;
        if ($tanahMode === 'per_m2') {
            if ($tanahLuas === null || $tanahHarga === null) {
                $errors[] = 'Biaya Tanah Luas & Harga/m2 wajib diisi untuk mode Per M2.';
            } else {
                $tanahNominal = round($tanahLuas * $tanahHarga, 2);
            }
        } elseif ($tanahMode === 'nominal') {
            if ($tanahTargetInput === null) {
                $errors[] = 'Biaya Tanah Target wajib diisi untuk mode Nominal.';
            } else {
                $tanahNominal = $tanahTargetInput;
            }
        }
        $tanahBayar = $num('tanah_bayar');
        $tanahTgl = $dateOf('tanah_tgl');
        if ($tanahBayar !== null && $tanahBayar > 0 && !$tanahTgl) $errors[] = 'Biaya Tanah Tgl Terakhir wajib diisi karena Terbayar terisi.';

        // ── Biaya Tambahan Lain (per preset) ──
        $biayaTambahanItems = []; // [preset, nominal, bayar, tgl]
        $biayaTambahanTotal = 0.0;
        foreach (KonsumenImportSpec::biayaTambahanPresets() as $btNama) {
            $target = $num("bt:{$btNama}:t");
            if ($target === null) continue;
            $bayar = $num("bt:{$btNama}:b");
            $tgl = $dateOf("bt:{$btNama}:d");
            if ($bayar !== null && $bayar > 0 && !$tgl) $errors[] = "Biaya Tambahan {$btNama} Tgl Terakhir wajib diisi karena Terbayar terisi.";
            $biayaTambahanTotal += $target;
            $biayaTambahanItems[] = ['nama' => $btNama, 'target' => $target, 'bayar' => $bayar, 'tgl' => $tgl];
        }

        $totalBiayaTambahan = $tanahNominal + $biayaTambahanTotal;
        $subtotal = $hargaDasar + $totalBiayaTambahan;
        $diskonNominal = 0.0;
        if ($diskonMode && $diskonNilai !== null) {
            $diskonNominal = $diskonMode === 'persen' ? round($subtotal * ($diskonNilai / 100), 2) : $diskonNilai;
        }
        $hargaJualNetto = $subtotal - $diskonNominal;

        // ── Booking Fee / DP (fallback ke Skema Pembayaran) ──
        $resolveBasis = fn (string $basis) => $basis === 'harga_dasar' ? $hargaDasar : $hargaJualNetto;
        $resolveNominal = function ($tipe, $nilai, $basis) use ($resolveBasis) {
            if (!$nilai) return 0.0;
            return $tipe === 'persen' ? round(((float) $nilai / 100) * $resolveBasis($basis), 2) : (float) $nilai;
        };
        $bfTargetInput = $num('bf_target');
        $bookingFee = $bfTargetInput ?? (($skemaPreset && $skemaPreset->booking_fee_aktif)
            ? $resolveNominal($skemaPreset->booking_fee_tipe, $skemaPreset->booking_fee_nilai, $skemaPreset->booking_fee_basis) : 0.0);
        $bfBayar = $num('bf_bayar');
        $bfTgl = $dateOf('bf_tgl');
        if ($bfBayar !== null && $bfBayar > 0 && !$bfTgl) $errors[] = 'Booking Fee Tgl Terakhir wajib diisi karena Terbayar terisi.';

        $dpTargetInput = $num('dp_target');
        $dpNominal = $dpTargetInput ?? (($skemaPreset && $skemaPreset->dp_aktif)
            ? $resolveNominal($skemaPreset->dp_tipe, $skemaPreset->dp_nilai, $skemaPreset->dp_basis) : 0.0);
        $dpBayar = $num('dp_bayar');
        $dpTgl = $dateOf('dp_tgl');
        if ($dpBayar !== null && $dpBayar > 0 && !$dpTgl) $errors[] = 'DP Tgl Terakhir wajib diisi karena Terbayar terisi.';
        $skemaDpString = $dpNominal > 0 ? "nominal:{$dpNominal}" : 'tanpa_dp';

        // ── Pelunasan (cash/cash bertahap) ──
        $pelunasanNominal = 0.0;
        $pelunasanTenor = 1;
        $pelBayar = null;
        $pelTgl = null;
        if (!$this->isKpr) {
            $bfPengurang = ($skemaPreset?->booking_fee_masuk_harga_jual ?? false) ? $bookingFee : 0;
            $dpPengurang = ($skemaPreset?->dp_masuk_harga_jual ?? false) ? $dpNominal : 0;
            $pelTargetInput = $num('pel_target');
            $pelunasanNominal = $pelTargetInput ?? max(0, $hargaJualNetto - $bfPengurang - $dpPengurang);
            if ($this->caraBayar === 'cash_bertahap') {
                $tenorInput = $num('tenor');
                $pelunasanTenor = $tenorInput !== null ? max(1, (int) $tenorInput) : 1;
            }
            $pelBayar = $num('pel_bayar');
            $pelTgl = $dateOf('pel_tgl');
            if ($pelBayar !== null && $pelBayar > 0 && !$pelTgl) $errors[] = 'Pelunasan Tgl Terakhir wajib diisi karena Terbayar terisi.';
        }

        // ── Program All In / Titipan Biaya Akad ──
        $titipanNominal = 0.0;
        if ($allInPreset) {
            $titipanNominal = (float) $allInPreset->nominal;
            if ($allInPreset->include_booking_fee) $titipanNominal -= $bookingFee;
            if ($allInPreset->include_dp) $titipanNominal -= $dpNominal;
            $titipanNominal = max(0, $titipanNominal);
        }
        $titipanBayar = $num('titipan_bayar');
        $titipanTgl = $dateOf('titipan_tgl');
        if ($titipanBayar !== null && $titipanBayar > 0 && !$titipanTgl) $errors[] = 'Titipan Biaya Akad Tgl Terakhir wajib diisi karena Terbayar terisi.';

        // ── KPR: Plafon, Pengajuan Bank, SP3K, SBUM, Dajam, Pencairan, Tambahan UM ──
        $plafon = null; $tglAjuan = null; $sp3kKey = null; $tglSp3k = null; $tglExp = null;
        $sbumItems = []; $dajamItems = [];
        $cairBayar = null; $cairTgl = null;
        $tumBayar = null; $tumTgl = null;
        if ($this->isKpr) {
            $tahapIdx = $tahapKey ? array_search($tahapKey, $this->tahapOrder, true) : null;
            $idxOf = fn (string $stage) => array_search($stage, $this->tahapOrder, true);

            $plafon = $num('plafon');
            $tglAjuan = $dateOf('tgl_ajuan');
            if ($tahapIdx !== null && $tahapIdx >= $idxOf('proses_bank')) {
                if ($plafon === null) $errors[] = 'Plafon KPR wajib diisi (tahap sudah Proses Bank atau lebih).';
                if (!$tglAjuan) $errors[] = 'Tanggal Pengajuan Bank wajib diisi (tahap sudah Proses Bank atau lebih).';
            }

            $sp3kLabel = $str('sp3k');
            $sp3kKey = $sp3kLabel ? (KonsumenImportSpec::statusSp3kMap()[$sp3kLabel] ?? null) : null;
            if ($sp3kLabel && !$sp3kKey) $errors[] = "Status SP3K '{$sp3kLabel}' tidak dikenal.";
            $tglSp3k = $dateOf('tgl_sp3k');
            $tglExp = $dateOf('tgl_exp');
            if ($tahapIdx !== null && $tahapIdx >= $idxOf('sp3k')) {
                if (!$sp3kKey) $errors[] = 'Status SP3K wajib diisi (tahap sudah SP3K atau lebih).';
            }
            if ($sp3kKey && (!$tglSp3k || !$tglExp)) {
                $errors[] = 'Tanggal SP3K & Tanggal Expired SP3K wajib diisi karena Status SP3K terisi.';
            }

            $tumBayar = $num('tum_bayar');
            $tumTgl = $dateOf('tum_tgl');
            if ($tumBayar !== null && $tumBayar > 0 && !$tumTgl) $errors[] = 'Tambahan Uang Muka Tgl Terakhir wajib diisi karena Terbayar terisi.';

            $sbumPresetsList = $this->caraBayar === 'kpr_subsidi' ? KonsumenImportSpec::sbumPresets() : [];
            foreach ($sbumPresetsList as $sNama) {
                $nominal = $num("sbum:{$sNama}:n");
                if ($nominal === null) continue;
                $tgl = $dateOf("sbum:{$sNama}:d");
                $sbumItems[] = ['nama' => $sNama, 'nominal' => $nominal, 'tgl' => $tgl];
            }
            foreach (KonsumenImportSpec::dajamPresets() as $dNama) {
                $nominal = $num("dajam:{$dNama}:n");
                if ($nominal === null) continue;
                $tgl = $dateOf("dajam:{$dNama}:d");
                $dajamItems[] = ['nama' => $dNama, 'nominal' => $nominal, 'tgl' => $tgl];
            }

            $cairBayar = $num('cair_bayar');
            $cairTgl = $dateOf('cair_tgl');
            if ($cairBayar !== null && $cairBayar > 0 && !$cairTgl) $errors[] = 'Pencairan KPR Tgl Terakhir wajib diisi karena Total Sudah Cair terisi.';
        }

        // ── Rencana Akad / Akad / BAST ──
        $tglRencana = $dateOf('tgl_rencana');
        $tglAkad = $dateOf('tgl_akad');
        $tglBast = $dateOf('tgl_bast');
        if ($tahapKey) {
            $idxOf = fn (string $stage) => array_search($stage, $this->tahapOrder, true);
            $tahapIdx = array_search($tahapKey, $this->tahapOrder, true);
            if ($tahapIdx >= $idxOf('rencana_akad') && !$tglRencana) $errors[] = 'Tanggal Rencana Akad wajib diisi (tahap sudah Rencana Akad atau lebih).';
            if ($tahapIdx >= $idxOf('akad') && !$tglAkad) $errors[] = 'Tanggal Akad wajib diisi (tahap sudah Akad atau lebih).';
            if ($tahapKey === 'bast' && !$tglBast) $errors[] = 'Tanggal BAST wajib diisi (tahap BAST).';
        }

        // ── Dokumen ──
        $docTemplates = KonsumenImportSpec::dokumenTemplates($this->caraBayar);
        $docStatuses = [];
        foreach ($docTemplates as $d) {
            $label = $str("dok:{$d->nama_dokumen}");
            $key = $label ? (KonsumenImportSpec::statusDokumenMap()[$label] ?? null) : 'belum_ada';
            if ($label && !$key) $errors[] = "Status dokumen '{$d->nama_dokumen}': '{$label}' tidak dikenal.";
            $docStatuses[$d->nama_dokumen] = ['status' => $key ?? 'belum_ada', 'sifat' => $d->sifat];
        }

        $catatan = $str('catatan');
        $nik = $str('nik');

        if (!empty($errors)) {
            $this->result->skip($this->sheetLabel, $rowNum, implode(' | ', array_unique($errors)));
            return;
        }

        try {
            KavlingKonsumen::withoutFinanceRefresh(fn () => DB::transaction(function () use (
                $rowNum, $nama, $nik, $npwp, $str, $kluster, $blok, $unit, $kavling, $tglBooking, $tahapKey,
                $skemaPreset, $skemaDpString, $sumberLead, $referralKeterangan, $pekerjaanKey, $nikahKey,
                $salesAgent, $notaris, $promo, $allInPreset, $bankNama, $hargaDasar, $hargaJualNetto,
                $diskonMode, $diskonNilai, $diskonNominal, $tanahAktif, $tanahMode, $tanahLuas, $tanahHarga,
                $tanahNominal, $tanahBayar, $tanahTgl, $totalBiayaTambahan, $biayaTambahanItems,
                $bookingFee, $bfBayar, $bfTgl, $dpNominal, $dpBayar, $dpTgl,
                $pelunasanNominal, $pelunasanTenor, $pelBayar, $pelTgl,
                $titipanNominal, $titipanBayar, $titipanTgl,
                $plafon, $tglAjuan, $sp3kKey, $tglSp3k, $tglExp, $sbumItems, $dajamItems,
                $cairBayar, $cairTgl, $tumBayar, $tumTgl,
                $tglRencana, $tglAkad, $tglBast, $docStatuses, $catatan,
            ) {
                $konsumen = $nik ? Konsumen::where('nik', $nik)->first() : null;
                if (!$konsumen) {
                    $konsumen = Konsumen::create([
                        'nama' => $nama,
                        'nik' => $nik,
                        'npwp' => $npwp,
                        'no_hp' => $str('hp'),
                        'email' => $str('email'),
                        'alamat' => $str('alamat'),
                        'pekerjaan' => $pekerjaanKey,
                        'status_pernikahan' => $nikahKey,
                        'sumber_lead_id' => $sumberLead?->id,
                        'referral_keterangan' => $sumberLead?->is_referral ? $referralKeterangan : null,
                    ]);
                }

                $tahapIdx = array_search($tahapKey, $this->tahapOrder, true);
                $idxOf = fn (string $stage) => array_search($stage, $this->tahapOrder, true);
                $statusBank = null; $tglKeputusanBank = null;
                if ($this->isKpr) {
                    if ($tahapIdx >= $idxOf('sp3k')) { $statusBank = 'disetujui'; $tglKeputusanBank = $tglSp3k ?? $tglAjuan; }
                    elseif ($tahapIdx >= $idxOf('proses_bank')) { $statusBank = 'diajukan'; }
                }

                $trx = KavlingKonsumen::create([
                    'kavling_id' => $kavling->id,
                    'konsumen_id' => $konsumen->id,
                    'status' => 'active',
                    'tanggal_booking' => $tglBooking,
                    'tanggal_akad' => $tglAkad,
                    'tanggal_bast' => $tglBast,
                    'harga_dasar' => $hargaDasar,
                    'harga_deal' => $hargaJualNetto,
                    'booking_fee' => $bookingFee,
                    'cara_bayar' => $this->caraBayar,
                    'cicilan_kali' => $this->caraBayar === 'cash_bertahap' ? $pelunasanTenor : null,
                    'skema_dp' => $skemaDpString,
                    'skema_dp_preset_id' => $skemaPreset->id,
                    'plafon_kpr' => $plafon,
                    'sales_agent_id' => $salesAgent?->id,
                    'biaya_kelebihan_tanah_aktif' => $tanahAktif,
                    'biaya_kelebihan_tanah_luas' => $tanahLuas,
                    'biaya_kelebihan_tanah_mode' => $tanahMode,
                    'biaya_kelebihan_tanah_harga_per_m2' => $tanahHarga,
                    'biaya_kelebihan_tanah_nominal' => $tanahNominal,
                    'promo_preset_id' => $promo?->id,
                    'diskon_mode' => $diskonMode,
                    'diskon_nilai' => $diskonNilai,
                    'diskon_nominal' => $diskonNominal,
                    'total_biaya_tambahan' => $totalBiayaTambahan,
                    'program_all_in_preset_id' => $allInPreset?->id,
                    'titipan_biaya_akad_nominal' => $titipanNominal,
                    'status_penjualan' => $tahapKey,
                    'tanggal_rencana_akad' => $tglRencana,
                    'notaris_preset_id' => $notaris?->id,
                    'tanggal_pengajuan_bank' => $tglAjuan,
                    'tanggal_keputusan_bank' => $tglKeputusanBank,
                    'status_bank' => $statusBank,
                    'tanggal_sp3k' => $tglSp3k,
                    'tanggal_expired_sp3k' => $tglExp,
                    'status_sp3k' => $sp3kKey,
                    'bank_rekanan_kpr' => $bankNama,
                    'catatan' => $catatan,
                    'created_by' => Auth::id(),
                ]);

                // Biaya Tambahan Lain per-preset
                $btRows = [];
                foreach ($biayaTambahanItems as $item) {
                    $preset = BiayaTambahanPreset::where('nama', $item['nama'])->first();
                    $bt = $trx->biayaTambahans()->create([
                        'biaya_tambahan_preset_id' => $preset?->id,
                        'nama' => $item['nama'],
                        'nominal' => $item['target'],
                    ]);
                    $btRows[$item['nama']] = ['model' => $bt, 'bayar' => $item['bayar'], 'tgl' => $item['tgl']];
                }

                // Jadwal Tagihan: Booking Fee, DP, Pelunasan — generate tenor lalu alokasikan Saldo Awal FIFO.
                $genJadwal = function (string $jenis, float $total, int $tenor) use ($trx, $tglBooking) {
                    if ($total <= 0 || $tenor < 1) return;
                    $perCicilan = round($total / $tenor, 2);
                    $sisa = $total;
                    for ($i = 0; $i < $tenor; $i++) {
                        $isLast = $i === $tenor - 1;
                        $jumlah = $isLast ? $sisa : $perCicilan;
                        $sisa -= $jumlah;
                        $trx->jadwalTagihans()->create([
                            'jenis' => $jenis, 'nomor_cicilan' => $i + 1, 'jumlah' => $jumlah,
                            'tanggal_jatuh_tempo' => $tglBooking->copy()->addMonths($i), 'status' => 'belum_bayar',
                        ]);
                    }
                };
                $applySaldoAwalJadwal = function (string $jenis, ?float $terbayar, $tanggal) use ($trx) {
                    if (!$terbayar || $terbayar <= 0) return;
                    $sisa = $terbayar;
                    foreach ($trx->jadwalTagihans()->where('jenis', $jenis)->orderBy('nomor_cicilan')->get() as $j) {
                        if ($sisa <= 0) break;
                        $bayarIni = min($sisa, (float) $j->jumlah);
                        $pembayaran = $trx->pembayarans()->create([
                            'jenis' => $jenis, 'jumlah' => $bayarIni, 'tanggal_bayar' => $tanggal,
                            'keterangan' => 'Saldo Awal (Import)', 'created_by' => Auth::id(),
                        ]);
                        $j->update([
                            'status' => $bayarIni >= (float) $j->jumlah ? 'lunas' : 'sebagian',
                            'pembayaran_konsumen_id' => $pembayaran->id,
                        ]);
                        $sisa -= $bayarIni;
                    }
                };

                $genJadwal('booking_fee', $bookingFee, max(1, $skemaPreset->booking_fee_aktif ? $skemaPreset->booking_fee_tenor : 1));
                $applySaldoAwalJadwal('booking_fee', $bfBayar, $bfTgl);
                $genJadwal('dp', $dpNominal, max(1, $skemaPreset->dp_aktif ? $skemaPreset->dp_tenor : 1));
                $applySaldoAwalJadwal('dp', $dpBayar, $dpTgl);
                if (!$this->isKpr) {
                    $genJadwal('pelunasan', $pelunasanNominal, $pelunasanTenor);
                    $applySaldoAwalJadwal('pelunasan', $pelBayar, $pelTgl);
                }

                // Cicilan bebas (bukan berjadwal): Titipan Biaya Akad (semua cara bayar),
                // Biaya Tanah & Biaya Tambahan Lain (khusus KPR — untuk cash sudah baku
                // di dalam nominal Pelunasan, lihat KavlingKonsumen::kartuPiutangBreakdown()).
                $payFree = function (string $jenis, ?float $jumlah, $tanggal, ?int $btId = null) use ($trx) {
                    if (!$jumlah || $jumlah <= 0) return;
                    $trx->pembayarans()->create([
                        'kavling_konsumen_biaya_tambahan_id' => $btId,
                        'jenis' => $jenis, 'jumlah' => $jumlah, 'tanggal_bayar' => $tanggal,
                        'keterangan' => 'Saldo Awal (Import)', 'created_by' => Auth::id(),
                    ]);
                };
                $payFree('titipan_biaya_akad', $titipanBayar, $titipanTgl);
                if ($this->isKpr) {
                    $payFree('biaya_tanah', $tanahBayar, $tanahTgl);
                    $payFree('tambahan_um', $tumBayar, $tumTgl);
                    foreach ($btRows as $row) {
                        if (($row['bayar'] ?? 0) > 0) {
                            $payFree('biaya_tambahan', $row['bayar'], $row['tgl'], $row['model']->id);
                            $row['model']->update([
                                'status' => $row['bayar'] >= (float) $row['model']->nominal ? 'lunas' : 'belum_bayar',
                                'pembayaran_konsumen_id' => $trx->pembayarans()->where('kavling_konsumen_biaya_tambahan_id', $row['model']->id)->latest('id')->value('id'),
                            ]);
                        }
                    }
                }

                // SBUM & Dajam — item lepas (1 nominal, opsional 1 pembayaran lunas).
                foreach ([...array_map(fn ($i) => $i + ['kategori' => 'sbum'], $sbumItems), ...array_map(fn ($i) => $i + ['kategori' => 'dajam'], $dajamItems)] as $item) {
                    $preset = DajamSbumPreset::where('kategori', $item['kategori'])->where('nama', $item['nama'])->first();
                    $rincian = $trx->rincianBiayaAkad()->create([
                        'dajam_sbum_preset_id' => $preset?->id,
                        'nama' => $item['nama'], 'kategori' => $item['kategori'], 'nominal' => $item['nominal'],
                    ]);
                    if ($item['tgl']) {
                        $pembayaran = $trx->pembayarans()->create([
                            'jenis' => $item['kategori'], 'jumlah' => $item['nominal'], 'tanggal_bayar' => $item['tgl'],
                            'keterangan' => 'Saldo Awal (Import)', 'created_by' => Auth::id(),
                        ]);
                        $rincian->update(['status' => 'lunas', 'pembayaran_konsumen_id' => $pembayaran->id]);
                    }
                }

                // Pencairan KPR (ledger bebas, bukan berkaitan pembayaran konsumen).
                if ($this->isKpr && $cairBayar && $cairBayar > 0) {
                    $trx->pencairanKprTahaps()->create([
                        'nominal' => $cairBayar, 'tanggal_cair' => $cairTgl,
                        'keterangan' => 'Saldo Awal (Import)', 'created_by' => Auth::id(),
                    ]);
                }

                // Checklist dokumen dari Template Pemberkasan.
                foreach ($docStatuses as $namaDok => $d) {
                    DokumenKonsumen::create([
                        'kavling_konsumen_id' => $trx->id, 'nama_dokumen' => $namaDok,
                        'sifat' => $d['sifat'], 'status' => $d['status'],
                    ]);
                }

                // Status kavling: mengikuti aturan sama seperti alur booking biasa.
                $kavling->update(['status_jual' => in_array($tahapKey, ['akad', 'bast'], true) ? StatusJual::Sold : StatusJual::Booked]);

                // Kalau seluruh piutang konsumen & bank sudah lunas, langsung tandai Selesai
                // (sama seperti tombol "Tandai Selesai" manual) — supaya transaksi lama yang
                // sudah benar-benar tuntas otomatis terkunci, tidak perlu diklik satu-satu.
                $trx->load(['jadwalTagihans.pembayaran', 'biayaTambahans.pembayarans', 'rincianBiayaAkad.pembayaran', 'skemaDpPreset', 'pembayarans', 'pencairanKprTahaps']);
                $breakdown = $trx->kartuPiutangBreakdown();
                if ($breakdown['total_terbayar_konsumen'] >= $breakdown['total_piutang_konsumen']
                    && $breakdown['total_terbayar_bank'] >= $breakdown['total_piutang_bank']) {
                    $trx->update(['status' => 'completed']);
                }

                $this->result->imported++;
            }));
        } catch (\Throwable $e) {
            $this->result->skip($this->sheetLabel, $rowNum, "Error tak terduga — {$e->getMessage()}");
        }
    }

    private function parseAngka($value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (float) $value;
        $str = preg_replace('/[^\d,.\-]/', '', (string) $value);
        if ($str === '' || $str === '-') return null;
        if (str_contains($str, '.') && str_contains($str, ',')) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } elseif (substr_count($str, '.') > 1) {
            $str = str_replace('.', '', $str);
        } elseif (str_contains($str, ',') && !str_contains($str, '.')) {
            $str = str_replace(',', '', $str);
        }
        return is_numeric($str) ? (float) $str : null;
    }

    /** @return array{0: ?Carbon, 1: ?string} [tanggal, pesan error kalau format tak dikenali] */
    private function parseTanggal($value): array
    {
        if ($value === null || $value === '') return [null, null];
        if ($value instanceof \DateTimeInterface) return [Carbon::instance($value), null];
        if (is_numeric($value)) {
            try {
                return [Carbon::instance(ExcelDate::excelToDateTimeObject($value)), null];
            } catch (\Throwable) {
                return [null, 'format tanggal tidak dikenali'];
            }
        }
        $str = trim((string) $value);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $fmt) {
            try {
                $c = Carbon::createFromFormat($fmt, $str);
                if ($c) return [$c->startOfDay(), null];
            } catch (\Throwable) {
                // coba format berikutnya
            }
        }
        try {
            return [Carbon::parse($str)->startOfDay(), null];
        } catch (\Throwable) {
            return [null, "'{$str}' bukan format tanggal yang dikenali"];
        }
    }
}
