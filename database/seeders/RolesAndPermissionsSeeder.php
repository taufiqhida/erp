<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define permissions
        $permissions = [
            // Projects
            'view projects',
            'create projects',
            'edit projects',
            'delete projects',
            // Kavlings
            'view kavlings',
            'create kavlings',
            'edit kavlings',
            'delete kavlings',
            'update status bangun',
            'book kavling',
            'swap kavling',
            // Konsumens
            'view konsumens',
            'create konsumens',
            'edit konsumens',
            'delete konsumens',
            // Dokumen & pipeline KPR
            'manage dokumen',
            'update status penjualan',
            // Keuangan
            'view keuangan',
            'manage pembayaran',
            'manage kpr',
            'manage sbum',
            // Cancellation
            'request cancellation',
            'review cancellation',
            // Roles
            'manage roles',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Rename role lama staff_penjualan -> sales in-place, supaya user yang
        // sudah punya role ini tidak kehilangan assignment-nya.
        if ($legacy = Role::where('name', 'staff_penjualan')->first()) {
            $legacy->update(['name' => 'sales']);
        }

        // Define roles and assign permissions
        $roles = [
            'superadmin' => $permissions, // all
            'manajer'    => [
                'view projects', 'create projects', 'edit projects',
                'view kavlings', 'create kavlings', 'edit kavlings', 'book kavling', 'swap kavling',
                'view konsumens', 'create konsumens', 'edit konsumens',
                'manage dokumen', 'update status penjualan',
                'view keuangan', 'manage pembayaran', 'manage kpr', 'manage sbum',
                'request cancellation', 'review cancellation',
            ],
            'sales' => [
                'view projects',
                'view kavlings', 'book kavling', 'swap kavling',
                'view konsumens', 'create konsumens', 'edit konsumens',
                'manage dokumen',
                'view keuangan',
                'request cancellation',
            ],
            'staff_lapangan' => [
                'view projects',
                'view kavlings', 'update status bangun',
            ],
            'finance' => [
                'view projects', 'view kavlings', 'view konsumens',
                'view keuangan', 'manage pembayaran', 'manage kpr', 'manage sbum',
                'review cancellation',
            ],
            'staff_kpr' => [
                'view projects', 'view kavlings', 'view konsumens',
                'manage dokumen', 'update status penjualan',
            ],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($rolePermissions);
        }
    }
}
