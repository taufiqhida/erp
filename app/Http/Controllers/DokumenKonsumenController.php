<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Http\Controllers\Concerns\ChecksTransactionLock;
use App\Models\BankRekananPreset;
use App\Models\CancellationRequest;
use App\Models\DokumenKonsumen;
use App\Models\KavlingKonsumen;
use App\Models\NotarisPreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DokumenKonsumenController extends Controller
{
    use AuthorizesProjectAccess, ChecksTransactionLock;

    /**
     * List dokumen per transaksi
     */
    public function index(KavlingKonsumen $kk): Response
    {
        $this->authorizeProjectAccess($kk->kavling->project);

        $kk->load(['konsumen', 'kavling.project', 'kavling.statusBangunStage', 'dokumens.verifiedBy', 'bastRecord', 'jadwalTagihans']);

        $pendingRequest = CancellationRequest::where('kavling_konsumen_id', $kk->id)->pending()->first();

        return Inertia::render('DokumenKonsumen/Show', [
            'transaksi'  => [
                'id'               => $kk->id,
                'status_penjualan' => $kk->status_penjualan,
                'status_penjualan_label' => $kk->status_penjualan_label,
                'cara_bayar'       => $kk->cara_bayar,
                'cara_bayar_label' => match($kk->cara_bayar) {
                    'cash'          => 'Cash',
                    'cash_bertahap' => 'Cash Bertahap',
                    'kpr_subsidi'   => 'KPR Subsidi',
                    'kpr_komersil'  => 'KPR Komersil',
                    default         => $kk->cara_bayar ?? '-',
                },
                'harga_deal'       => $kk->harga_deal,
                'progress_berkas'  => $kk->progress_berkas,
                'tanggal_booking'      => $kk->tanggal_booking?->format('Y-m-d'),
                'tanggal_rencana_akad' => $kk->tanggal_rencana_akad?->format('Y-m-d'),
                'notaris_preset_id'    => $kk->notaris_preset_id,
                'tanggal_sp3k'         => $kk->tanggal_sp3k?->format('Y-m-d'),
                'tanggal_expired_sp3k' => $kk->tanggal_expired_sp3k?->format('Y-m-d'),
                'tanggal_akad'         => $kk->tanggal_akad?->format('Y-m-d'),
                'tanggal_bast'         => $kk->tanggal_bast?->format('Y-m-d'),
                'sp3k_expiry_status'   => $kk->sp3k_expiry_status,
                'can_update_status'    => Auth::user()->can('update status penjualan'),
                'dokumen_wajib_lengkap' => $kk->dokumen_wajib_lengkap,
                'dp_lunas'             => $kk->dp_lunas,
                'piutang_lunas'        => $kk->piutang_lunas,
                'is_locked'            => $kk->is_locked,
                'bank_rekanan_kpr'     => $kk->bank_rekanan_kpr,
                // Proses Bank
                'tanggal_pengajuan_bank' => $kk->tanggal_pengajuan_bank?->format('Y-m-d'),
                'tanggal_keputusan_bank' => $kk->tanggal_keputusan_bank?->format('Y-m-d'),
                'status_bank'            => $kk->status_bank,
                'status_bank_label'      => $kk->status_bank_label,
                'catatan_bank'           => $kk->catatan_bank,
                // SP3K
                'tanggal_sp3k'           => $kk->tanggal_sp3k?->format('Y-m-d'),
                'tanggal_expired_sp3k'   => $kk->tanggal_expired_sp3k?->format('Y-m-d'),
                'status_sp3k'            => $kk->status_sp3k,
                'status_sp3k_label'      => $kk->status_sp3k_label,
                'catatan_sp3k'           => $kk->catatan_sp3k,
                'plafon_kpr'             => $kk->plafon_kpr,
                'has_pending_request'    => (bool) $pendingRequest,
                'pending_request_type'   => $pendingRequest?->type->value,
            ],
            'konsumen'   => [
                'id'   => $kk->konsumen->id,
                'nama' => $kk->konsumen->nama,
                'no_hp'=> $kk->konsumen->no_hp,
            ],
            'kavling'    => [
                'id'                  => $kk->kavling->id,
                'project_id'          => $kk->kavling->project_id,
                'nomor_lengkap'       => $kk->kavling->nomor_lengkap,
                'project_nama'        => $kk->kavling->project->nama,
                'status_bangun_stage_id' => $kk->kavling->status_bangun_stage_id,
                'status_bangun_label' => $kk->kavling->status_bangun_label,
                'status_bangun_is_final' => $kk->kavling->bangun_selesai,
            ],
            'dokumens'   => $kk->dokumens->map(fn($d) => [
                'id'           => $d->id,
                'nama_dokumen' => $d->nama_dokumen,
                'sifat'        => $d->sifat,
                'sifat_label'  => $d->sifat_label,
                'status'       => $d->status,
                'status_label' => $d->status_label,
                'status_icon'  => $d->status_icon,
                'catatan'      => $d->catatan,
                'catatan_revisi'     => $d->catatan_revisi,
                'tanggal_verifikasi' => $d->tanggal_verifikasi?->format('d M Y H:i'),
                'verified_by'        => $d->verifiedBy?->name,
            ]),
            'bast' => $kk->bastRecord ? [
                'tanggal_bast' => $kk->bastRecord->tanggal_bast?->format('Y-m-d'),
                'catatan'      => $kk->bastRecord->catatan,
                'status_ttd'   => $kk->bastRecord->status_ttd,
            ] : null,
            'bankRekananPresets' => BankRekananPreset::where('is_active', true)->ordered()->get(['id', 'nama']),
            'notarisPresets'     => NotarisPreset::where('is_active', true)
                ->orWhere('id', $kk->notaris_preset_id)
                ->ordered()->get(['id', 'nama']),
        ]);
    }

    /**
     * Update status dokumen
     */
    public function updateStatus(Request $request, DokumenKonsumen $dok): RedirectResponse
    {
        $this->authorizeProjectAccess($dok->transaksi->kavling->project);
        abort_unless(Auth::user()->can('manage dokumen'), 403);
        $this->assertTransactionEditable($dok->transaksi, 'Update status dokumen');

        $validated = $request->validate([
            'status'  => 'required|in:belum_ada,sudah_ada,perlu_revisi,ditolak',
            'catatan' => 'nullable|string|max:500',
        ]);

        $isRevisionState = in_array($validated['status'], ['perlu_revisi', 'ditolak']);

        $dok->update([
            'status'             => $validated['status'],
            'catatan'            => $validated['catatan'] ?? $dok->catatan,
            'catatan_revisi'     => $isRevisionState ? ($validated['catatan'] ?? $dok->catatan_revisi) : $dok->catatan_revisi,
            'tanggal_verifikasi' => now(),
            'verified_by'        => Auth::id(),
            'updated_by'         => Auth::id(),
        ]);

        return back()->with('success', "Status dokumen '{$dok->nama_dokumen}' diperbarui.");
    }
}
