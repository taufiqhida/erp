<?php

namespace App\Http\Controllers;

use App\Enums\StatusJual;
use App\Http\Controllers\Concerns\AuthorizesProjectAccess;
use App\Models\Kavling;
use App\Models\Project;
use App\Models\StatusBangunStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class KavlingController extends Controller
{
    use AuthorizesProjectAccess;


    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('create kavlings'), 403);

        $validated = $request->validate([
            'nomor_kavling'  => ['required', 'string', 'max:20', function ($attribute, $value, $fail) use ($request, $project) {
                if (Kavling::identitasExists($project->id, $request->input('kluster'), $request->input('blok'), (string) $value)) {
                    $fail('Unit dengan kluster, blok, dan nomor yang sama sudah ada di proyek ini.');
                }
            }],
            'tipe_unit_preset_id' => "required|exists:tipe_unit_presets,id,project_id,{$project->id}",
            'kluster'        => 'nullable|string|max:50',
            'blok'           => 'required|string|max:10',
            'harga'          => 'nullable|numeric|min:0',
            'status_jual'    => 'required|in:' . implode(',', StatusJual::values()),
            'status_bangun_stage_id' => 'required|exists:status_bangun_stages,id',
            'keterangan'     => 'nullable|string|max:255',
            'perlu_biaya_tambahan' => 'boolean',
            'catatan'        => 'nullable|string',
            'id_rumah'       => ['nullable', 'string', 'max:50', Rule::unique('kavlings', 'id_rumah')],
            'hgb_no'         => ['nullable', 'string', 'max:50', Rule::unique('kavlings', 'hgb_no')],
        ]);

        $validated['id_rumah'] = $validated['id_rumah'] ?: null;
        $validated['hgb_no'] = $validated['hgb_no'] ?: null;

        $kavling = $project->kavlings()->create($validated);

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} berhasil ditambahkan.");
    }

    public function update(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $validated = $request->validate([
            'nomor_kavling'  => ['required', 'string', 'max:20', function ($attribute, $value, $fail) use ($request, $kavling) {
                if (Kavling::identitasExists($kavling->project_id, $request->input('kluster'), $request->input('blok'), (string) $value, $kavling->id)) {
                    $fail('Unit dengan kluster, blok, dan nomor yang sama sudah ada di proyek ini.');
                }
            }],
            'tipe_unit_preset_id' => "required|exists:tipe_unit_presets,id,project_id,{$kavling->project_id}",
            'kluster'        => 'nullable|string|max:50',
            'blok'           => 'required|string|max:10',
            'harga'          => 'nullable|numeric|min:0',
            'keterangan'     => 'nullable|string|max:255',
            'perlu_biaya_tambahan' => 'boolean',
            'catatan'        => 'nullable|string',
            'id_rumah'       => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'id_rumah')->ignore($kavling->id),
            ],
            'hgb_no'         => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'hgb_no')->ignore($kavling->id),
            ],
        ]);

        $validated['id_rumah'] = $validated['id_rumah'] ?: null;
        $validated['hgb_no'] = $validated['hgb_no'] ?: null;

        $kavling->update($validated);

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} berhasil diperbarui.");
    }

    /** Status jual yang boleh diubah massal: unit yang sudah berproses transaksi tidak disentuh sama sekali. */
    private const STATUS_BOLEH_MASSAL = ['available', 'hold'];
    private const MAKS_UNIT_MASSAL = 2000;
    private const MAKS_BARIS_PRATINJAU = 300;

    /**
     * Edit massal unit: ganti harga (angka baru / naik-turun persen / naik-turun nominal) atau ganti Tipe Unit untuk banyak unit
     * sekaligus. Unit dipilih lewat `kavling_ids` atau `semua_hasil_filter` (+ filter yang sama dengan tabel). `simulasi` = hanya
     * hitung & tampilkan "lama → baru" tanpa menyimpan (JSON); tanpa itu perubahan disimpan dalam satu transaksi.
     * Unit Dipesan/Terjual/Pembatalan/Tukar dilewati (harga transaksi yang berjalan tidak boleh berubah diam-diam).
     * Luas tanah/bangunan melekat pada Tipe Unit, bukan unit, jadi ganti tipe otomatis mengganti luasnya.
     */
    public function editMassal(Request $request, Project $project): \Illuminate\Http\JsonResponse|RedirectResponse
    {
        $this->authorizeProjectAccess($project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        // Galat validasi untuk permintaan JSON dirender manual (aplikasi ini hanya merender JSON untuk api/*).
        $validator = Validator::make($request->all(), [
            'aksi'                   => 'required|in:harga,tipe',
            'mode'                   => 'required_if:aksi,harga|nullable|in:set,persen,nominal',
            'nilai'                  => 'required_if:aksi,harga|nullable|numeric|between:-100000000000,100000000000',
            'tipe_unit_preset_id'    => ['required_if:aksi,tipe', 'nullable', Rule::exists('tipe_unit_presets', 'id')->where('project_id', $project->id)->where('is_active', true)],
            'kavling_ids'            => 'nullable|array|max:' . self::MAKS_UNIT_MASSAL,
            'kavling_ids.*'          => 'integer|distinct',
            'semua_hasil_filter'     => 'nullable|boolean',
            'filter'                 => 'nullable|array',
            'filter.kluster'         => 'nullable|string|max:50',
            'filter.blok'            => 'nullable|string|max:10',
            'filter.tipe_unit_preset_id'    => 'nullable|integer',
            'filter.status_jual'            => 'nullable|string|max:30',
            'filter.status_bangun_stage_id' => 'nullable|integer',
            'simulasi'               => 'nullable|boolean',
        ], [
            'nilai.required_if'               => 'Isi nilainya.',
            'tipe_unit_preset_id.required_if' => 'Pilih Tipe Unit.',
            'tipe_unit_preset_id.exists'      => 'Tipe Unit tidak ditemukan atau tidak aktif di proyek ini.',
            'kavling_ids.max'                 => 'Maksimal ' . self::MAKS_UNIT_MASSAL . ' unit sekali proses.',
        ]);
        $gagal = fn (array $errors) => $request->wantsJson()
            ? response()->json(['message' => collect($errors)->flatten()->first(), 'errors' => $errors], 422)
            : throw ValidationException::withMessages($errors);
        if ($validator->fails()) return $gagal($validator->errors()->toArray());
        $v = $validator->validated();

        if ($v['aksi'] === 'harga' && $v['mode'] === 'set' && (float) $v['nilai'] < 0) {
            return $gagal(['nilai' => ['Harga tidak boleh negatif.']]);
        }

        $semua = (bool) ($v['semua_hasil_filter'] ?? false);
        $ids = array_values(array_unique(array_map('intval', $v['kavling_ids'] ?? [])));
        if (!$semua && !$ids) return $gagal(['kavling_ids' => ['Pilih minimal satu unit.']]);

        $filter = $v['filter'] ?? [];
        $units = $project->kavlings()->with('tipeUnitPreset')
            ->when($semua, fn ($q) => $q
                ->when($filter['kluster'] ?? null, fn ($q, $x) => $q->where('kluster', $x))
                ->when($filter['blok'] ?? null, fn ($q, $x) => $q->where('blok', $x))
                ->when($filter['tipe_unit_preset_id'] ?? null, fn ($q, $x) => $q->where('tipe_unit_preset_id', $x))
                ->when($filter['status_jual'] ?? null, fn ($q, $x) => $q->where('status_jual', $x))
                ->when($filter['status_bangun_stage_id'] ?? null, fn ($q, $x) => $q->where('status_bangun_stage_id', $x)))
            ->when(!$semua, fn ($q) => $q->whereIn('id', $ids))
            ->orderByUnit()
            ->get();
        if (!$semua && $units->count() !== count($ids)) return $gagal(['kavling_ids' => ['Ada unit yang bukan bagian dari proyek ini.']]);
        if ($units->count() > self::MAKS_UNIT_MASSAL) return $gagal(['kavling_ids' => ['Terlalu banyak unit (maksimal ' . self::MAKS_UNIT_MASSAL . '). Persempit dengan filter.']]);
        if ($units->isEmpty()) return $gagal(['kavling_ids' => ['Tidak ada unit yang cocok.']]);

        $tipeBaru = $v['aksi'] === 'tipe' ? $project->tipeUnitPresets()->find($v['tipe_unit_preset_id']) : null;
        $rencana = $units->map(function (Kavling $k) use ($v, $tipeBaru) {
            $status = $k->status_jual->value;
            $baris = ['id' => $k->id, 'unit' => $k->nomor_lengkap, 'status' => $status, 'hasil' => 'ubah', 'alasan' => null, 'lama' => null, 'baru' => null, 'data' => []];
            if ($v['aksi'] === 'harga') {
                $lama = $k->harga === null ? null : (float) $k->harga;
                $baris['lama'] = $lama;
                if ($v['mode'] === 'set') {
                    $baru = (float) $v['nilai'];
                } elseif ($lama === null) {
                    $baris['hasil'] = 'lewati';
                    $baris['alasan'] = 'Belum punya harga';
                    $baru = null;
                } else {
                    $baru = $v['mode'] === 'persen' ? $lama * (1 + (float) $v['nilai'] / 100) : $lama + (float) $v['nilai'];
                }
                if ($baru !== null) {
                    $baru = max(0.0, round($baru));
                    $baris['baru'] = $baru;
                    $baris['data'] = ['harga' => $baru];
                }
            } else {
                $baris['lama'] = $k->tipeUnitPreset?->nama;
                $baris['baru'] = $tipeBaru->nama;
                $baris['data'] = ['tipe_unit_preset_id' => $tipeBaru->id];
                if ((int) $k->tipe_unit_preset_id === $tipeBaru->id) $baris['hasil'] = 'sama';
            }
            if ($baris['hasil'] === 'ubah' && !in_array($status, self::STATUS_BOLEH_MASSAL, true)) {
                $baris['hasil'] = 'lewati';
                $baris['alasan'] = 'Sudah berproses transaksi';
            } elseif ($baris['hasil'] === 'ubah' && $v['aksi'] === 'harga' && $baris['lama'] === $baris['baru']) {
                $baris['hasil'] = 'sama';
            }
            if ($baris['hasil'] === 'sama') $baris['alasan'] = 'Sudah sama';

            return $baris;
        });
        $hitung = $rencana->countBy('hasil');
        $ringkas = ['total' => $rencana->count(), 'ubah' => $hitung['ubah'] ?? 0, 'lewati' => $hitung['lewati'] ?? 0, 'sama' => $hitung['sama'] ?? 0];

        if ($request->boolean('simulasi')) {
            return response()->json([
                'ringkas'   => $ringkas,
                // Yang akan diubah ditampilkan lebih dulu; pratinjau dipotong agar respons tetap ringan untuk ribuan unit.
                'baris'     => $rencana->sortBy(fn ($b) => $b['hasil'] === 'ubah' ? 0 : 1)->take(self::MAKS_BARIS_PRATINJAU)
                    ->map(fn ($b) => collect($b)->except('data'))->values(),
                'terpotong' => $rencana->count() > self::MAKS_BARIS_PRATINJAU,
            ]);
        }

        DB::transaction(function () use ($rencana, $units) {
            $perId = $units->keyBy('id');
            foreach ($rencana->where('hasil', 'ubah') as $b) {
                $perId[$b['id']]->update($b['data']);
            }
        });

        $rangkum = $v['aksi'] === 'harga' ? "harga ({$v['mode']} {$v['nilai']})" : "tipe ke {$tipeBaru->nama}";
        activity('kavling')
            ->causedBy($request->user())
            ->withProperties(['project_id' => $project->id, 'aksi' => $v['aksi'], 'unit_ids' => $rencana->where('hasil', 'ubah')->pluck('id')->all(), 'ringkas' => $ringkas])
            ->log("Edit massal {$rangkum}: {$ringkas['ubah']} unit diubah");

        $pesan = "Edit massal: {$ringkas['ubah']} unit diubah";
        if ($ringkas['lewati']) $pesan .= ", {$ringkas['lewati']} dilewati";
        if ($ringkas['sama']) $pesan .= ", {$ringkas['sama']} sudah sama";

        return back()->with('success', $pesan . '.');
    }

    public function destroy(Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('delete kavlings'), 403);

        $kavling->delete();

        return back()->with('success', 'Kavling berhasil dihapus.');
    }

    /**
     * Simpan ID Rumah (Tapera/SIKUMBANG) — bisa diedit langsung dari kolom
     * tabel list Kavling, tidak perlu buka detail. Melekat di unit fisik
     * (bukan transaksi), jadi bisa diisi kapan pun terlepas status jual.
     */
    public function updateIdRumah(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings') || Auth::user()->can('isi id rumah'), 403);

        $validated = $request->validate([
            'id_rumah' => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'id_rumah')->ignore($kavling->id),
            ],
        ]);

        $kavling->update(['id_rumah' => $validated['id_rumah'] ?: null]);

        return back()->with('success', 'ID Rumah berhasil disimpan.');
    }

    /**
     * Simpan NIB (Nomor Induk Bidang; kolom `hgb_no`) — sama polanya dengan updateIdRumah(), diedit
     * langsung dari kolom tabel list Kavling.
     */
    public function updateHgbNo(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        $validated = $request->validate([
            'hgb_no' => [
                'nullable', 'string', 'max:50',
                Rule::unique('kavlings', 'hgb_no')->ignore($kavling->id),
            ],
        ]);

        $kavling->update(['hgb_no' => $validated['hgb_no'] ?: null]);

        return back()->with('success', 'NIB berhasil disimpan.');
    }

    /**
     * Toggle ketersediaan kavling (Tersedia <-> Tidak Tersedia) oleh admin
     * proyek. Hanya berlaku selama kavling belum dibooking/terjual —
     * begitu masuk pipeline penjualan, status_jual dikendalikan otomatis
     * oleh BookingController, bukan lewat sini.
     */
    public function updateStatusJual(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('edit kavlings'), 403);

        abort_unless(
            in_array($kavling->status_jual, [StatusJual::Available, StatusJual::Hold]),
            422,
            'Ketersediaan hanya bisa diubah selama kavling belum dibooking/terjual.'
        );

        $validated = $request->validate([
            'status_jual' => 'required|in:available,hold',
        ]);

        $kavling->update($validated);

        return back()->with('success', "Kavling {$kavling->nomor_lengkap} sekarang: {$kavling->status_jual->label()}");
    }

    /**
     * Update status pembangunan (khusus staff lapangan)
     */
    /**
     * Catatan bebas per kavling (dipakai di Proses Bangun) — dipisah dari
     * updateStatusBangun supaya bisa disimpan sendiri tanpa ikut mengubah
     * tahap/persen. Sama polanya dengan updateIdRumah()/updateHgbNo().
     */
    public function updateCatatan(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('update status bangun'), 403);

        $validated = $request->validate([
            'catatan' => 'nullable|string|max:2000',
        ]);

        $kavling->update(['catatan' => $validated['catatan'] ?: null]);

        return back()->with('success', "Catatan kavling {$kavling->nomor_lengkap} disimpan.");
    }

    public function updateStatusBangun(Request $request, Kavling $kavling): RedirectResponse
    {
        $this->authorizeProjectAccess($kavling->project);
        abort_unless(Auth::user()->can('update status bangun'), 403);

        $validated = $request->validate([
            'status_bangun_stage_id' => 'required|exists:status_bangun_stages,id',
            'persen'                 => 'nullable|numeric|min:0|max:100',
            'catatan'                => 'nullable|string',
        ]);

        // Pindah tahap tanpa persen eksplisit → mulai dari 0% di tahap baru.
        $stageBerubah = (int) $validated['status_bangun_stage_id'] !== (int) $kavling->status_bangun_stage_id;
        $persen = $validated['persen'] ?? ($stageBerubah ? 0 : $kavling->status_bangun_persen);
        $default = StatusBangunStage::defaultStage();
        if ($default && (int) $validated['status_bangun_stage_id'] === $default->id) $persen = 0;

        $kavling->update([
            'status_bangun_stage_id' => $validated['status_bangun_stage_id'],
            'status_bangun_persen'   => $persen,
        ] + (isset($validated['catatan']) ? ['catatan' => $validated['catatan']] : []));

        return back()->with('success', "Status bangun kavling {$kavling->nomor_lengkap} diperbarui: {$kavling->statusBangunStage->nama} {$persen}% (progress {$kavling->progress_bangun}%)");
    }
}
