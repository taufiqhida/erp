<?php

namespace App\Http\Controllers;

use App\Support\PindahMaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan → Pindah Master Data (superadmin: permission 'manage system settings').
 * Ekspor master dari satu server ke file JSON, lalu impor ke server lain (mis. staging → production),
 * dengan pratinjau dulu. Lihat App\Support\PindahMaster untuk aturan pencocokan dan keamanannya.
 */
class PindahMasterController extends Controller
{
    private const SESI = 'pindah_master';

    public function index(Request $request): Response
    {
        return Inertia::render('Pengaturan/PindahMaster', [
            'daftar' => PindahMaster::daftar(),
            'preview' => $request->session()->get(self::SESI . '.preview'),
        ]);
    }

    /** Unduh file master (GET supaya bisa berupa tautan unduh biasa). */
    public function ekspor(Request $request)
    {
        $keys = PindahMaster::kunciValid((array) $request->query('master', []));
        abort_if(!$keys, 422, 'Pilih minimal satu master untuk diekspor.');

        $payload = PindahMaster::ekspor($keys);

        activity('master')->causedBy($request->user())
            ->withProperties(['master' => $keys])
            ->log('Ekspor master data: ' . implode(', ', $keys));

        $nama = 'master-ssid-' . now()->format('Ymd-His') . '.json';

        return response()->streamDownload(
            fn () => print json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            $nama,
            ['Content-Type' => 'application/json'],
        );
    }

    /** Langkah 1 impor: baca file, simulasikan, simpan pratinjau. Belum ada yang diubah. */
    public function periksa(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:json,txt|max:2048',
            'master' => 'required|array|min:1',
            'perbarui' => 'nullable|boolean',
        ], [
            'file.required' => 'Pilih file master (.json) dulu.',
            'file.mimes' => 'File harus berformat .json hasil ekspor sistem ini.',
            'file.max' => 'File terlalu besar (maksimal 2 MB).',
            'master.required' => 'Pilih minimal satu master untuk diimpor.',
        ]);

        $payload = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if ($galat = PindahMaster::periksaPayload($payload)) {
            return back()->withErrors(['file' => $galat]);
        }

        $keys = PindahMaster::kunciValid((array) $request->input('master'));
        $perbarui = $request->boolean('perbarui');
        $r = PindahMaster::proses($payload, $keys, $perbarui, false);

        $request->session()->put(self::SESI, [
            'payload' => $payload,
            'keys' => $keys,
            'perbarui' => $perbarui,
            'preview' => $this->tampilkan($r, $keys, $perbarui, $request->file('file')->getClientOriginalName(), $payload),
        ]);

        return back();
    }

    /** Langkah 2 impor: terapkan sungguhan, memakai file dan pilihan dari pratinjau. */
    public function terapkan(Request $request): RedirectResponse
    {
        $sesi = $request->session()->get(self::SESI);
        if (!$sesi) {
            return back()->with('error', 'Pratinjau sudah kedaluwarsa. Pilih file lalu periksa dulu.');
        }

        $r = PindahMaster::proses($sesi['payload'], $sesi['keys'], $sesi['perbarui'], true);
        if (!$r['diterapkan']) {
            $request->session()->put(self::SESI . '.preview', $this->tampilkan($r, $sesi['keys'], $sesi['perbarui'], $sesi['preview']['nama_file'] ?? '', $sesi['payload']));

            return back()->with('error', 'Impor dibatalkan karena ada masalah. Tidak ada data yang berubah.');
        }

        $baru = array_sum(array_column($r['hasil'], 'baru'));
        $diperbarui = array_sum(array_column($r['hasil'], 'diperbarui'));

        activity('master')->causedBy($request->user())
            ->withProperties(['master' => $sesi['keys'], 'perbarui' => $sesi['perbarui'], 'hasil' => $r['hasil']])
            ->log("Impor master data: {$baru} baru, {$diperbarui} diperbarui");

        $request->session()->forget(self::SESI);

        return back()->with('success', "Impor selesai: {$baru} data baru ditambahkan, {$diperbarui} diperbarui.");
    }

    public function batal(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESI);

        return back();
    }

    private function tampilkan(array $r, array $keys, bool $perbarui, string $namaFile, array $payload): array
    {
        $label = fn (string $k) => PindahMaster::definisi()[$k]['label'];

        return [
            'nama_file' => $namaFile,
            'dibuat' => $payload['dibuat'] ?? null,
            'sumber' => $payload['sumber'] ?? null,
            'perbarui' => $perbarui,
            'baris' => collect($r['hasil'])->map(fn ($h, $k) => ['key' => $k, 'label' => $label($k)] + $h)->values()->all(),
            'masalah' => $r['masalah'],
            'bisa_diterapkan' => !$r['masalah'],
        ];
    }
}
