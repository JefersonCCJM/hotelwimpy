<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'allowed_ip',
        'working_hours',
        'security_pin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'security_pin',
    ];

    /**
     * Verify the user's security PIN.
     */
    public function verifyPin(string $pin): bool
    {
        return $this->security_pin === $pin;
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'working_hours' => 'array',
        ];
    }

    /**
     * Get the shift handovers delivered by the user.
     */
    public function turnosEntregados(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ShiftHandover::class, 'entregado_por');
    }

    /**
     * Get the shift handovers received by the user.
     */
    public function turnosRecibidos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ShiftHandover::class, 'recibido_por');
    }

    /**
     * Get the current active shift handover for the user.
     */
    public function turnoActivo(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ShiftHandover::class, 'entregado_por')
            ->where('status', \App\Enums\ShiftHandoverStatus::ACTIVE);
    }

    public function externalIncomes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExternalIncome::class);
    }
}
