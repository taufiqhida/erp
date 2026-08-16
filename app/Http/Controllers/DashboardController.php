<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\CancellationRequest;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $isGlobal = $user->hasAnyRole(['superadmin', 'manajer']);

        $stats = [
            'total_projects'   => Project::active()->count(),
            'total_kavlings'   => Kavling::count(),
            'kavling_available' => Kavling::available()->count(),
            'kavling_sold'     => Kavling::where('status_jual', 'sold')->count(),
            'pending_cancellations' => CancellationRequest::pending()->count(),
        ];

        $recentActivities = Activity::with('causer')
            ->latest()
            ->take(10)
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'description' => $a->description,
                'causer_name' => $a->causer?->name ?? 'System',
                'created_at'  => $a->created_at->diffForHumans(),
            ]);

        $projectsSummary = Project::active()
            ->withCount([
                'kavlings',
                'kavlings as kavlings_sold_count'      => fn($q) => $q->where('status_jual', 'sold'),
                'kavlings as kavlings_available_count' => fn($q) => $q->where('status_jual', 'available'),
            ])
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn($p) => [
                'id'                => $p->id,
                'nama'              => $p->nama,
                'kode'              => $p->kode,
                'kota'              => $p->kota,
                'kavlings_count'    => $p->kavlings_count,
                'kavlings_sold'     => $p->kavlings_sold_count,
                'kavlings_available'=> $p->kavlings_available_count,
                'progress'          => $p->kavlings_count > 0
                    ? round(($p->kavlings_sold_count / $p->kavlings_count) * 100, 1)
                    : 0,
            ]);

        $sp3kMonitoring = KavlingKonsumen::query()
            ->whereNotNull('tanggal_expired_sp3k')
            ->whereIn('status_penjualan', ['sp3k', 'rencana_akad'])
            ->where('status', 'active')
            ->with(['konsumen:id,nama', 'kavling.project:id,nama'])
            ->when(!$isGlobal, fn($q) =>
                $q->whereHas('kavling.project.users', fn($q2) => $q2->where('users.id', $user->id))
            )
            ->orderBy('tanggal_expired_sp3k')
            ->take(10)
            ->get()
            ->map(fn($kk) => [
                'id'                   => $kk->id,
                'konsumen_nama'        => $kk->konsumen->nama,
                'kavling'              => $kk->kavling->nomor_lengkap,
                'project'              => $kk->kavling->project->nama,
                'tanggal_expired_sp3k' => $kk->tanggal_expired_sp3k->format('d M Y'),
                'sp3k_expiry_status'   => $kk->sp3k_expiry_status,
            ]);

        return Inertia::render('Dashboard', [
            'stats'            => $stats,
            'recentActivities' => $recentActivities,
            'projectsSummary'  => $projectsSummary,
            'sp3kMonitoring'   => $sp3kMonitoring,
        ]);
    }
}
