<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class UserPackage extends Model
{
    protected $table = 'user_packages';

    public $timestamps = false;

    protected $fillable = [
        "client_package_id",
        "free_call_minutes",
        "free_sms",
        "free_fax",
        "free_emails",
        "free_reset_time"
    ];
}
