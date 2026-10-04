<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * User sungguhan tim (per evaluasi RBAC 2026-09-29) — menggantikan 6 user
 * dummy lama. Password default 'password' untuk semua (dummy data, ganti
 * sebelum go-live). Email pakai domain internal @erp.local karena belum ada
 * alamat email asli — sesuaikan nanti kalau sudah ada.
 */
class RealUsersSeeder extends Seeder
{
    public function run(): void
    {
        $people = [
            // [name, email local-part, role]
            ['Annaz',   'annaz',   'superadmin'],
            ['Toro',    'toro',    'superadmin'],
            ['Ferry',   'ferry',   'manager'],
            ['Icha',    'icha',    'manager'],
            ['Eko',     'eko',     'manager'],
            ['Umi',     'umi',     'spv'],
            ['Ridwan',  'ridwan',  'leader'],
            ['Melza',   'melza',   'admin_sales'],
            ['Yunita',  'yunita',  'admin_pemberkasan'],
            ['Dinda',   'dinda',   'admin_proyek'],
            ['Fathoni', 'fathoni', 'pelaksana_lapangan'],
            ['Nia',     'nia',     'admin_keuangan'],
        ];

        foreach ($people as [$name, $emailLocal, $role]) {
            $user = User::firstOrCreate(
                ['email' => "{$emailLocal}@erp.local"],
                ['name' => $name, 'password' => Hash::make('password'), 'email_verified_at' => now()]
            );
            $user->syncRoles([$role]);
        }
    }
}
