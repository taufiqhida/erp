<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Auth;

/**
 * Dipakai oleh controller yang menerima Kavling/KavlingKonsumen (bukan Project
 * langsung) untuk tetap menegakkan project-scoping: superadmin/manajer bebas,
 * role lain hanya boleh menyentuh data proyek yang di-assign lewat project_user.
 */
trait AuthorizesProjectAccess
{
    protected function authorizeProjectAccess(Project $project): void
    {
        abort_unless(
            app(ProjectPolicy::class)->isAssignedTo(Auth::user(), $project),
            403,
            'Anda tidak memiliki akses ke proyek ini.'
        );
    }
}
