<?php

namespace App\Modules\Auth\Models;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\RefreshToken; // Adjust if your path is differen

class User extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'pin', 'otp_pin',
        'balance', 'available_balance','transfer_pin',
        'transfer_locked',
    ];


    protected $hidden = [
        'password', 'pin', 'otp_pin', 'transfer_pin', 'remember_token',
    ];

     // Fillable and hidden remain the same

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function incrementRiskScore(int $points = 1, int $max = 100): void
    {
        $this->risk_score = min($this->risk_score + $points, $max);
        $this->save();
    }

    public function decrementRiskScore(int $points = 1, int $min = 0): void
    {
        $this->risk_score = max($this->risk_score - $points, $min);
        $this->save();
    }
    public function sentTransactions()
    {
        return $this->hasMany(\App\Modules\Transaction\Models\Transaction::class, 'sender_id');
    }

    public function receivedTransactions()
    {
        return $this->hasMany(\App\Modules\Transaction\Models\Transaction::class, 'receiver_id');
    }


    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }


}
