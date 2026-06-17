<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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
        return $this->hasMany(Position::class, 'id', 'user_id');
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
