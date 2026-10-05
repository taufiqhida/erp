<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WajibGantiPassword extends Command
{
    protected $signature = 'users:wajib-ganti-password
        {email?* : Email user tertentu (boleh lebih dari satu)}
        {--all : Semua user}
        {--except=* : Email yang dikecualikan (untuk --all)}
        {--logout : Keluarkan juga sesi login yang sedang aktif}';

    protected $description = 'Tandai user wajib mengganti password pada login berikutnya (mis. setelah password default dipakai bersama)';

    public function handle(): int
    {
        $emails = $this->argument('email');

        if (!$this->option('all') && !$emails) {
            $this->error('Sebutkan email user, atau pakai --all.');
            return self::FAILURE;
        }

        $query = User::query()
            ->when($emails, fn ($q) => $q->whereIn('email', $emails))
            ->when($this->option('except'), fn ($q, $kecuali) => $q->whereNotIn('email', $kecuali));

        $ids = $query->pluck('id');
        if ($ids->isEmpty()) {
            $this->warn('Tidak ada user yang cocok.');
            return self::FAILURE;
        }

        User::whereIn('id', $ids)->update(['must_change_password' => true]);

        if ($this->option('logout')) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }

        $this->info("{$ids->count()} user ditandai wajib ganti password pada login berikutnya.");

        return self::SUCCESS;
    }
}
