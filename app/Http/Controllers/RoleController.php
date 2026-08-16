<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        $users = User::with(['roles', 'projects:id,nama,kode'])
            ->orderBy('name')
            ->paginate(20)
            ->through(fn($u) => [
                'id'       => $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'initials' => $u->initials,
                'roles'    => $u->roles->pluck('name'),
                'projects' => $u->projects->map(fn($p) => ['id' => $p->id, 'nama' => $p->nama, 'kode' => $p->kode]),
            ]);

        $roles    = Role::orderBy('name')->get(['id', 'name']);
        $projects = \App\Models\Project::orderBy('nama')->where('is_active', true)->get(['id', 'nama', 'kode']);

        return Inertia::render('Roles/Index', [
            'users'    => $users,
            'roles'    => $roles,
            'projects' => $projects,
        ]);
    }

    public function assign(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles'   => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $user->syncRoles($validated['roles']);

        return back()->with('success', "Role untuk {$user->name} berhasil diperbarui.");
    }

    /**
     * Buat user baru (hanya superadmin). Email langsung diverifikasi.
     */
    public function storeUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'roles'    => 'nullable|array',
            'roles.*'  => 'string|exists:roles,name',
        ]);

        $user = User::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => Hash::make($validated['password']),
            'email_verified_at' => now(), // langsung verifikasi
        ]);

        if (!empty($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        return back()->with('success', "Akun {$user->name} berhasil dibuat.");
    }

    /**
     * Assign / unassign user ke project
     */
    public function assignProject(Request $request, \App\Models\Project $project): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'user_ids'   => 'required|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        // Sync user ke project (replace semua assignment lama)
        $project->users()->sync($validated['user_ids']);

        return back()->with('success', "Assignment user ke proyek {$project->nama} berhasil diperbarui.");
    }
}
