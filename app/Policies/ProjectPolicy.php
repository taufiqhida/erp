<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * superadmin & manajer punya akses lintas-proyek (oversight role).
     * Role lain (sales, staff_lapangan, finance, staff_kpr) hanya
     * boleh mengakses proyek yang di-assign lewat project_user.
     */
    protected function hasGlobalAccess(User $user): bool
    {
        return $user->hasAnyRole(['superadmin', 'manajer']);
    }

    public function isAssignedTo(User $user, Project $project): bool
    {
        return $this->hasGlobalAccess($user)
            || $project->users()->where('users.id', $user->id)->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->can('view projects');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can('view projects') && $this->isAssignedTo($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->can('create projects');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('edit projects') && $this->isAssignedTo($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('delete projects');
    }
}
