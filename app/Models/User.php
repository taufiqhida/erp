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

    protected $fillable = [
        'name',
        'email',
        'password',
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
        ];
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
