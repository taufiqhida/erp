<?php

namespace App\Http\Controllers\Concerns;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export tabel ke Excel — dipakai halaman-halaman yang butuh snapshot
 * referensi offline (mis. kalau sistem sedang down). Ini SNAPSHOT, bukan
 * mode kerja offline: transaksi yang dicatat manual saat sistem down tetap
 * harus di-entry ulang manual ke sistem setelah online (mirip pola Saldo
 * Awal di Import Konsumen), tidak ada sinkronisasi otomatis.
 */
trait ExportsExcel
{
    /**
     * @param  string  $filename      Nama file unduhan, termasuk ".xlsx".
     * @param  string[]  $headers     Judul kolom (baris pertama, ditebalkan).
     * @param  iterable<array>  $rows Baris data, tiap baris array nilai urut sama dengan $headers.
     */
    protected function streamExcelExport(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray($headers, null, 'A1');
        $lastCol = $this->excelColumnLetter(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('7C3AED');
        $sheet->freezePane('A2');

        $rowNum = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, "A{$rowNum}");
            $rowNum++;
        }

        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimension($this->excelColumnLetter($i))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function excelColumnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = (int) (($index - $mod) / 26);
        }

        return $letter;
    }
}
