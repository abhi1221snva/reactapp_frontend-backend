<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class SmsTemplate  extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $table = "sms_templete";
    protected $primaryKey = 'templete_id';

    protected $fillable = ['template_name', 'templete_desc'];
}
