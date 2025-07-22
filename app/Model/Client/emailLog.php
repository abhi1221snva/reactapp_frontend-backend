<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class emailLog extends Model
{
	public $timestamps = false;
    protected $table = "email_logs";
}
