<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class TeamMessageReadReceipt extends Model
{
    protected $table = 'team_message_read_receipts';

    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'user_id',
        'read_at'
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    protected $dates = [
        'read_at'
    ];

    public function message()
    {
        return $this->belongsTo(TeamMessage::class, 'message_id');
    }
}
