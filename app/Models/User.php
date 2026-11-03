<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use Illuminate\Database\Eloquent\Builder;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const LEVEL_SUPER_ADMIN = 0;
    public const LEVEL_COMPANY_ADMIN = 1;
    public const LEVEL_USER = 2;

    /**
     * Check if the user is a Super Admin.
     *
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return $this->level_id !== null && (int) $this->level_id === self::LEVEL_SUPER_ADMIN;
    }

    /**
     * Check if the user is a Company Admin.
     *
     * @return bool
     */
    public function isCompanyAdmin(): bool
    {
        return $this->level_id !== null && (int) $this->level_id === self::LEVEL_COMPANY_ADMIN;
    }

    /**
     * Check if the user is a regular User.
     *
     * @return bool
     */
    public function isRegularUser(): bool
    {
        return $this->level_id !== null && (int) $this->level_id === self::LEVEL_USER;
    }

    /**
     * Scope a query to only include users manageable by the given actor.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @param  \App\Models\User|null  $actor
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeManageableBy(Builder $q, ?User $actor = null): Builder
    {
        if (!$actor) {
            return $q->whereRaw('0 = 1');
        }

        if ($actor->isSuperAdmin()) {
            return $q;
        }

        if (empty($actor->company_id)) {
            return $q->whereRaw('0 = 1');
        }

        return $q->where('company_id', $actor->company_id)
            ->where('level_id', '>', self::LEVEL_SUPER_ADMIN);
    }

    /**
     * Scope a query to only include users belonging to the actor's tenant.
     * Fail-closed: if actor is null or actor is non-superadmin with empty company_id, returns empty.
     * Super admin sees all users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \App\Models\User|null  $actor
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInTenantOf(Builder $query, ?User $actor = null): Builder
    {
        if (!$actor) {
            return $query->whereRaw('0 = 1');
        }

        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if (empty($actor->company_id)) {
            return $query->whereRaw('0 = 1');
        }

        $table = $query->getModel()->getTable();
        return $query->where("{$table}.company_id", $actor->company_id);
    }

    protected static function booted()
    {
        static::created(function ($user) {
            \App\Services\MumbleService::generateAndRegister($user);
        });

        static::deleting(function ($user) {
            if ($user->certificate) {
                $filePath = public_path($user->certificate->certificate_path);
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }
        });

        static::deleted(function ($user) {
            \App\Services\MumbleService::deregisterUserFromMumble($user);
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['name', 'email', 'password', 'position_id', 'level_id', 'photo', 'company_id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function servers()
    {
        return $this->hasMany(ServerCompany::class, 'company_id', 'company_id');
    }

    public function certificate()
    {
        return $this->hasOne(Certificate::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function positions()
    {
        return $this->hasMany(Position::class, 'user_id', 'id');
    }
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function level()
    {
        return $this->belongsTo(Level::class, 'level_id');
    }
}
