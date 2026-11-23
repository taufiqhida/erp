<?php

namespace App\Imports\Konsumen;

/**
 * Kolektor hasil dipakai bersama oleh semua sheet (KPR Subsidi/Komersil/
 * Cash/Cash Bertahap) dalam satu proses import, supaya ringkasannya gabungan
 * dari 4 sheet sekaligus (sama seperti KavlingImport, tapi lintas-sheet).
 */
class KonsumenImportResult
{
    public int $imported = 0;
    /** Konsumen batal (sheet "Batal") yang berhasil dicatat — dihitung terpisah dari $imported. */
    public int $importedBatal = 0;
    public int $skipped = 0;
    /** Mode Perbarui: baris yang cocok dengan transaksi tetapi tidak ada yang perlu diisi. */
    public int $tanpaPerubahan = 0;

    /** @var string[] Mode Perbarui: rincian perubahan per baris ("Baris 5 (A-3 · Nama): Notaris ← X"). */
    public array $perubahan = [];

    /** @var string[] */
    public array $errors = [];

    /** @var string[] Peringatan yang TIDAK memblokir (baris tetap diimpor), mis. kemungkinan konsumen ganda. */
    public array $warnings = [];

    public function warn(string $sheet, int $rowNum, string $message): void
    {
        $this->warnings[] = "[{$sheet}] Baris {$rowNum} (peringatan): {$message}";
    }

    public function skip(string $sheet, int $rowNum, string $message): void
    {
        $this->skipped++;
        $this->errors[] = "[{$sheet}] Baris {$rowNum}: {$message}";
    }
}
