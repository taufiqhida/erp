<?php

namespace App\Http\Controllers;

use App\Models\Konsumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KonsumenController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Konsumen::class);

        $konsumens = Konsumen::query()
            ->when($request->search, fn($q) =>
                $q->where('nama', 'like', "%{$request->search}%")
                  ->orWhere('no_hp', 'like', "%{$request->search}%")
                  ->orWhere('nik', 'like', "%{$request->search}%")
            )
            ->when($request->project_id, fn($q) =>
                $q->whereHas('kavlingKonsumens.kavling', fn($q2) => $q2->where('project_id', $request->project_id))
            )
            ->withCount('kavlingKonsumens as transaksi_count')
            ->orderBy('nama')
            ->paginate(15)
            ->withQueryString()
            ->through(fn($k) => [
                'id'             => $k->id,
                'nama'           => $k->nama,
                'no_hp'          => $k->no_hp,
                'nik'            => $k->nik,
                'email'          => $k->email,
                'pekerjaan'      => $k->pekerjaan,
                'transaksi_count' => $k->transaksi_count,
            ]);

        $projects = \App\Models\Project::orderBy('nama')->get(['id', 'nama', 'kode']);

        return Inertia::render('Konsumens/Index', [
            'konsumens' => $konsumens,
            'projects'  => $projects,
            'filters'   => $request->only(['search', 'project_id']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Konsumen::class);

        return Inertia::render('Konsumens/Form', [
            'konsumen' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Konsumen::class);

        $validated = $request->validate([
            'nama'      => 'required|string|max:100',
            'nik'       => 'nullable|string|max:20|unique:konsumens,nik',
            'no_hp'     => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:100',
            'alamat'    => 'nullable|string',
            'pekerjaan' => 'nullable|string|max:100',
            'catatan'   => 'nullable|string',
            'drive_folder_link' => 'nullable|url|max:255',
        ]);

        $konsumen = Konsumen::create($validated);

        return redirect()->route('konsumens.index')
            ->with('success', "Konsumen {$konsumen->nama} berhasil ditambahkan.");
    }

    public function show(Konsumen $konsumen): Response
    {
        $this->authorize('view', $konsumen);

        $transaksis = $konsumen->kavlingKonsumens()
            ->with(['kavling.project:id,nama', 'dokumens', 'pembayarans'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($trx) => [
                'id'               => $trx->id,
                'kavling_nomor'    => $trx->kavling->nomor_lengkap,
                'project_nama'     => $trx->kavling->project->nama,
                'cara_bayar'       => $trx->cara_bayar,
                'cara_bayar_label' => match($trx->cara_bayar) {
                    'cash'          => 'Cash',
                    'cash_bertahap' => 'Cash Bertahap',
                    'kpr_subsidi'   => 'KPR Subsidi',
                    'kpr_komersil'  => 'KPR Komersil',
                    default         => $trx->cara_bayar ?? '-',
                },
                'harga_deal'       => $trx->harga_deal,
                'booking_fee'      => $trx->booking_fee,
                'status_penjualan' => $trx->status_penjualan,
                'progress_berkas'  => $trx->progress_berkas,
                'dokumens'         => $trx->dokumens->map(fn($d) => [
                    'id'           => $d->id,
                    'nama_dokumen' => $d->nama_dokumen,
                    'sifat'        => $d->sifat,
                    'sifat_label'  => $d->sifat_label,
                    'status'       => $d->status,
                    'status_label' => $d->status_label,
                    'status_icon'  => $d->status_icon,
                ]),
                'pembayarans' => $trx->pembayarans->map(fn($p) => [
                    'id'            => $p->id,
                    'jenis'         => $p->jenis,
                    'jenis_label'   => $p->jenis_label,
                    'jumlah'        => $p->jumlah,
                    'tanggal_bayar' => $p->tanggal_bayar?->format('d M Y'),
                    'keterangan'    => $p->keterangan,
                ]),
            ]);

        return Inertia::render('Konsumens/Show', [
            'konsumen'  => [
                'id'        => $konsumen->id,
                'nama'      => $konsumen->nama,
                'no_hp'     => $konsumen->no_hp,
                'nik'       => $konsumen->nik,
                'email'     => $konsumen->email,
                'alamat'    => $konsumen->alamat,
                'pekerjaan' => $konsumen->pekerjaan,
                'drive_folder_link' => $konsumen->drive_folder_link,
            ],
            'transaksis' => $transaksis,
        ]);
    }

    public function edit(Konsumen $konsumen): Response
    {
        $this->authorize('update', $konsumen);

        return Inertia::render('Konsumens/Form', [
            'konsumen' => [
                'id'        => $konsumen->id,
                'nama'      => $konsumen->nama,
                'nik'       => $konsumen->nik,
                'no_hp'     => $konsumen->no_hp,
                'email'     => $konsumen->email,
                'alamat'    => $konsumen->alamat,
                'pekerjaan' => $konsumen->pekerjaan,
                'catatan'   => $konsumen->catatan,
                'drive_folder_link' => $konsumen->drive_folder_link,
            ],
        ]);
    }

    public function update(Request $request, Konsumen $konsumen): RedirectResponse
    {
        $this->authorize('update', $konsumen);

        $validated = $request->validate([
            'nama'              => 'required|string|max:100',
            'nik'               => "nullable|string|max:20|unique:konsumens,nik,{$konsumen->id}",
            'no_hp'             => 'nullable|string|max:20',
            'email'             => 'nullable|email|max:100',
            'alamat'            => 'nullable|string',
            'pekerjaan'         => 'nullable|string|max:100',
            'catatan'           => 'nullable|string',
            'drive_folder_link' => 'nullable|url|max:255',
        ]);

        $konsumen->update($validated);

        return redirect()->route('konsumens.index')
            ->with('success', "Konsumen {$konsumen->nama} berhasil diperbarui.");
    }

    public function destroy(Konsumen $konsumen): RedirectResponse
    {
        $this->authorize('delete', $konsumen);

        $konsumen->delete();

        return redirect()->route('konsumens.index')
            ->with('success', 'Konsumen berhasil dihapus.');
    }
}
