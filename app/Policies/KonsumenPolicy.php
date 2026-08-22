<?php

namespace App\Policies;

use App\Models\Konsumen;
use App\Models\User;

class KonsumenPolicy
{
    /**
     * superadmin & manajer punya akses lintas-proyek (oversight role).
     * Role lain hanya boleh mengakses konsumen yang transaksinya terkait
     * proyek yang di-assign lewat project_user (konsisten dengan ProjectPolicy).
     */
    protected function hasProjectAccess(User $user, Konsumen $konsumen): bool
    {
        if ($user->hasAnyRole(['superadmin', 'manajer'])) {
            return true;
        }

        // Konsumen yang belum punya transaksi sama sekali belum terikat
        // proyek manapun, jadi tidak ada yang perlu di-scope.
        if (!$konsumen->kavlingKonsumens()->exists()) {
            return true;
        }

        return $konsumen->kavlingKonsumens()
            ->whereHas('kavling.project.users', fn($q) => $q->where('users.id', $user->id))
            ->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->can('view konsumens');
    }

    public function view(User $user, Konsumen $konsumen): bool
    {
        return $user->can('view konsumens') && $this->hasProjectAccess($user, $konsumen);
    }

    public function create(User $user): bool
    {
        return $user->can('create konsumens');
    }

    public function update(User $user, Konsumen $konsumen): bool
    {
        return $user->can('edit konsumens') && $this->hasProjectAccess($user, $konsumen);
    }

    public function delete(User $user, Konsumen $konsumen): bool
    {
        return $user->can('delete konsumens') && $this->hasProjectAccess($user, $konsumen);
    }
}
