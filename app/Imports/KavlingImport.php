<?php

namespace App\Imports;

use App\Enums\StatusJual;
use App\Models\Kavling;
use App\Models\Project;
use App\Models\StatusBangunStage;
use App\Models\TipeUnitPreset;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

/**
 * Import bulk kavling dari Excel/CSV — kolom yang diterima persis sama
 * dengan template yang bisa diunduh dari tombol "Download Template" di
 * modal import (lihat ProjectController::downloadKavlingTemplate()).
 * Baris dengan data tidak valid di-skip dengan pesan error yang jelas,
 * bukan diam-diam diganti ke nilai default — supaya user tahu persis
 * baris mana yang perlu diperbaiki & diupload ulang.
 */
class KavlingImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    protected Project $project;
    public array $errors = [];
    public int $imported = 0;
    public int $skipped  = 0;

    /** Nomor kavling yang sudah diproses dalam batch file ini — supaya
     *  duplikat SESAMA baris di file yang sama juga ketahuan, bukan cuma
     *  duplikat terhadap data yang sudah ada di database. */
    private array $seenInBatch = [];

    public function __construct(Project $project)
    {
        $this->project = $project;
    }

    public function collection(Collection $rows): void
    {
        $statusJualValues = ['available', 'not_for_sale'];
        // Nama tahap status_bangun dicocokkan case-insensitive terhadap
        // master Kelola Status Bangun — bukan enum hardcode lagi.
        $stagesByName = StatusBangunStage::ordered()->get()->keyBy(fn($s) => strtolower($s->nama));
        $defaultStageId = StatusBangunStage::defaultStage()?->id;
        $stageNamesForError = $stagesByName->pluck('nama')->implode(', ');

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // +2 karena baris 1 adalah header

            $noUnit  = trim((string) ($row['nomor_kavling'] ?? $row['no_unit'] ?? $row['nomor_unit'] ?? ''));
            $blok    = trim((string) ($row['blok'] ?? ''));
            $kluster = trim((string) ($row['kluster'] ?? $row['cluster'] ?? ''));
            $tipe    = trim((string) ($row['tipe'] ?? $row['tipe_unit'] ?? $row['type'] ?? ''));
            $keterangan = trim((string) ($row['keterangan'] ?? ''));

            if (empty($noUnit)) {
                $this->errors[] = "Baris {$rowNum}: No Unit kosong, dilewati.";
                $this->skipped++;
                continue;
            }

            if (empty($tipe)) {
                $this->errors[] = "Baris {$rowNum}: Tipe Unit kosong (wajib diisi), dilewati.";
                $this->skipped++;
                continue;
            }

            if (isset($this->seenInBatch[$noUnit])) {
                $this->errors[] = "Baris {$rowNum}: No Unit '{$noUnit}' duplikat dengan baris {$this->seenInBatch[$noUnit]} di file yang sama, dilewati.";
                $this->skipped++;
                continue;
            }

            $exists = Kavling::where('project_id', $this->project->id)
                ->where('nomor_kavling', $noUnit)
                ->exists();

            if ($exists) {
                $this->errors[] = "Baris {$rowNum}: No Unit '{$noUnit}' sudah ada di proyek ini, dilewati.";
                $this->skipped++;
                continue;
            }

            // Status ketersediaan unit — cuma terima 2 nilai raw yang persis
            // sama seperti di template, TIDAK diam-diam di-fallback ke
            // 'available' kalau isinya salah ketik atau tidak dikenal.
            $statusInput = $row['status'] ?? $row['status_unit'] ?? 'available';
            $statusRaw = strtolower(trim((string) $statusInput));
            if (!in_array($statusRaw, $statusJualValues, true)) {
                $this->errors[] = "Baris {$rowNum}: Status '{$statusInput}' tidak dikenal (harus 'available' atau 'not_for_sale'), dilewati.";
                $this->skipped++;
                continue;
            }

            // Status progress bangun — opsional, default tahap "Belum Mulai"
            // kalau kosong (unit baru), tapi kalau diisi harus persis sama
            // nama salah satu tahap di master Kelola Status Bangun (case-
            // insensitive). Mengakomodasi proyek yang saat diimport sudah
            // berjalan setengah jalan (sebagian unit progressnya bukan dari nol).
            $statusBangunRaw = trim((string) ($row['status_bangun'] ?? ''));
            if ($statusBangunRaw === '') {
                $statusBangunStageId = $defaultStageId;
            } elseif ($stage = $stagesByName->get(strtolower($statusBangunRaw))) {
                $statusBangunStageId = $stage->id;
            } else {
                $this->errors[] = "Baris {$rowNum}: Status Bangun '{$statusBangunRaw}' tidak dikenal (harus salah satu: {$stageNamesForError}), dilewati.";
                $this->skipped++;
                continue;
            }

            $harga = $this->parseAngka($row['harga'] ?? 0);
            $lb    = $this->parseAngka($row['lb'] ?? $row['luas_bangunan'] ?? 0);
            $lt    = $this->parseAngka($row['lt'] ?? $row['luas_tanah'] ?? 0);

            $idRumah = trim((string) ($row['id_rumah'] ?? ''));
            if ($idRumah !== '' && Kavling::where('id_rumah', $idRumah)->exists()) {
                $this->errors[] = "Baris {$rowNum}: ID Rumah '{$idRumah}' sudah dipakai kavling lain, dilewati.";
                $this->skipped++;
                continue;
            }

            try {
                $tipePreset = TipeUnitPreset::firstOrCreate(
                    ['project_id' => $this->project->id, 'nama' => $tipe],
                    ['luas_tanah' => $lt ?: null, 'luas_bangunan' => $lb ?: null]
                );
                if ($tipePreset->wasRecentlyCreated) {
                    $this->errors[] = "Baris {$rowNum}: Tipe Unit '{$tipe}' belum ada di proyek ini, dibuat otomatis — lengkapi spek lengkapnya di halaman Kelola Tipe Unit.";
                }

                Kavling::create([
                    'project_id'    => $this->project->id,
                    'kluster'       => $kluster ?: null,
                    'nomor_kavling' => $noUnit,
                    'blok'          => $blok ?: null,
                    'tipe_unit_preset_id' => $tipePreset->id,
                    'harga'         => $harga ?: null,
                    'keterangan'    => $keterangan ?: null,
                    'status_unit'   => $statusRaw,
                    'status_jual'   => $statusRaw === 'not_for_sale' ? StatusJual::Hold : StatusJual::Available,
                    'status_bangun_stage_id' => $statusBangunStageId,
                    'id_rumah'      => $idRumah ?: null,
                ]);
                $this->seenInBatch[$noUnit] = $rowNum;
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
