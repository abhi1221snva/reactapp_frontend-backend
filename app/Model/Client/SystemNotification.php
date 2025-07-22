<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class SystemNotification extends Model
{
    public $timestamps = false;

    protected $table = "system_notifications";

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'notification_id';

    protected $keyType = 'string';

    protected $casts = [
        "subscribers" => "array"
    ];

    protected $fillable = [
        'notification_id',
        'active',
        'active_sms',
        'subscribers'
    ];
}
