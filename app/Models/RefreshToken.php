<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Auth\Models\User;

class RefreshToken extends Model
{
    protected $fillable = ['user_id', 'token', 'expires_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
