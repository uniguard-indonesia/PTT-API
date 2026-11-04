<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasFactory;

    protected $table = 'servers';
    protected $guarded = ['id'];
    protected $hidden = ['password'];

    public static function rules()
    {
        return [
            'name' => 'required|max:128',
            'host' => 'required|max:255',
            'port' => 'numeric|required',
            'username' => 'nullable|max:128',
            'password' => 'nullable|max:128',
        ];
    }
    public static function attributes()
    {
        return [
            'name' => 'Name',
            'host' => 'Host',
            'port' => 'Port',
            'username' => 'Username',
            'password' => 'Password',
        ];
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'server_companies', 'server_id', 'company_id');
    }
}
