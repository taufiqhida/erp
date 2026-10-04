<?php

namespace App\Http\Controllers\Concerns;

use App\Models\KavlingKonsumen;
use Illuminate\Support\Facades\Auth;

/**
 * Lock permanen begitu transaksi ditandai Selesai (bukan otomatis saat
 * Akad, lihat KavlingKonsumen::getIsLockedAttribute()): pemberkasan,
 * rincian biaya akad, & riwayat pembayaran dianggap final. Pemegang
 * permission 'override transaction lock' (Superadmin/SPV/Leader) tetap bisa
 * mengedit langsung (tanpa tombol "unlock" terpisah), tapi setiap override
 * dicatat ke activity log untuk jejak audit.
 */
trait ChecksTransactionLock
{
    protected function assertTransactionEditable(KavlingKonsumen $kk, string $context): void
    {
        if (!$kk->is_locked) {
            return;
        }

        abort_unless(
            Auth::user()->can('override transaction lock'),
            403,
            'Transaksi ini sudah terkunci sejak ditandai Selesai. Hanya pengguna dengan wewenang override yang bisa mengubah data ini.'
        );

        activity()
            ->causedBy(Auth::user())
            ->performedOn($kk)
            ->withProperties(['context' => $context, 'status_penjualan' => $kk->status_penjualan])
            ->log("Override edit data terkunci (transaksi selesai) oleh " . Auth::user()->name . " [{$context}]");
    }
}
