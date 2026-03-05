<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $connection = 'master';
    protected $table = 'audit_log';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'client_id',
        'user_level',
        'method',
        'path',
        'payload',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
