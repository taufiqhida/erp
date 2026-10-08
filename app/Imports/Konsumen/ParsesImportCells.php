<?php

namespace App\Imports\Konsumen;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** Pembaca sel Excel (angka & tanggal) yang dipakai bersama semua sheet Import Konsumen. */
trait ParsesImportCells
{
    protected function parseAngka($value): ?float
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
    protected function parseTanggal($value): array
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
