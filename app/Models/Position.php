<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $table = 'positions';
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope a query to only include positions belonging to users in the actor's tenant.
     * Fail-closed: if actor is null or actor is non-superadmin with empty company_id, returns empty.
     * Super admin sees all positions.
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

        return $query->whereHas('user', function ($q) use ($actor) {
            $q->where('company_id', $actor->company_id);
        });
    }

    public static function rules()
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public static function attributes()
    {
        return [
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
        ];
    }
}
