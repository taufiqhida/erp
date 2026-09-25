<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\KavlingKonsumen;
use App\Models\SuratTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SuratGenerateController extends Controller
{
    use AuthorizesProjectAccess;

    /**
     * Generate dokumen .docx dari template surat + data 1 transaksi, lalu
     * langsung download. File sementara di storage/app/tmp dihapus lagi
     * begitu response selesai dikirim (lihat deleteFileAfterSend).
     */
    public function generate(Request $request, KavlingKonsumen $kk, SuratTemplate $suratTemplate): BinaryFileResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('view konsumens'), 403);

        $validated = $request->validate([
            'nomor_surat'            => 'nullable|string|max:100',
            'nama_penandatangan'     => 'nullable|string|max:100',
            'jabatan_penandatangan'  => 'nullable|string|max:100',
        ]);

        $extra = array_filter($validated, fn($v) => $v !== null && $v !== '');
        $outputPath = $suratTemplate->generateDocx($kk, $extra);

        $downloadName = $suratTemplate->nama . ' - ' . $kk->konsumen->nama . '.docx';

        return response()->download($outputPath, $downloadName)->deleteFileAfterSend(true);
    }
}
