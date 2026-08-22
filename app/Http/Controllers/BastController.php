<?php

namespace App\Http\Controllers;

use App\Enums\StatusBangun;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\BastRecord;
use App\Models\KavlingKonsumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BastController extends Controller
{
    use AuthorizesProjectAccess;

    /**
     * Simpan/perbarui data BAST. Transisi transaksi ke stage 'bast' (Selesai)
     * TIDAK otomatis di sini — itu lewat confirmSelesai() supaya staff sadar
     * betul saat menandai transaksi selesai penuh.
     */
    public function update(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);

        $validated = $request->validate([
            'tanggal_bast' => 'nullable|date',
            'catatan'      => 'nullable|string',
            'status_ttd'   => 'required|in:belum_ttd,sudah_ttd',
        ]);

        BastRecord::updateOrCreate(
            ['kavling_konsumen_id' => $kk->id],
            [
                ...$validated,
                'created_by' => $kk->bastRecord?->created_by ?? Auth::id(),
            ]
        );

        return back()->with('success', 'Data BAST berhasil disimpan.');
    }

    /**
     * Konfirmasi transaksi Selesai. Syarat: bangunan sudah siap serah terima
     * (status_bangun kavling), form BAST sudah ditandatangani, dan seluruh
     * Kartu Piutang booking_fee/DP sudah lunas (dipindah dari gerbang Proses
     * Bank — lihat BookingController::updateStatus()).
     */
    public function confirmSelesai(KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('update status penjualan'), 403);

        $bast = $kk->bastRecord;
        abort_unless(
            $kk->kavling->status_bangun === StatusBangun::HandoverReady,
            422,
            'Bangunan belum berstatus "Siap Serah Terima".'
        );
        abort_unless(
            $bast && $bast->status_ttd === 'sudah_ttd',
            422,
            'Form BAST harus sudah ditandatangani sebelum bisa dikonfirmasi Selesai.'
        );
        abort_unless(
            $kk->dp_lunas,
            422,
            'DP harus lunas terlebih dahulu sebelum transaksi bisa dikonfirmasi Selesai.'
        );

        $kk->update([
            'status_penjualan' => 'bast',
            'tanggal_bast'     => $bast->tanggal_bast ?? now(),
        ]);

        return back()->with('success', 'Transaksi berhasil dikonfirmasi Selesai.');
    }
}
