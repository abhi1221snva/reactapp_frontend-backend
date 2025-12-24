<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class GatewayCredential extends Model
{
    use SoftDeletes;
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = "gateway_credentials";  
    protected $fillable = [
        'uuid',
        'user_uuid',
        'provider',
        'type',
        'credentials'
    ];

    protected $casts = [
        'credentials' => 'array'
    ];
      
}
