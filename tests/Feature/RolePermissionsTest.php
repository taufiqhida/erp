<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pengaman regresi untuk RBAC (lihat memori project_rbac_restructure_2026_09_29
 * di luar repo) — kalau RolesAndPermissionsSeeder.php diubah tanpa sengaja
 * (misal nambah permission ke role yang salah, atau typo nama permission),
 * test ini langsung merah. Daftar permission per role di bawah HARUS sinkron
 * manual dengan isi seeder — kalau memang sengaja mengubah hak akses suatu
 * role, update juga daftar di sini sebagai bagian dari perubahan yang sama.
 */
class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string[]> */
    private const EXPECTED = [
        'manager' => [
            'view all projects', 'view projects', 'view kavlings', 'view konsumens', 'view keuangan',
        ],
        'direktur' => [
            'view all projects', 'view projects', 'view kavlings', 'view konsumens', 'view keuangan', 'view audit trail',
        ],
        'spv' => [
            'view projects', 'view kavlings',
            'view konsumens', 'create konsumens', 'edit konsumens',
            'view keuangan',
            'review cancellation',
            'override transaction lock',
        ],
        'leader' => [
            'view projects', 'view kavlings',
            'view konsumens', 'create konsumens', 'edit konsumens',
            'view keuangan',
            'review cancellation',
            'override transaction lock',
            'manage sales agent', 'manage program all in',
        ],
        'admin_sales' => [
            'view projects', 'view kavlings',
            'view konsumens', 'create konsumens', 'edit konsumens',
            'manage dokumen',
            'kelola pipeline sales', 'isi bank rekanan kpr',
            'view keuangan', 'manage pembayaran', 'manage rincian biaya akad',
            'book kavling', 'swap kavling', 'request cancellation',
            'manage sales agent',
        ],
        'admin_pemberkasan' => [
            'view projects', 'view kavlings',
            'view konsumens', 'create konsumens', 'edit konsumens',
            'manage dokumen',
            'kelola pemberkasan bank', 'isi bank rekanan kpr',
            'view keuangan',
        ],
        'admin_proyek' => [
            'view projects', 'view kavlings', 'create kavlings', 'edit kavlings', 'delete kavlings',
            'terbitkan spk', 'update status bangun',
            'manage status bangun master', 'manage kontraktor',
        ],
        'pelaksana_lapangan' => [
            'view projects', 'view kavlings', 'update status bangun',
        ],
        'admin_keuangan' => [
            'view projects', 'view kavlings', 'view konsumens',
            'view keuangan', 'manage pembayaran', 'manage kpr', 'manage rincian biaya akad',
            'manage bank rekanan', 'manage notaris', 'manage dajam sbum preset',
        ],
    ];

    public function test_superadmin_has_every_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $role = Role::findByName('superadmin');
        $all = Permission::pluck('name')->sort()->values()->all();

        $this->assertSame($all, $role->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_each_role_has_exactly_the_expected_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (self::EXPECTED as $roleName => $expectedPermissions) {
            $role = Role::findByName($roleName);

            $actual = $role->permissions->pluck('name')->sort()->values()->all();
            $expected = collect($expectedPermissions)->sort()->values()->all();

            $this->assertSame(
                $expected,
                $actual,
                "Permission role '{$roleName}' tidak sesuai — cek RolesAndPermissionsSeeder.php atau perbarui EXPECTED di test ini."
            );
        }
    }

    public function test_only_expected_9_roles_exist(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $expectedRoleNames = array_merge(['superadmin'], array_keys(self::EXPECTED));
        $actualRoleNames = Role::pluck('name')->sort()->values()->all();

        $this->assertSame(
            collect($expectedRoleNames)->sort()->values()->all(),
            $actualRoleNames,
            'Role lama seharusnya sudah dihapus total oleh seeder (lihat Role::whereNotIn(...)->delete()).'
        );
    }
}
