<?php

namespace App\Http\Controllers;

use App\Enums\CancellationRequestType;
use App\Enums\CancellationStatus;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\AlihPembeliRiwayat;
use App\Models\CancellationRequest;
use App\Models\Konsumen;
use App\Models\KavlingKonsumen;
use App\Support\AlihPembeli;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengajuan Alih Pembeli (oleh Admin Sales). Disetujui/ditolak SPV/Leader lewat menu Pembatalan — lihat
 * CancellationRequestController::approve()/reject(), pelaksanaannya di App\Support\AlihPembeli.
 */
class AlihPembeliController extends Controller
{
    use AuthorizesProjectAccess;

    /** Cari konsumen terdaftar (nama/NIK/No. HP) untuk dipilih sebagai pembeli baru. */
    public function cari(Request $request, KavlingKonsumen $kk): JsonResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('ajukan alih pembeli'), 403);

        $q = trim((string) $request->query('q'));
        abort_if(mb_strlen($q) < 2, 422, 'Ketik minimal 2 huruf.');

        return response()->json(
            Konsumen::where('id', '!=', $kk->konsumen_id)
                ->where(fn ($w) => $w->where('nama', 'like', "%{$q}%")->orWhere('nik', 'like', "%{$q}%")->orWhere('no_hp', 'like', "%{$q}%"))
                ->orderBy('nama')->limit(15)->get(['id', 'nama', 'nik', 'no_hp'])
        );
    }

    public function store(Request $request, KavlingKonsumen $kk): RedirectResponse
    {
        $this->authorizeProjectAccess($kk->kavling->project);
        abort_unless(Auth::user()->can('ajukan alih pembeli'), 403);
        if ($alasan = AlihPembeli::alasanTidakBoleh($kk)) abort(422, $alasan);
        abort_if(CancellationRequest::where('kavling_konsumen_id', $kk->id)->pending()->exists(), 422, 'Sudah ada pengajuan yang sedang menunggu review untuk transaksi ini.');

        $lama = $kk->konsumen;
        $validated = $request->validate([
            'konsumen_baru_id'        => 'nullable|integer|exists:konsumens,id',
            'baru'                    => 'nullable|array',
            'baru.nama'               => 'required_without:konsumen_baru_id|nullable|string|max:100',
            'baru.nik'                => 'nullable|string|max:20|unique:konsumens,nik',
            'baru.no_hp'              => 'nullable|string|max:20',
            'baru.alamat'             => 'nullable|string',
            'baru.pekerjaan'          => 'nullable|in:' . implode(',', array_keys(Konsumen::jenisPekerjaanLabel())),
            'baru.status_pernikahan'  => 'nullable|in:' . implode(',', array_keys(Konsumen::statusPernikahanLabel())),
            'hubungan'                => 'required|in:' . implode(',', array_keys(AlihPembeliRiwayat::hubunganLabel())),
            'alasan'                  => 'required|string|min:10',
        ], [
            'baru.nama.required_without' => 'Pilih konsumen yang sudah terdaftar, atau isi nama pembeli baru.',
            'baru.nik.unique'            => 'NIK ini sudah terdaftar atas nama konsumen lain — cari dan pilih konsumen tersebut.',
            'alasan.min'                 => 'Alasan minimal 10 karakter.',
        ]);

        // Pembeli baru tidak boleh memakai identitas yang sama dengan pembeli lama (inti dari peralihan adalah pihak yang berbeda).
        $data = $validated['konsumen_baru_id'] ?? null ? Konsumen::findOrFail($validated['konsumen_baru_id'])->only(['id', 'nik', 'no_hp']) : ($validated['baru'] ?? []);
        $galat = [];
        if (($data['id'] ?? null) === $lama->id) $galat['konsumen_baru_id'] = 'Pembeli baru tidak boleh sama dengan pembeli saat ini.';
        if (filled($lama->nik) && ($data['nik'] ?? null) === $lama->nik) $galat['baru.nik'] = 'NIK sama dengan pembeli saat ini.';
        if (filled($lama->no_hp) && ($data['no_hp'] ?? null) === $lama->no_hp) $galat['baru.no_hp'] = 'No. HP sama dengan pembeli saat ini. Pembeli baru harus punya kontak sendiri.';
        if ($galat) throw ValidationException::withMessages($galat);

        DB::transaction(function () use ($kk, $validated) {
            $baruId = $validated['konsumen_baru_id'] ?? Konsumen::create($validated['baru'])->id;

            CancellationRequest::create([
                'type'                  => CancellationRequestType::BuyerTransfer,
                'kavling_id'            => $kk->kavling_id,
                'kavling_konsumen_id'   => $kk->id,
                'konsumen_id'           => $kk->konsumen_id,
                'konsumen_baru_id'      => $baruId,
                'hubungan'              => $validated['hubungan'],
                'kavling_status_before' => $kk->kavling->status_jual->value,   // status unit TIDAK diubah oleh pengajuan ini
                'alasan'                => $validated['alasan'],
                'requested_by'          => Auth::id(),
                'status'                => CancellationStatus::Pending,
            ]);
        });

        return back()->with('success', 'Pengajuan alih pembeli disubmit dan menunggu review SPV/Leader di menu Pembatalan.');
    }
}
