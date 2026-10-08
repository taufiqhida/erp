<?php

namespace App\Imports\Konsumen;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Models\CancellationRequest;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\Project;
use App\Models\SkemaDpPreset;
use App\Support\KonsumenImportSpec;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Sheet "Batal" Import Konsumen: konsumen yang sudah batal (riwayat & audit), termasuk uang yang
 * sempat masuk dan hangus. Hasilnya SAMA dengan pembatalan lewat sistem yang sudah disetujui:
 * transaksi `cancelled`/`batal`, 1 pembayaran sintetis "Saldo Awal (Import)", dan 1 catatan
 * pembatalan berstatus disetujui (nominal diterima/dikembalikan/hangus) — sehingga muncul di tab
 * Pembatalan dan uang hangusnya otomatis dihitung Dashboard.
 *
 * Status kavling TIDAK dicek dan TIDAK diubah: unit boleh sudah terisi konsumen lain atau masih kosong.
 * Baris yang sama (nama + unit + tanggal booking, sudah batal) dilewati supaya impor ulang tidak dobel.
 * Hanya pengguna dengan izin `review cancellation` yang boleh memakai sheet ini.
 */
class KonsumenBatalSheetImport implements ToCollection, SkipsEmptyRows
{
    use ParsesImportCells;

    private array $columns;

