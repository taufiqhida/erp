<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Bahan bersama dua daftar Keuangan (Piutang Konsumen & Pencairan KPR): query
 * dasar (RBAC, proyek aktif, filter umum) dan sort di SQL. Total keuangan dibaca
 * dari kolom tersimpan fin_* (lihat App\Models\Concerns\HasFinanceTotals), jadi
 * sort/filter/paginasi tidak menghitung Kartu Piutang di PHP.
 */
trait BuildsKeuanganLists
{
    /** Toleransi pembulatan: sisa di bawah ini dianggap 0 (lunas). */
    private const SISA_EPS = 0.009;

    private function keuanganBaseQuery(Request $request): Builder
    {
        $user = Auth::user();
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);
        $projectId = session('current_project_id');
        $projectScope = fn ($q) => $q->whereHas('project.users', fn ($q2) => $q2->where('users.id', $user->id));

        return KavlingKonsumen::query()
            ->join('kavlings', 'kavlings.id', '=', 'kavling_konsumen.kavling_id')
            ->join('konsumens', 'konsumens.id', '=', 'kavling_konsumen.konsumen_id')
            ->select('kavling_konsumen.*')
            ->whereNull('kavlings.deleted_at')
            ->whereNull('konsumens.deleted_at')
            ->where('kavling_konsumen.status', '!=', 'cancelled')
            ->when($projectId, fn ($q) => $q->where('kavlings.project_id', $projectId))
            ->when(!$isGlobal, fn ($q) => $q->whereHas('kavling', $projectScope))
            ->when($request->kluster, fn ($q) => $q->where('kavlings.kluster', $request->kluster))
            ->when($request->blok, fn ($q) => $q->where('kavlings.blok', $request->blok))
            ->when($request->search, function ($q) use ($request) {
                $kw = $request->search;
                $q->where(fn ($sub) => $sub
                    ->where('konsumens.nama', 'like', "%{$kw}%")
                    ->orWhere('konsumens.nik', 'like', "%{$kw}%")
                    ->orWhere('konsumens.no_hp', 'like', "%{$kw}%")
                    ->orWhere('konsumens.email', 'like', "%{$kw}%")
                    ->orWhere('kavlings.nomor_kavling', 'like', "%{$kw}%")
                    ->orWhere('kavlings.blok', 'like', "%{$kw}%"));
            });
    }

    private function keuanganFilterOptions(): array
    {
        $user = Auth::user();
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);
        $projectId = session('current_project_id');
        $projectScope = fn ($q) => $q->whereHas('project.users', fn ($q2) => $q2->where('users.id', $user->id));

        $options = Kavling::query()
            ->when(!$isGlobal, $projectScope)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId));

        return [
            'kluster' => (clone $options)->whereNotNull('kluster')->where('kluster', '!=', '')->distinct()->orderBy('kluster')->pluck('kluster'),
            'blok'    => (clone $options)->whereNotNull('blok')->where('blok', '!=', '')->distinct()->orderBy('blok')->pluck('blok'),
        ];
    }

    /**
     * Terapkan sort dari whitelist (kunci sort → closure ORDER BY). Kunci tak dikenal
     * jatuh ke default, jadi input dari URL tidak pernah masuk ke ORDER BY mentah.
     *
     * @param  array<string, callable(Builder,string):void>  $map
     * @return array{0:string,1:string}  [sort terpakai, dir terpakai]
     */
    private function applyKeuanganSort(Builder $query, Request $request, array $map, string $default): array
    {
        $sort = array_key_exists((string) $request->sort, $map) ? $request->sort : $default;
        $dir = $request->dir === 'asc' ? 'asc' : 'desc';
        $map[$sort]($query, $dir);
        $query->orderByDesc('kavling_konsumen.id');

        return [$sort, $dir];
    }

    /** ORDER BY ekspresi dengan NULL selalu di paling bawah, apa pun arahnya. */
    private function orderNullsLast(Builder $query, string $expr, string $dir): void
    {
        $query->orderByRaw("({$expr}) IS NULL")->orderByRaw("({$expr}) {$dir}");
    }
}
