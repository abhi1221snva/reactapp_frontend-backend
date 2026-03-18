<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * In-app notification for admin/agent users.
 *
 * @property int         $id
 * @property int|null    $lead_id
 * @property int|null    $recipient_user_id
 * @property string      $type
 * @property string|null $title
 * @property string      $message
 * @property bool        $is_read
 * @property string|null $read_at
 * @property array|null  $meta
 */
class CrmNotification extends Model
{
    protected $table = 'crm_notifications';

    protected $fillable = [
        'lead_id',
        'recipient_user_id',
        'type',
        'title',
        'message',
        'is_read',
        'read_at',
        'meta',
    ];

    protected $casts = [
        'lead_id'           => 'integer',
        'recipient_user_id' => 'integer',
        'is_read'           => 'boolean',
        'meta'              => 'array',
    ];
}
