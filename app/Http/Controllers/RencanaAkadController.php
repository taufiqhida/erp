<?php

namespace App\Http\Controllers;

use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\NotarisPreset;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daftar kerja penjadwalan akad: semua transaksi yang sedang di tahap Rencana Akad.
 * Sengaja hanya daftar baca — mengubah tanggal/notaris & memindah tahap tetap di
 * halaman Kelola Dokumen (satu-satunya tempat logika transisi tahap).
 * Semua baris dimuat sekaligus (jumlahnya dibatasi tahap ini), jadi sort/filter di browser.
 */
class RencanaAkadController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Konsumen::class);

        $user = Auth::user();
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);
        $projectId = session('current_project_id');

        $rows = KavlingKonsumen::query()
            ->with(['konsumen:id,nama,no_hp', 'kavling.project:id,nama', 'notarisPreset:id,nama'])
            ->where('status', 'active')
            ->where('status_penjualan', 'rencana_akad')
            ->whereHas('kavling', function ($q) use ($isGlobal, $projectId, $user) {
                if ($projectId) $q->where('project_id', $projectId);
                if (!$isGlobal) {
                    $q->whereHas('project.users', fn ($q2) => $q2->where('users.id', $user->id));
                }
            })
            ->get()
            ->map(function ($kk) {
                $akad = $kk->tanggal_rencana_akad;
                $expired = $kk->tanggal_expired_sp3k;
                $isKpr = in_array($kk->cara_bayar, ['kpr_subsidi', 'kpr_komersil'], true);

                // Peringatan SP3K hanya relevan untuk KPR: sudah kedaluwarsa, atau akan
                // kedaluwarsa sebelum tanggal akad yang direncanakan.
                $sp3k = null;
                if ($isKpr && $expired) {
                    if ($expired->isPast()) $sp3k = 'sudah_expired';
                    elseif ($akad && $expired->lt($akad)) $sp3k = 'sebelum_akad';
                }

                return [
                    'id'               => $kk->id,
                    'konsumen_nama'    => $kk->konsumen->nama,
                    'konsumen_no_hp'   => $kk->konsumen->no_hp,
                    'kavling_nomor'    => $kk->kavling->nomor_lengkap,
                    'project_nama'     => $kk->kavling->project->nama,
                    'cara_bayar'       => $kk->cara_bayar,
                    'bank'             => $kk->bank_rekanan_kpr,
                    'notaris_id'       => $kk->notaris_preset_id,
                    'notaris_nama'     => $kk->notarisPreset?->nama,
                    'tanggal_akad'     => $akad?->format('Y-m-d'),
                    'tanggal_akad_label' => $akad?->format('d M Y'),
                    'sisa_hari'        => $akad ? (int) now()->startOfDay()->diffInDays($akad, false) : null,
                    'sp3k_expired'     => $expired?->format('d M Y'),
                    'sp3k_warning'     => $sp3k,
                    'sisa_piutang'     => (float) $kk->fin_sisa_konsumen,
                ];
            })
            ->values();

        return Inertia::render('RencanaAkad/Index', [
            'rows'           => $rows,
            'notarisOptions' => NotarisPreset::ordered()->get(['id', 'nama']),
        ]);
    }
}
