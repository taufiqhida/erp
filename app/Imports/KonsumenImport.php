<?php

namespace App\Imports;

use App\Imports\Konsumen\KonsumenBatalSheetImport;
use App\Imports\Konsumen\KonsumenImportResult;
use App\Imports\Konsumen\KonsumenSheetImport;
use App\Models\Project;
use App\Support\KonsumenImportSpec;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Entry point Import Konsumen — 1 sheet per cara bayar (lihat
 * KonsumenImportSpec::CARA_BAYAR_SHEETS), digabung jadi 1 ringkasan lewat
 * KonsumenImportResult, ditambah sheet "Batal" untuk konsumen yang sudah batal. Sheet yang tidak ada di file (mis. admin menghapus
 * sheet yang tidak dipakai) dilewati begitu saja oleh Maatwebsite.
 */
class KonsumenImport implements Import, WithMultipleSheets, SkipsUnknownSheets
{
    public KonsumenImportResult $result;

    public function __construct(private Project $project)
    {
        $this->result = new KonsumenImportResult();
    }

    public function sheets(): array
    {
        $sheets = [];
        foreach (KonsumenImportSpec::CARA_BAYAR_SHEETS as $caraBayar => $label) {
            $sheets[$label] = new KonsumenSheetImport($this->project, $caraBayar, $label, $this->result);
        }

        // Sheet "Batal" (riwayat konsumen batal): hanya untuk yang berhak mereview pembatalan.
        $sheets[KonsumenImportSpec::BATAL_SHEET] = new KonsumenBatalSheetImport(
            $this->project,
            KonsumenImportSpec::BATAL_SHEET,
            $this->result,
            (bool) Auth::user()?->can('review cancellation'),
        );

        return $sheets;
    }

    /** Sheet yang tidak ada di file (mis. dihapus karena tidak dipakai) dilewati, bukan error. */
    public function onUnknownSheet($sheetName): void
    {
    }
}
