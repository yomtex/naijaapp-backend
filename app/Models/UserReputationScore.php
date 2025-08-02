<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReputationScore extends Model
{
    protected $fillable = ['reporter_id', 'reported_id', 'score'];

    public function reporter() {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reported() {
        return $this->belongsTo(User::class, 'reported_id');
    }
}
