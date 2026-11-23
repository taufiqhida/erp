<?php

namespace App\Http\Controllers;

use App\Models\Wilayah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sumber data pemilih wilayah bertingkat: daftar anak dari satu kode (tanpa `induk` = daftar provinsi). */
class WilayahController extends Controller
{
    public function anak(Request $request): JsonResponse
    {
        $data = $request->validate(['induk' => ['nullable', 'string', 'max:16', 'regex:/^[0-9.]+$/']]);

        $daftar = Wilayah::query()
            ->when(filled($data['induk'] ?? null), fn ($q) => $q->where('induk', $data['induk']), fn ($q) => $q->where('level', 1))
            ->orderBy('nama')
            ->get(['kode', 'nama', 'kode_pos']);

        // Data referensi yang jarang berubah: boleh disimpan browser sehari.
        return response()->json($daftar)->header('Cache-Control', 'private, max-age=86400');
    }
}
