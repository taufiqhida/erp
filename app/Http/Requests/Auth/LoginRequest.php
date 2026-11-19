<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Batas gagal login per email+IP, dan (terpisah) per IP saja. */
    private const MAKS_GAGAL_PER_EMAIL = 5;
    private const MAKS_GAGAL_PER_IP = 20;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Password benar tapi akun dinonaktifkan: tolak dengan pesan yang jelas.
        if (! Auth::user()->is_active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => \App\Http\Middleware\EnsureUserIsActive::PESAN,
            ]);
        }

        // Hanya hitungan email+IP yang direset. Hitungan per IP sengaja dibiarkan: kalau login benar
        // ikut mereset, penyerang yang punya satu akun sah bisa menyegarkan batasnya terus-menerus.
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        // Batas per email+IP menahan tebakan ke satu akun; batas per IP menahan password spraying
        // (satu password umum ke banyak email dari satu IP, yang tidak pernah menyentuh batas pertama).
        $terkunci = collect([
            $this->throttleKey() => self::MAKS_GAGAL_PER_EMAIL,
            $this->ipThrottleKey() => self::MAKS_GAGAL_PER_IP,
        ])->filter(fn (int $maks, string $kunci) => RateLimiter::tooManyAttempts($kunci, $maks));

        if ($terkunci->isEmpty()) {
            return;
        }

        event(new Lockout($this));

        $seconds = $terkunci->keys()->map(fn (string $kunci) => RateLimiter::availableIn($kunci))->max();

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * Kunci pembatas per IP (tanpa email).
     */
    public function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }
}
