<?php

namespace App\Models;

use App\Enums\DemoIdentity;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'demo_identity' => DemoIdentity::class,
        ];
    }

    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isDemo(): bool
    {
        return $this->demo_identity !== null;
    }

    public function demoRestricted(): bool
    {
        return config('app.demo_mode') && $this->isDemo();
    }

    public function sendPasswordResetNotification(mixed $token): void
    {
        if (! $this->isDemo()) {
            parent::sendPasswordResetNotification($token);
        }
    }

    /** @return HasMany<Ticket, $this> */
    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by');
    }

    /** @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }
}