    public function __construct(
        private Project $project,
        private string $sheetLabel,
        private KonsumenImportResult $result,
        private bool $diizinkan,
    ) {
        $this->columns = KonsumenImportSpec::batalColumns();
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
        if (!$this->diizinkan) {
            $this->result->skip($this->sheetLabel, $rowNum, 'Anda tidak punya izin mengimpor konsumen batal (butuh izin review pembatalan).');
            return;
        }

        $v = [];
        foreach ($this->columns as $i => [$key]) {
            $raw = $cells[$i] ?? null;
            $v[$key] = is_string($raw) ? trim($raw) : $raw;
        }
        $str = fn (string $k) => $v[$k] !== null && $v[$k] !== '' ? (string) $v[$k] : null;

        $errors = [];
        $dateOf = function (string $k) use (&$errors, $v) {
            [$val, $err] = $this->parseTanggal($v[$k] ?? null);
            if ($err) $errors[] = "{$k}: {$err}";
            return $val;
        };

        $nama = $str('nama');
        if (!$nama) $errors[] = 'Nama Konsumen kosong.';

        $kluster = $str('kluster') ?? '';
        $blok = $str('blok');
        $unit = $str('unit');
        if (!$blok) $errors[] = 'Blok kosong.';
        if (!$unit) $errors[] = 'Nomor Kavling kosong.';

        $tglBooking = $dateOf('tgl_booking');
        if (!$tglBooking) $errors[] = 'Tanggal Booking kosong/format tidak dikenali.';
        $tglBatal = $dateOf('tgl_batal');
        if (!$tglBatal) $errors[] = 'Tanggal Batal kosong/format tidak dikenali.';
        $tglBayar = $dateOf('tgl_bayar');
        if ($tglBooking && $tglBatal && $tglBatal->lt($tglBooking)) {
            $errors[] = 'Tanggal Batal lebih awal dari Tanggal Booking.';
        }
        if ($tglBayar && $tglBatal && $tglBayar->gt($tglBatal)) {
            $errors[] = 'Tanggal Bayar Terakhir melewati Tanggal Batal.';
        }

        // ── Uang: Total Dibayar wajib, Dikembalikan opsional, Hangus = selisih ──
        $totalRaw = $v['total_bayar'];
        $total = $this->parseAngka($totalRaw);
        if ($totalRaw === null || $totalRaw === '') {
            $errors[] = 'Total Dibayar kosong (isi 0 kalau memang tidak ada pembayaran).';
        } elseif ($total === null || $total < 0) {
            $errors[] = "Total Dibayar '{$totalRaw}' bukan angka yang valid.";
        }
        $dikembalikanRaw = $v['dikembalikan'];
        $dikembalikan = 0.0;
        if ($dikembalikanRaw !== null && $dikembalikanRaw !== '') {
            $parsed = $this->parseAngka($dikembalikanRaw);
            if ($parsed === null || $parsed < 0) {
                $errors[] = "Dikembalikan '{$dikembalikanRaw}' bukan angka yang valid.";
            } else {
                $dikembalikan = $parsed;
            }
        }
        if ($total !== null && $dikembalikan > $total) {
            $errors[] = 'Dikembalikan melebihi Total Dibayar.';
        }

        $hargaDeal = null;
        if ($v['harga_deal'] !== null && $v['harga_deal'] !== '') {
            $hargaDeal = $this->parseAngka($v['harga_deal']);
            if ($hargaDeal === null || $hargaDeal < 0) $errors[] = "Harga Deal '{$v['harga_deal']}' bukan angka yang valid.";
        }

        // ── Cara bayar & skema (opsional; kalau diisi harus dikenal) ──
        $caraBayar = null;
        if ($caraLabel = $str('cara_bayar')) {
            $caraBayar = array_search(mb_strtolower($caraLabel), array_map('mb_strtolower', KonsumenImportSpec::CARA_BAYAR_SHEETS), true) ?: null;
            if (!$caraBayar) $errors[] = "Cara Bayar '{$caraLabel}' tidak dikenal.";
        }
        $skemaPreset = null;
        if ($skemaLabel = $str('skema')) {
            $q = SkemaDpPreset::whereRaw('LOWER(nama) = ?', [mb_strtolower($skemaLabel)]);
            if ($caraBayar) $q->where('cara_bayar', $caraBayar);
            $found = $q->get();
            if ($found->isEmpty()) {
                $errors[] = "Skema Pembayaran '{$skemaLabel}' tidak dikenal" . ($caraBayar ? ' untuk cara bayar ini.' : '.');
            } elseif ($found->count() > 1) {
                $errors[] = "Skema Pembayaran '{$skemaLabel}' ada di lebih dari satu cara bayar — isi kolom Cara Bayar.";
            } else {
                $skemaPreset = $found->first();
                $caraBayar ??= $skemaPreset->cara_bayar;
            }
        }

        // ── Unit: harus ada; statusnya sengaja TIDAK dicek ──
        $kavling = null;
        if ($blok && $unit) {
            $kavling = Kavling::where('project_id', $this->project->id)
                ->where('kluster_key', $kluster)
                ->where('blok_key', $blok)
                ->where('nomor_kavling', $unit)
                ->first();
            if (!$kavling) {
                $unitLabel = ($kluster !== '' ? "{$kluster} · " : '') . "{$blok}-{$unit}";
                $errors[] = "Unit '{$unitLabel}' tidak ditemukan di Stok Kavling proyek ini.";
            }
        }

        // ── Sudah pernah diimpor? (nama + unit + tanggal booking, sudah batal) ──
        if (empty($errors) && $kavling && $this->sudahAda($kavling, $nama, $tglBooking)) {
            $errors[] = 'Sudah ada konsumen batal yang sama (nama, unit, dan tanggal booking) — dilewati agar tidak dobel.';
        }

        if (!empty($errors)) {
            $this->result->skip($this->sheetLabel, $rowNum, implode(' | ', array_unique($errors)));
            return;
        }

        $nik = $str('nik');
        $alasan = $str('alasan') ?? 'Import data lama';
        $rincian = $str('rincian');
        $hangus = $total - $dikembalikan;

        try {
            KavlingKonsumen::withoutFinanceRefresh(fn () => DB::transaction(function () use (
                $nama, $nik, $str, $kavling, $tglBooking, $tglBatal, $tglBayar, $caraBayar, $skemaPreset,
                $hargaDeal, $total, $dikembalikan, $hangus, $alasan, $rincian,
            ) {
                $konsumen = $nik ? Konsumen::where('nik', $nik)->first() : null;
                if (!$konsumen) {
                    $konsumen = Konsumen::create([
                        'nama' => $nama,
                        'nik' => $nik,
                        'no_hp' => $str('hp'),
                    ]);
                }

                $trx = KavlingKonsumen::create([
                    'kavling_id' => $kavling->id,
                    'konsumen_id' => $konsumen->id,
                    'status' => 'cancelled',
                    'status_penjualan' => 'batal',
                    'tanggal_booking' => $tglBooking,
                    'harga_deal' => $hargaDeal,
                    'cara_bayar' => $caraBayar,
                    'skema_dp_preset_id' => $skemaPreset?->id,
                    'created_by' => Auth::id(),
                ]);

                if ($total > 0) {
                    $trx->pembayarans()->create([
                        'jenis' => 'uang_masuk_batal',
                        'jumlah' => $total,
                        'tanggal_bayar' => $tglBayar ?? $tglBooking,
                        'keterangan' => 'Saldo Awal (Import)',
                        'created_by' => Auth::id(),
                    ]);
                }

                CancellationRequest::create([
                    'type' => CancellationRequestType::Cancellation,
                    'kavling_id' => $kavling->id,
                    'kavling_konsumen_id' => $trx->id,
                    'requested_by' => Auth::id(),
                    'reviewed_by' => Auth::id(),
                    'status' => CancellationStatus::Approved,
                    'alasan' => $alasan,
                    'catatan_reviewer' => $rincian,
                    'reviewed_at' => $tglBatal,
                    'nominal_diterima' => $total,
                    'nominal_dikembalikan' => $dikembalikan,
                    'nominal_hangus' => $hangus,
                ]);

                $this->result->importedBatal++;
            }));
        } catch (\Throwable $e) {
            $this->result->skip($this->sheetLabel, $rowNum, "Error tak terduga — {$e->getMessage()}");
        }
    }

    private function sudahAda(Kavling $kavling, string $nama, $tglBooking): bool
    {
        return KavlingKonsumen::where('kavling_id', $kavling->id)
            ->where('status', 'cancelled')
            ->whereDate('tanggal_booking', $tglBooking->toDateString())
            ->whereHas('konsumen', fn ($q) => $q->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)]))
            ->exists();
    }
}
