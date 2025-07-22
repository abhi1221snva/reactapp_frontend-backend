<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RvmCallbackConfiguration extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'rvm_callback_configuration';
    protected $fillable = ['id', 'cli', 'phone', 'callback_number', 'sip_gateway_id'];
    public $timestamps = true;
}
