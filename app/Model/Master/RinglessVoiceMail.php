<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RinglessVoiceMail extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'voicemail_drop_log';
    //public $timestamps = false;
}
