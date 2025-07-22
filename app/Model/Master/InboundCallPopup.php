<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class InboundCallPopup extends Model
{
    protected $connection = 'master';
    protected $table = 'inbound_call_popup';
}
