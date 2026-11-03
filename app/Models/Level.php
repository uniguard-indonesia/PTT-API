<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Scope a query to only include levels assignable by the given actor.
     * Fail-closed: if actor is null or actor is non-superadmin with empty company_id, returns empty.
     * Super admin can assign all levels. Non-superadmins can assign standard levels (id > LEVEL_SUPER_ADMIN).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \App\Models\User|null  $actor
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAssignableBy(Builder $query, ?User $actor = null): Builder
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

        return $query->where('id', '>', User::LEVEL_SUPER_ADMIN);
    }

    public static function rules()
    {
        return [
            'name' => 'required|max:64',
            'menu' => 'required'
        ];
    }

    public static function attributes()
    {
        return [
            'name' => 'Name',
            'menu' => 'Menu'
        ];
    }
}
