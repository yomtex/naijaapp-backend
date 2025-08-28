<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Auth\Models\User;

class RefreshToken extends Model
{
    protected $fillable = [
        'user_id','token','expires_at',
        'device_id','device_name','platform','fcm_token',
        'revoked_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function scopeActive($q) {
        return $q->whereNull('revoked_at')->where('expires_at','>', now());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
