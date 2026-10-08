<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordSementara;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class BuatSuperadmin extends Command
{
    protected $signature = 'users:buat-superadmin
        {email : Email akun superadmin (dipakai untuk login)}
        {--nama= : Nama lengkap (bawaan: bagian email sebelum @)}';

    protected $description = 'Buat akun superadmin pertama di server baru (production). Password sementara acak tampil SEKALI dan wajib diganti saat login pertama.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $nama = trim((string) $this->option('nama')) ?: ucfirst(explode('@', $email)[0]);

        $v = Validator::make(['email' => $email, 'nama' => $nama], [
            'email' => 'required|email|max:255',
            'nama' => 'required|string|max:255',
        ]);
        if ($v->fails()) {
            $this->error(implode(' ', $v->errors()->all()));
            return self::FAILURE;
        }

        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            $this->error("Akun dengan email {$email} sudah ada. Tidak ada yang diubah.");
            return self::FAILURE;
        }

        if (!Role::where('name', 'superadmin')->where('guard_name', 'web')->exists()) {
            $this->error('Role superadmin belum ada. Jalankan dulu: php artisan db:seed --class=RolesAndPermissionsSeeder --force');
            return self::FAILURE;
        }

        $sementara = PasswordSementara::buat();

        $user = User::create([
            'name' => $nama,
            'email' => $email,
            'password' => Hash::make($sementara),
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
            'must_change_password' => true,
            'password_changed_at' => null,
        ])->save();
        $user->assignRole('superadmin');

        activity('akun')->performedOn($user)->log("Akun superadmin {$email} dibuat lewat perintah server");

        $this->info("Akun superadmin dibuat: {$nama} <{$email}>");
        $this->line('Password sementara (hanya tampil sekali, catat sekarang):');
        $this->line("  {$sementara}");
        $this->line('Saat login pertama, sistem akan meminta password baru.');

        return self::SUCCESS;
    }
}
