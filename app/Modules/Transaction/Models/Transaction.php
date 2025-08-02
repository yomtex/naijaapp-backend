<?php
namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'sender_id', 'receiver_id', 'amount', 'type', 'purpose',
        'status', 'reference', 'processed_at', 'note',
    ];

    protected $casts = [
        'amount' => 'float',
        'processed_at' => 'datetime',
    ];
}
