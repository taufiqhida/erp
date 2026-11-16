<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\BastRecord;
use App\Models\CatatanTransaksi;
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
        abort_unless(Auth::user()->can('kelola pipeline sales'), 403);

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
     * (status_bangun kavling), form BAST sudah ditandatangani, dan SEMUA tagihan
     * konsumen lunas (Booking Fee, DP/Pengikatan, Pelunasan, biaya tanah, tiap
     * biaya tambahan, Titipan Biaya Akad, Tambahan UM; sisi bank tidak ikut).
     * Rumah belum diserahterimakan kalau belum lunas — pengecualiannya hanya lewat
     * override pemegang izin 'override transaction lock' dengan alasan tercatat.
     */
    public function confirmSelesai(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        // Admin Sales mengonfirmasi BAST; SPV/Leader/Superadmin (pemegang override) juga boleh, supaya pengecualian pembayaran bisa
        // diputuskan oleh mereka tanpa harus meminjam akun Admin Sales.
        abort_unless(Auth::user()->can('kelola pipeline sales') || Auth::user()->can('override transaction lock'), 403);

        $bast = $kk->bastRecord;
        abort_unless(
            $kk->kavling->bangun_selesai,
            422,
            'Bangunan belum berstatus "Siap Serah Terima".'
        );
        abort_unless(
            $bast && $bast->status_ttd === 'sudah_ttd',
            422,
            'Form BAST harus sudah ditandatangani sebelum bisa dikonfirmasi Selesai.'
        );

        $belumLunas = $kk->komponenKonsumenBelumLunas();
        if ($belumLunas) {
            $ringkas = KavlingKonsumen::ringkasKomponenBelumLunas($belumLunas);
            abort_unless(
                Auth::user()->can('override transaction lock'),
                422,
                "Pembayaran konsumen belum lunas: {$ringkas}. Lunasi dulu, atau minta SPV/Leader/Superadmin mengonfirmasi dengan alasan."
            );
            $alasan = trim((string) $request->input('alasan_override', ''));
            abort_if(
                mb_strlen($alasan) < 5,
                422,
                "Pembayaran konsumen belum lunas: {$ringkas}. Isi alasan override (minimal 5 karakter) untuk tetap mengonfirmasi BAST."
            );

            CatatanTransaksi::tulis($kk, 'pemberkasan', "BAST dikonfirmasi walau pembayaran konsumen belum lunas ({$ringkas}). Alasan: {$alasan}", 'BAST');
            activity('transaksi')->performedOn($kk)
                ->withProperties(['komponen_belum_lunas' => $belumLunas, 'alasan' => $alasan])
                ->log("Override gerbang BAST: dikonfirmasi dengan pembayaran belum lunas ({$ringkas}). Alasan: {$alasan}");
        }

        $kk->update([
            'status_penjualan' => 'bast',
            'tanggal_bast'     => $bast->tanggal_bast ?? now(),
        ]);

        return back()->with('success', 'Transaksi berhasil dikonfirmasi Selesai.');
    }
}
