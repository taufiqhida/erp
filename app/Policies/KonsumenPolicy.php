<?php

namespace App\Policies;

use App\Models\Konsumen;
use App\Models\User;

class KonsumenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view konsumens');
    }

    public function view(User $user, Konsumen $konsumen): bool
    {
        return $user->can('view konsumens');
    }

    public function create(User $user): bool
    {
        return $user->can('create konsumens');
    }

    public function update(User $user, Konsumen $konsumen): bool
    {
        return $user->can('edit konsumens');
    }

    public function delete(User $user, Konsumen $konsumen): bool
    {
        return $user->can('delete konsumens');
    }
}
