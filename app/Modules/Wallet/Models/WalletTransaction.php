<?php
namespace App\Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'sender_id', 'receiver_id',
        'payment_type', 'transaction_type', 'status',
        'amount', 'fee', 'total', 'note', 'released_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'fee' => 'float',
        'total' => 'float',
        'released_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'receiver_id');
    }
}
