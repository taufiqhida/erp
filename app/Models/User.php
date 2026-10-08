<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /** Pilihan ukuran teks (diterapkan lewat atribut data-ukuran pada <html>, lihat resources/css/app.css). */
    public const UKURAN_FONT = ['normal', 'besar', 'lebih-besar'];

    /** Pilihan tema tampilan: gelap (bawaan), terang, atau mengikuti pengaturan perangkat. */
    public const TEMA = ['gelap', 'terang', 'sistem'];

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** Nilai bawaan juga berlaku untuk model yang baru dibuat (sebelum dimuat ulang dari database). */
    protected $attributes = [
        'is_active'            => true,
        'must_change_password' => false,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'must_change_password' => 'boolean',
            'is_active'            => 'boolean',
            'password_changed_at'  => 'datetime',
            'preferences'          => 'array',
        ];
    }

    /** Tema pilihan pengguna, selalu salah satu nilai TEMA (bawaan gelap). */
    public function temaPilihan(): string
    {
        $v = $this->preferences['tema'] ?? 'gelap';

        return in_array($v, self::TEMA, true) ? $v : 'gelap';
    }

    /** Ukuran teks pilihan pengguna, selalu salah satu nilai UKURAN_FONT. */
    public function ukuranFont(): string
    {
        $v = $this->preferences['ukuran_font'] ?? 'normal';

        return in_array($v, self::UKURAN_FONT, true) ? $v : 'normal';
    }

    /* ---------------------------------------------------------------
     | Relationships
     --------------------------------------------------------------- */

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_user')->withTimestamps();
    }

    public function createdKavlingKonsumens(): HasMany
    {
        return $this->hasMany(KavlingKonsumen::class, 'created_by');
    }

    public function cancellationRequestsSubmitted(): HasMany
    {
        return $this->hasMany(CancellationRequest::class, 'requested_by');
    }

    public function cancellationRequestsReviewed(): HasMany
    {
        return $this->hasMany(CancellationRequest::class, 'reviewed_by');
    }

    /* ---------------------------------------------------------------
     | Accessors
     --------------------------------------------------------------- */

    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->name);
        return strtoupper(
            count($words) >= 2
                ? $words[0][0] . $words[1][0]
                : substr($this->name, 0, 2)
        );
    }

    public function getRoleLabelAttribute(): string
    {
        $role = $this->roles->first();
        return $role ? $role->name : 'Tanpa Role';
    }
}
