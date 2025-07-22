<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RvmCallbackLog extends Model
{

    protected $table = 'rvm_callback_logs';

    protected $fillable = [
        'caller_number',
        'incoming_number',
        'callback_number',
        'duration',
        'start_time',
        'end_time',
        'call_recording',
    ];
}
