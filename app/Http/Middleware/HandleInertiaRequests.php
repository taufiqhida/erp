<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Policies\ProjectPolicy;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id'          => $request->user()->id,
                    'name'        => $request->user()->name,
                    'email'       => $request->user()->email,
                    'roles'       => $request->user()->getRoleNames(),
                    'permissions' => $request->user()->getAllPermissions()->pluck('name'),
                    'projects'    => $request->user()->hasAnyRole(['superadmin', 'manajer'])
                        ? null // null = akses semua proyek
                        : $request->user()->projects()->get(['projects.id', 'projects.nama', 'projects.kode']),
                ] : null,
            ],
            'flash' => [
                'success'      => $request->session()->get('success'),
                'error'        => $request->session()->get('error'),
                'warning'      => $request->session()->get('warning'),
                'importErrors' => $request->session()->get('importErrors'),
            ],
            'currentProject' => $this->resolveCurrentProject($request),
        ];
    }

    /**
     * Proyek yang sedang aktif dipilih di Halaman Utama — session saja,
     * tidak persisten lintas login. Kalau id di session sudah tidak valid
     * atau di luar akses user (mis. akses dicabut di tengah sesi), diam-diam
     * dianggap tidak ada context aktif — bukan error, biar otomatis fallback
     * ke Halaman Utama Pilih Proyek.
     */
    private function resolveCurrentProject(Request $request): ?array
    {
        $projectId = $request->session()->get('current_project_id');
        if (!$projectId || !$request->user()) {
            return null;
        }

        $project = Project::find($projectId, ['id', 'nama', 'kode']);
        if (!$project || !app(ProjectPolicy::class)->isAssignedTo($request->user(), $project)) {
            return null;
        }

        return ['id' => $project->id, 'nama' => $project->nama, 'kode' => $project->kode];
    }
}
