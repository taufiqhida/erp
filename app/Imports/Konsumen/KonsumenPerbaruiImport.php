<?php

namespace App\Imports\Konsumen;

use App\Models\Project;
use App\Support\KonsumenPerbaruiSpec;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Entry point Import mode Perbarui: hanya membaca sheet "Perbarui" (sheet lain seperti Petunjuk/Daftar dilewati). */
class KonsumenPerbaruiImport implements Import, WithMultipleSheets, SkipsUnknownSheets
{
    public KonsumenImportResult $result;

    public function __construct(private Project $project)
    {
        $this->result = new KonsumenImportResult();
    }

    public function sheets(): array
    {
        return [KonsumenPerbaruiSpec::SHEET => new KonsumenPerbaruiSheetImport($this->project, $this->result)];
    }

    public function onUnknownSheet($sheetName): void
    {
    }
}
