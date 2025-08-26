<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class CallTimer extends Model
{
    protected $table = 'call_timers';

    protected $fillable = [
        'title',
        'description',
        'week_plan'
    ];

    protected $casts = [
        'week_plan' => 'array', // JSON → array
    ];
}
