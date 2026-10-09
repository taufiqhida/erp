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
            ->paginate($this->perPage(request(), 20))
            ->through(fn($u) => [
                'id'       => $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'initials' => $u->initials,
                'roles'    => $u->roles->pluck('name'),
                'must_change_password' => (bool) $u->must_change_password,
                'is_active' => (bool) $u->is_active,
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
    /**
     * Ubah nama & email akun (hanya superadmin). Pemilik akun sendiri TIDAK bisa mengubahnya di Profil: email adalah
     * nama login dan nama tampil di Audit Trail, jadi perubahannya harus tercatat dan dilakukan orang yang berwenang.
     */
    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $lama = ['name' => $user->name, 'email' => $user->email];
        if ($lama === $validated) {
            return back()->with('success', "Tidak ada perubahan untuk {$user->name}.");
        }

        $user->forceFill($validated)->save();

        activity('akun')
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['lama' => $lama, 'baru' => $validated])
            ->log("Ubah data akun {$lama['email']}");

        return back()->with('success', "Data akun {$validated['name']} diperbarui.");
    }

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

    /**
     * Aktifkan / nonaktifkan user. Nonaktif = tidak bisa login dan sesi yang sedang aktif langsung
     * dikeluarkan, tapi nama & riwayatnya tetap utuh di Audit Trail dan data transaksi.
     */
    public function toggleAktif(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'Akun sendiri tidak bisa dinonaktifkan.');

        $user->is_active = !$user->is_active;
        $user->remember_token = null;
        $user->save();

        if (!$user->is_active) {
            \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        activity('akun')
            ->performedOn($user)
            ->causedBy($request->user())
            ->log(($user->is_active ? 'Mengaktifkan' : 'Menonaktifkan') . " pengguna {$user->email}");

        return back()->with('success', $user->is_active
            ? "Akun {$user->name} diaktifkan kembali."
            : "Akun {$user->name} dinonaktifkan dan tidak bisa login.");
    }

    /**
     * Hapus permanen — HANYA untuk akun yang belum punya jejak apa pun (mis. salah buat). Akun yang
     * pernah bekerja di sistem harus dinonaktifkan supaya riwayatnya tidak kehilangan nama.
     */
    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'Akun sendiri tidak bisa dihapus.');

        $pesan = "Akun {$user->name} sudah punya riwayat kerja di sistem, jadi tidak bisa dihapus. Nonaktifkan saja.";

        $punyaJejak = \Spatie\Activitylog\Models\Activity::where('causer_type', User::class)->where('causer_id', $user->id)->exists();
        if ($punyaJejak) {
            return back()->with('error', $pesan);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
                $user->projects()->detach();
                $user->syncRoles([]);
                $user->delete();
            });
        } catch (\Illuminate\Database\QueryException) {
            return back()->with('error', $pesan); // masih direferensikan data lain
        }

        return back()->with('success', "Akun {$user->name} dihapus.");
    }

    private function buatPasswordSementara(): string
    {
        return \App\Support\PasswordSementara::buat();
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
