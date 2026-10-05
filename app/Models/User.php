<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Roles are never mass assignable: role escalation must go through an audited service. */
    protected $fillable = ['name', 'email', 'password', 'phone'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function investor(): HasOne
    {
        return $this->hasOne(Investor::class);
    }

    public function business(): HasOne
    {
        return $this->hasOne(Business::class);
    }

    public function wakilProfile(): HasOne
    {
        return $this->hasOne(WakilProfile::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function isStaffMember(): bool
    {
        return $this->hasAnyRole([UserRole::Admin->value, UserRole::Manager->value, UserRole::Staff->value]);
    }

    public function isInvestor(): bool
    {
        return $this->hasRole(UserRole::Investor->value);
    }

    public function isBusiness(): bool
    {
        return $this->hasRole(UserRole::Business->value);
    }

    public function isWakil(): bool
    {
        return $this->hasRole(UserRole::Wakil->value);
    }

    /** Portal the user lands in after login. */
    public function homeRoute(): string
    {
        return match (true) {
            $this->isStaffMember() => 'admin.dashboard',
            $this->isBusiness() => 'business.dashboard',
            $this->isWakil() => 'wakil.appointments',
            default => 'investor.dashboard',
        };
    }
}
