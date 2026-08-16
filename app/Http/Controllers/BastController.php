<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\BastRecord;
use App\Models\KavlingKonsumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BastController extends Controller
{
    use AuthorizesProjectAccess;

    /**
     * Simpan/perbarui checklist & data BAST. Kalau checklist lengkap dan
     * sudah tanda tangan, transaksi otomatis maju ke stage 'bast'.
     */
    public function update(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);

        $validated = $request->validate([
            'tanggal_bast' => 'nullable|date',
            'penerima'     => 'nullable|string|max:100',
            'catatan'      => 'nullable|string',
            'status_ttd'   => 'required|in:belum_ttd,sudah_ttd',
            'checklist'    => 'required|array',
            'checklist.*'  => 'boolean',
        ]);

        DB::transaction(function () use ($kk, $validated) {
            $bast = BastRecord::updateOrCreate(
                ['kavling_konsumen_id' => $kk->id],
                [
                    ...$validated,
                    'created_by' => $kk->bastRecord?->created_by ?? Auth::id(),
                ]
            );

            $isComplete = $bast->checklist_complete && $validated['status_ttd'] === 'sudah_ttd';

            if ($isComplete && $kk->status_penjualan !== 'bast') {
                $kk->update([
                    'status_penjualan' => 'bast',
                    'tanggal_bast'     => $validated['tanggal_bast'] ?? now(),
                ]);
            }
        });

        return back()->with('success', 'Data BAST berhasil disimpan.');
    }

    public function uploadDokumen(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);

        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $bast = $kk->bastRecord ?? BastRecord::create(['kavling_konsumen_id' => $kk->id, 'checklist' => [], 'created_by' => Auth::id()]);

        if ($bast->dokumen_path) {
            Storage::disk('public')->delete($bast->dokumen_path);
        }

        $path = $request->file('file')->store("bast/{$kk->id}", 'public');
        $bast->update(['dokumen_path' => $path]);

        return back()->with('success', 'Dokumen BAST berhasil diupload.');
    }
}
