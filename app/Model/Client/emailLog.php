<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class emailLog extends Model
{
	public $timestamps = false;
    protected $table = "email_logs";

    protected $fillable = ['user_id'];

    protected $casts = [
        'cc' => 'array',
        'bcc' => 'array',
         'attachments' => 'array',
    ];

}
