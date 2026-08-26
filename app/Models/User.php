<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * Deliberately excludes `role` and `team_id`: these determine
     * authorization and must never be settable from arbitrary request
     * input. They are only ever written explicitly by
     * UserManagementService / TeamManagementService, which are gated by
     * UserPolicy/TeamPolicy (Manager only).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * The team this user belongs to (Team Head or Team Member). Null for a
     * Manager, who has organisation-wide visibility rather than team
     * membership.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The team this user currently heads, if any.
     */
    public function headedTeam(): HasOne
    {
        return $this->hasOne(Team::class, 'team_head_id');
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    public function isTeamHead(): bool
    {
        return $this->role === UserRole::TeamHead;
    }

    public function isTeamMember(): bool
    {
        return $this->role === UserRole::TeamMember;
    }
}
