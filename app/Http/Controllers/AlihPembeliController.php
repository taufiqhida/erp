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
 * Pengajuan Alih Pembeli (oleh Admin Sales). Disetujui/ditolak SPV/Leader lewat menu Pengajuan — lihat
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
        abort_if(CancellationRequest::where('kavling_konsumen_id', $kk->id)->where('type', '!=', CancellationRequestType::RincianChange->value)->pending()->exists(), 422, 'Sudah ada pengajuan yang sedang menunggu review untuk transaksi ini.');

        $lama = $kk->konsumen;
        $validated = $request->validate([
            'konsumen_baru_id'        => 'nullable|integer|exists:konsumens,id',
            // Pembeli baru: kolom wajib sama dengan form booking (nama, NIK, No. HP, pekerjaan, status pernikahan — dua yang terakhir
            // menentukan berkas kondisional yang berlaku). NPWP/email/alamat opsional. Sumber lead & sales mengikuti pembeli lama.
            'baru'                    => 'nullable|array',
            'baru.nama'               => 'required_without:konsumen_baru_id|nullable|string|max:100',
            'baru.nik'                => 'required_without:konsumen_baru_id|nullable|string|max:20',
            'baru.no_hp'              => 'required_without:konsumen_baru_id|nullable|string|max:20',
            'baru.npwp'               => Konsumen::npwpRules(),
            'baru.email'              => 'nullable|email|max:100',
            'baru.alamat'             => 'nullable|string|max:500',
            'baru.pekerjaan'          => 'required_without:konsumen_baru_id|nullable|in:' . implode(',', array_keys(Konsumen::jenisPekerjaanLabel())),
            'baru.status_pernikahan'  => 'required_without:konsumen_baru_id|nullable|in:' . implode(',', array_keys(Konsumen::statusPernikahanLabel())),
            'hubungan'                => 'required|in:' . implode(',', array_keys(AlihPembeliRiwayat::hubunganLabel())),
            'alasan'                  => 'required|string|min:10',
        ], [
            'baru.nama.required_without' => 'Pilih konsumen yang sudah terdaftar, atau isi nama pembeli baru.',
            'baru.nik.required_without'  => 'NIK pembeli baru wajib diisi.',
            'baru.no_hp.required_without' => 'No. HP pembeli baru wajib diisi.',
            'baru.pekerjaan.required_without' => 'Pilih pekerjaan pembeli baru.',
            'baru.status_pernikahan.required_without' => 'Pilih status pernikahan pembeli baru.',
            'alasan.min'                 => 'Alasan minimal 10 karakter.',
        ]);

        // Pembeli baru tidak boleh memakai identitas yang sama dengan pembeli lama (inti dari peralihan adalah pihak yang berbeda).
        $data = $validated['konsumen_baru_id'] ?? null ? Konsumen::findOrFail($validated['konsumen_baru_id'])->only(['id', 'nik', 'no_hp']) : ($validated['baru'] ?? []);
        $galat = [];
        if (($data['id'] ?? null) === $lama->id) $galat['konsumen_baru_id'] = 'Pembeli baru tidak boleh sama dengan pembeli saat ini.';
        if (filled($lama->nik) && ($data['nik'] ?? null) === $lama->nik) $galat['baru.nik'] = 'NIK sama dengan pembeli saat ini.';
        if (filled($lama->no_hp) && ($data['no_hp'] ?? null) === $lama->no_hp) $galat['baru.no_hp'] = 'No. HP sama dengan pembeli saat ini. Pembeli baru harus punya kontak sendiri.';
        // NIK yang sudah terdaftar (termasuk yang datanya sudah dihapus): arahkan memilih konsumen itu, jangan membuat ganda.
        if (!($validated['konsumen_baru_id'] ?? null) && filled($validated['baru']['nik'] ?? null)) {
            $ada = Konsumen::withTrashed()->where('nik', $validated['baru']['nik'])->first();
            if ($ada) {
                $galat['baru.nik'] = $ada->trashed()
                    ? "NIK ini pernah terdaftar atas nama {$ada->nama} (datanya sudah dihapus). Hubungi admin untuk memulihkannya."
                    : "NIK ini sudah terdaftar atas nama {$ada->nama}. Pilih konsumen itu di tab \"Konsumen terdaftar\".";
            }
        }
        if ($galat) throw ValidationException::withMessages($galat);

        DB::transaction(function () use ($kk, $validated) {
            // Sumber lead (dan keterangan referral) diwarisi dari pembeli lama: penjualannya tetap berasal dari kanal yang sama.
            $lama = $kk->konsumen;
            $baruId = $validated['konsumen_baru_id'] ?? Konsumen::create($validated['baru'] + [
                'sumber_lead_id' => $lama->sumber_lead_id, 'referral_keterangan' => $lama->referral_keterangan,
            ])->id;

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

        return back()->with('success', 'Pengajuan alih pembeli disubmit dan menunggu review SPV/Leader di menu Pengajuan.');
    }
}
