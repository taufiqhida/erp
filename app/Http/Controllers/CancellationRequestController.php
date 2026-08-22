<?php

namespace App\Http\Controllers;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\CancellationRequest;
use App\Models\Kavling;
use App\Models\UnitSwapHistory;
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
        abort_unless($user->can('request cancellation') || $user->can('review cancellation') || $user->can('swap kavling'), 403);
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);

        $requests = CancellationRequest::with([
            'kavling.project',
            'kavlingBaru',
            'kavlingKonsumen.konsumen',
            'requester:id,name',
            'reviewer:id,name',
        ])
        ->when(!$isGlobal, fn($q) =>
            $q->whereHas('kavling.project.users', fn($q2) => $q2->where('users.id', $user->id))
        )
        // Proyek aktif (Halaman Utama Pilih Proyek) — kosong berarti mode
        // "Semua Proyek", data lintas-proyek (tetap dibatasi RBAC di atas).
        ->when(session('current_project_id'), fn($q) =>
            $q->whereHas('kavling', fn($q2) => $q2->where('project_id', session('current_project_id')))
        )
        ->when($request->status, fn($q) => $q->where('status', $request->status))
        ->when($request->type, fn($q) => $q->where('type', $request->type))
        ->orderByDesc('created_at')
        ->paginate(15)
        ->withQueryString()
        ->through(fn($r) => [
            'id'                    => $r->id,
            'type'                  => $r->type->value,
            'type_label'            => $r->type->label(),
            'status'                => $r->status->value,
            'status_label'          => $r->status->label(),
            'status_color'          => $r->status->color(),
            'alasan'                => $r->alasan,
            'catatan_reviewer'      => $r->catatan_reviewer,
            'reviewed_at'           => $r->reviewed_at?->format('d M Y H:i'),
            'created_at'            => $r->created_at->format('d M Y H:i'),
            'kavling'               => $r->kavling->nomor_lengkap,
            'kavling_baru'          => $r->kavlingBaru?->nomor_lengkap,
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
            'filters'  => $request->only(['status', 'type']),
            'statusOptions' => array_map(fn($s) => ['value' => $s->value, 'label' => $s->label()], CancellationStatus::cases()),
            'typeOptions'   => array_map(fn($t) => ['value' => $t->value, 'label' => $t->label()], CancellationRequestType::cases()),
        ]);
    }

    /**
     * Buat pengajuan — pembatalan ATAU tukar unit. Keduanya lewat alur formal
     * yang sama: pending → direview manajer → approve/reject. Bisa diajukan
     * dari tahap manapun setelah booking (bukan cuma setelah Akad/Sold).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type'                => 'required|in:cancellation,unit_swap',
            'kavling_id'          => 'required|exists:kavlings,id',
            'kavling_konsumen_id' => 'required|exists:kavling_konsumen,id',
            'kavling_baru_id'     => 'required_if:type,unit_swap|nullable|exists:kavlings,id',
            'alasan'              => 'required|string|min:10',
        ]);

        $type = CancellationRequestType::from($validated['type']);
        $user = Auth::user();
        abort_unless(
            $type === CancellationRequestType::Cancellation ? $user->can('request cancellation') : $user->can('swap kavling'),
            403
        );

        $kavling = Kavling::findOrFail($validated['kavling_id']);
        $this->authorizeProjectAccess($kavling->project);

        abort_unless(
            in_array($kavling->status_jual, [StatusJual::Booked, StatusJual::Sold]),
            422,
            'Pengajuan hanya bisa dibuat untuk kavling yang sedang dipesan/terjual.'
        );

        // Cek tidak ada pending request sebelumnya untuk transaksi ini
        // (lintas kedua tipe — tidak boleh ada 2 pengajuan aktif bersamaan).
        abort_if(
            CancellationRequest::where('kavling_konsumen_id', $validated['kavling_konsumen_id'])->pending()->exists(),
            422,
            'Sudah ada pengajuan yang sedang menunggu review untuk transaksi ini.'
        );

        if ($type === CancellationRequestType::UnitSwap) {
            $kavlingBaru = Kavling::findOrFail($validated['kavling_baru_id']);
            abort_if((int) $kavlingBaru->id === (int) $kavling->id, 422, 'Kavling baru harus berbeda dengan kavling saat ini.');
            abort_if($kavlingBaru->project_id !== $kavling->project_id, 422, 'Tukar unit hanya bisa diajukan ke kavling dalam proyek yang sama.');
            abort_if($kavlingBaru->status_jual !== StatusJual::Available, 422, 'Kavling tujuan tidak tersedia.');
        }

        DB::transaction(function () use ($kavling, $validated, $type) {
            CancellationRequest::create([
                'type'                  => $type,
                'kavling_id'            => $validated['kavling_id'],
                'kavling_baru_id'       => $validated['kavling_baru_id'] ?? null,
                'kavling_konsumen_id'   => $validated['kavling_konsumen_id'],
                'kavling_status_before' => $kavling->status_jual->value,
                'alasan'                => $validated['alasan'],
                'requested_by'          => Auth::id(),
                'status'                => CancellationStatus::Pending,
            ]);

            $kavling->update([
                'status_jual' => $type === CancellationRequestType::UnitSwap
                    ? StatusJual::SwapRequested
                    : StatusJual::CancellationRequested,
            ]);
        });

        return back()->with('success', $type === CancellationRequestType::UnitSwap
            ? 'Pengajuan tukar unit berhasil disubmit.'
            : 'Pengajuan pembatalan berhasil disubmit.');
    }

    /**
     * Approve: cancellation = kavling kembali available, transaksi di-cancel.
     * unit_swap = unit benar-benar ditukar (histori dicatat, transaksi pindah
     * ke kavling baru).
     */
    public function approve(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $this->authorizeProjectAccess($cancellationRequest->kavling->project);
        abort_unless(Auth::user()->can('review cancellation'), 403);
        abort_if(!$cancellationRequest->isPending(), 422, 'Request ini sudah diproses.');

        if ($cancellationRequest->isUnitSwap()) {
            $validated = $request->validate([
                'catatan_reviewer' => 'nullable|string',
            ]);

            DB::transaction(function () use ($cancellationRequest, $validated) {
                $kavlingLama = Kavling::whereKey($cancellationRequest->kavling_id)->lockForUpdate()->firstOrFail();
                $kavlingBaru = Kavling::whereKey($cancellationRequest->kavling_baru_id)->lockForUpdate()->firstOrFail();

                abort_if($kavlingBaru->status_jual !== StatusJual::Available, 422, 'Kavling tujuan sudah tidak tersedia.');

                UnitSwapHistory::create([
                    'kavling_konsumen_id' => $cancellationRequest->kavling_konsumen_id,
                    'kavling_lama_id'     => $kavlingLama->id,
                    'kavling_baru_id'     => $kavlingBaru->id,
                    'user_id'             => Auth::id(),
                    'alasan'              => $cancellationRequest->alasan,
                ]);

                $statusSebelum = StatusJual::from($cancellationRequest->kavling_status_before ?? StatusJual::Booked->value);

                $kavlingLama->update(['status_jual' => StatusJual::Available]);
                $kavlingBaru->update(['status_jual' => $statusSebelum]);
                $cancellationRequest->kavlingKonsumen->update(['kavling_id' => $kavlingBaru->id]);

                $cancellationRequest->update([
                    'status'           => CancellationStatus::Approved,
                    'reviewed_by'      => Auth::id(),
                    'reviewed_at'      => now(),
                    'catatan_reviewer' => $validated['catatan_reviewer'] ?? null,
                ]);
            });

            return back()->with('success', 'Tukar unit disetujui dan berhasil dilaksanakan.');
        }

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

            // Tandai transaksi sebagai cancelled — status_penjualan ikut
            // disinkronkan ke 'batal' (sama seperti jalur batal instan lama
            // di BookingController) supaya badge "Batal" yang sudah ada di
            // list Konsumen/Keuangan konsisten muncul, bukan nyisa status
            // pipeline lama (mis. masih "Proses Bank/SLIK") yang bikin
            // seolah transaksi masih aktif.
            $cancellationRequest->kavlingKonsumen->update([
                'status'           => 'cancelled',
                'status_penjualan' => 'batal',
            ]);
        });

        return back()->with('success', 'Pembatalan disetujui. Kavling kembali tersedia.');
    }

    /**
     * Reject: kavling kembali ke status sebelum pengajuan dibuat (bisa
     * booked atau sold, tergantung tahap transaksi saat pengajuan dibuat).
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

            $statusSebelum = StatusJual::from($cancellationRequest->kavling_status_before ?? StatusJual::Sold->value);
            $cancellationRequest->kavling->update(['status_jual' => $statusSebelum]);
        });

        return back()->with('success', $cancellationRequest->isUnitSwap()
            ? 'Pengajuan tukar unit ditolak.'
            : 'Pengajuan pembatalan ditolak.');
    }
}
