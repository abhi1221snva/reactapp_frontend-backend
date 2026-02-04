<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class UserFcmToken extends Model
{
    protected $connection = 'master';
    protected $table = 'user_fcm_tokens';

    protected $fillable = [
        'user_id',
        'device_token',
        'device_type',
        'last_used_at'
    ];

    /**
     * Relationship with User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
