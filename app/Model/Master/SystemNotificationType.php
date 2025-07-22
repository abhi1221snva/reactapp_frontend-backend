<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class SystemNotificationType extends Model
{
    public $timestamps = false;

    protected $connection = 'master';

    protected $table = "system_notification_types";

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'type',
        'display_order',
        'content'
    ];
}
