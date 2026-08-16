<?php

namespace App\Imports;

use App\Enums\StatusBangun;
use App\Enums\StatusJual;
use App\Models\Kavling;
use App\Models\Project;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Validators\Failure;

class KavlingImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    protected Project $project;
    public array $errors = [];
    public int $imported = 0;
    public int $skipped  = 0;

    public function __construct(Project $project)
    {
        $this->project = $project;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // +2 karena baris 1 adalah header

            $noUnit = trim((string) ($row['no_unit'] ?? $row['nomor_unit'] ?? ''));
            $blok   = trim((string) ($row['blok'] ?? ''));

            if (empty($noUnit)) {
                $this->errors[] = "Baris {$rowNum}: No Unit kosong, dilewati.";
                $this->skipped++;
                continue;
            }

            // Cek duplikasi
            $exists = Kavling::where('project_id', $this->project->id)
                ->where('nomor_kavling', $noUnit)
                ->exists();

            if ($exists) {
                $this->errors[] = "Baris {$rowNum}: No Unit '{$noUnit}' sudah ada, dilewati.";
                $this->skipped++;
                continue;
            }

            $statusUnitRaw = strtolower(trim((string) ($row['status_unit'] ?? 'available')));
            $statusUnit = in_array($statusUnitRaw, ['available', 'not_for_sale']) ? $statusUnitRaw : 'available';

            $harga = $this->parseAngka($row['harga'] ?? 0);
            $lb    = $this->parseAngka($row['lb'] ?? $row['luas_bangunan'] ?? 0);
            $lt    = $this->parseAngka($row['lt'] ?? $row['luas_tanah'] ?? 0);

            try {
                Kavling::create([
                    'project_id'    => $this->project->id,
                    'nomor_kavling' => $noUnit,
                    'blok'          => $blok ?: null,
                    'luas_bangunan' => $lb ?: null,
                    'luas_tanah'    => $lt ?: null,
                    'harga'         => $harga ?: null,
                    'keterangan'    => trim((string) ($row['keterangan'] ?? '')),
                    'status_unit'   => $statusUnit,
                    'status_jual'   => $statusUnit === 'not_for_sale' ? StatusJual::Hold : StatusJual::Available,
                    'status_bangun' => StatusBangun::NotStarted,
                ]);
                $this->imported++;
            } catch (\Exception $e) {
                $this->errors[] = "Baris {$rowNum}: Error – {$e->getMessage()}";
                $this->skipped++;
            }
        }
    }

    private function parseAngka(mixed $value): float
    {
        // Bersihkan format angka Indonesia (misal: 1.500.000 atau 1,500,000)
        $str = (string) $value;
        $str = preg_replace('/[^\d,.]/', '', $str);
        // Jika ada titik dan koma, anggap titik sebagai pemisah ribuan
        if (str_contains($str, '.') && str_contains($str, ',')) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } elseif (substr_count($str, '.') > 1) {
            $str = str_replace('.', '', $str);
        } elseif (str_contains($str, ',') && !str_contains($str, '.')) {
            $str = str_replace(',', '', $str);
        }
        return (float) $str;
    }
}
