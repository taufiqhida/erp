<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Http\Controllers\Concerns\ChecksTransactionLock;
use App\Models\CatatanTransaksi;
use App\Models\KavlingKonsumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Catatan transaksi (booking / pemberkasan): tambah, edit, tandai selesai — untuk pemegang pipeline/dokumen — dan hapus
 * khusus pemegang 'override transaction lock' (Superadmin/SPV/Leader). Semua perubahan tercatat di Audit Trail.
 */
class CatatanTransaksiController extends Controller
{
    use AuthorizesProjectAccess, ChecksTransactionLock;

    private function pastikanBolehKelola(KavlingKonsumen $kk, string $konteks): void
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(CatatanTransaksi::izin($kk, Auth::user())['tambah'], 403, 'Anda tidak berwenang mengelola catatan transaksi ini.');
        $this->assertTransactionEditable($kk, $konteks);
    }

    public function store(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->pastikanBolehKelola($kk, 'Tambah catatan');

        $validated = $request->validate([
            'jenis' => 'required|in:booking,pemberkasan',
            'isi'   => 'required|string|max:2000',
        ], ['isi.required' => 'Isi catatan tidak boleh kosong.']);

        CatatanTransaksi::tulis($kk, $validated['jenis'], $validated['isi']);

        return back()->with('success', 'Catatan ditambahkan.');
    }

    public function update(Request $request, CatatanTransaksi $catatan): RedirectResponse
    {
        $this->pastikanBolehKelola($catatan->transaksi, 'Edit catatan');

        $validated = $request->validate(['isi' => 'required|string|max:2000'], ['isi.required' => 'Isi catatan tidak boleh kosong.']);
        $catatan->update(['isi' => trim($validated['isi'])]);

        return back()->with('success', 'Catatan diperbarui.');
    }

    public function selesai(Request $request, CatatanTransaksi $catatan): RedirectResponse
    {
        $this->pastikanBolehKelola($catatan->transaksi, 'Tandai catatan selesai');

        $selesai = $request->validate(['selesai' => 'required|boolean'])['selesai'];
        $catatan->update([
            'selesai'           => $selesai,
            'diselesaikan_oleh' => $selesai ? Auth::id() : null,
            'diselesaikan_at'   => $selesai ? now() : null,
        ]);

        return back()->with('success', $selesai ? 'Catatan ditandai selesai.' : 'Catatan dibuka kembali.');
    }

    public function destroy(CatatanTransaksi $catatan): RedirectResponse
    {
        $this->authorizeProjectAccess($catatan->transaksi->kavling->project);
        abort_unless(CatatanTransaksi::izin($catatan->transaksi, Auth::user())['hapus'], 403, 'Hanya SPV, Leader, atau Superadmin yang bisa menghapus catatan.');

        $catatan->delete();

        return back()->with('success', 'Catatan dihapus.');
    }
}
