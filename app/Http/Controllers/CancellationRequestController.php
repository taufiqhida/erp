<?php

namespace App\Http\Controllers;

use App\Enums\CancellationStatus;
use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\CancellationRequest;
use App\Models\Kavling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CancellationRequestController extends Controller
{
    use AuthorizesProjectAccess;

    public function index(Request $request): Response
    {
        $user = Auth::user();
        abort_unless($user->can('request cancellation') || $user->can('review cancellation'), 403);
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);

        $requests = CancellationRequest::with([
            'kavling.project',
            'kavlingKonsumen.konsumen',
            'requester:id,name',
            'reviewer:id,name',
        ])
        ->when(!$isGlobal, fn($q) =>
            $q->whereHas('kavling.project.users', fn($q2) => $q2->where('users.id', $user->id))
        )
        ->when($request->status, fn($q) => $q->where('status', $request->status))
        ->orderByDesc('created_at')
        ->paginate(15)
        ->withQueryString()
        ->through(fn($r) => [
            'id'                    => $r->id,
            'status'                => $r->status->value,
            'status_label'          => $r->status->label(),
            'status_color'          => $r->status->color(),
            'alasan'                => $r->alasan,
            'catatan_reviewer'      => $r->catatan_reviewer,
            'reviewed_at'           => $r->reviewed_at?->format('d M Y H:i'),
            'created_at'            => $r->created_at->format('d M Y H:i'),
            'kavling'               => $r->kavling->nomor_lengkap,
            'project'               => $r->kavling->project->nama,
            'konsumen'              => $r->kavlingKonsumen->konsumen->nama,
            'requester'             => $r->requester->name,
            'reviewer'              => $r->reviewer?->name,
            'kavling_id'            => $r->kavling_id,
            'total_terbayar'        => $r->kavlingKonsumen->total_terbayar,
            'nominal_diterima'      => $r->nominal_diterima,
            'nominal_dikembalikan'  => $r->nominal_dikembalikan,
            'nominal_hangus'        => $r->nominal_hangus,
        ]);

        return Inertia::render('CancellationRequests/Index', [
            'requests' => $requests,
            'filters'  => $request->only(['status']),
            'statusOptions' => array_map(fn($s) => ['value' => $s->value, 'label' => $s->label()], CancellationStatus::cases()),
        ]);
    }

    /**
     * Buat pengajuan pembatalan dari kavling yang sudah terjual
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kavling_id'         => 'required|exists:kavlings,id',
            'kavling_konsumen_id' => 'required|exists:kavling_konsumen,id',
            'alasan'             => 'required|string|min:10',
        ]);

        abort_unless(Auth::user()->can('request cancellation'), 403);

        $kavling = Kavling::findOrFail($validated['kavling_id']);
        $this->authorizeProjectAccess($kavling->project);

        abort_if(
            $kavling->status_jual !== StatusJual::Sold,
            422,
            'Pembatalan hanya bisa diajukan untuk kavling berstatus Terjual.'
        );

        // Cek tidak ada pending request sebelumnya
        abort_if(
            CancellationRequest::where('kavling_id', $kavling->id)->pending()->exists(),
            422,
            'Sudah ada pengajuan pembatalan yang sedang menunggu review.'
        );

        DB::transaction(function () use ($kavling, $validated) {
            CancellationRequest::create([
                ...$validated,
                'requested_by' => Auth::id(),
                'status'       => CancellationStatus::Pending,
            ]);

            // Update status kavling ke cancellation_requested
            $kavling->update(['status_jual' => StatusJual::CancellationRequested]);
        });

        return back()->with('success', 'Pengajuan pembatalan berhasil disubmit.');
    }

    /**
     * Approve: kavling kembali available, transaksi di-cancel
     */
    public function approve(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $this->authorizeProjectAccess($cancellationRequest->kavling->project);
        abort_unless(Auth::user()->can('review cancellation'), 403);
        abort_if(!$cancellationRequest->isPending(), 422, 'Request ini sudah diproses.');

        $nominalDiterima = $cancellationRequest->kavlingKonsumen->total_terbayar;

        $validated = $request->validate([
            'catatan_reviewer'     => 'nullable|string',
            'nominal_dikembalikan' => "required|numeric|min:0|max:{$nominalDiterima}",
        ]);

        DB::transaction(function () use ($cancellationRequest, $validated, $nominalDiterima) {
            $cancellationRequest->update([
                'status'               => CancellationStatus::Approved,
                'reviewed_by'          => Auth::id(),
                'reviewed_at'          => now(),
                'catatan_reviewer'     => $validated['catatan_reviewer'] ?? null,
                'nominal_diterima'     => $nominalDiterima,
                'nominal_dikembalikan' => $validated['nominal_dikembalikan'],
                'nominal_hangus'       => $nominalDiterima - $validated['nominal_dikembalikan'],
            ]);

            // Kavling kembali available
            $cancellationRequest->kavling->update(['status_jual' => StatusJual::Available]);

            // Tandai transaksi sebagai cancelled
            $cancellationRequest->kavlingKonsumen->update(['status' => 'cancelled']);
        });

        return back()->with('success', 'Pembatalan disetujui. Kavling kembali tersedia.');
    }

    /**
     * Reject: kavling kembali ke status sold
     */
    public function reject(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $this->authorizeProjectAccess($cancellationRequest->kavling->project);
        abort_unless(Auth::user()->can('review cancellation'), 403);
        abort_if(!$cancellationRequest->isPending(), 422, 'Request ini sudah diproses.');

        $validated = $request->validate([
            'catatan_reviewer' => 'required|string|min:5',
        ]);

        DB::transaction(function () use ($cancellationRequest, $validated) {
            $cancellationRequest->update([
                'status'           => CancellationStatus::Rejected,
                'reviewed_by'      => Auth::id(),
                'reviewed_at'      => now(),
                'catatan_reviewer' => $validated['catatan_reviewer'],
            ]);

            // Kavling kembali ke sold
            $cancellationRequest->kavling->update(['status_jual' => StatusJual::Sold]);
        });

        return back()->with('success', 'Pengajuan pembatalan ditolak.');
    }
}
