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
                'must_change_password' => (bool) $u->must_change_password,
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
        // Admin yang menentukan password-nya, jadi pemilik akun wajib menggantinya saat login pertama.
        $user->forceFill(['must_change_password' => true])->save();

        if (!empty($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        return back()->with('success', "Akun {$user->name} berhasil dibuat.");
    }

    /**
     * Reset password user (hanya superadmin): buat password sementara yang tampil SEKALI, tandai
     * wajib ganti pada login berikutnya, dan keluarkan sesi yang sedang aktif. Password lama tidak
     * pernah bisa dilihat (hanya tersimpan sebagai hash).
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'Untuk akun sendiri, ganti password lewat menu Profil.');

        $sementara = $this->buatPasswordSementara();

        $user->forceFill([
            'password'             => Hash::make($sementara),
            'must_change_password' => true,
            'password_changed_at'  => null,
            'remember_token'       => null,
        ])->save();

        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();

        activity('akun')
            ->performedOn($user)
            ->causedBy($request->user())
            ->log("Reset password pengguna {$user->email}");

        return back()->with('tempPassword', [
            'nama'     => $user->name,
            'email'    => $user->email,
            'password' => $sementara,
        ]);
    }

    /** 12 karakter (grup 4-4-4), tanpa karakter yang mudah tertukar (0/O, 1/l/I); selalu ada huruf besar, kecil, dan angka. */
    private function buatPasswordSementara(): string
    {
        $besar = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $kecil = 'abcdefghijkmnpqrstuvwxyz';
        $angka = '23456789';
        $ambil = fn (string $set) => $set[random_int(0, strlen($set) - 1)];

        $chars = [
            $ambil($besar), $ambil($besar), $ambil($besar),
            $ambil($kecil), $ambil($kecil), $ambil($kecil), $ambil($kecil), $ambil($kecil),
            $ambil($angka), $ambil($angka), $ambil($angka), $ambil($kecil),
        ];
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('-', array_map('implode', array_chunk($chars, 4)));
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
