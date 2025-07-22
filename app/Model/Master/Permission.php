<?php


namespace App\Model\Master;


use App\Model\Traits\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasCompositePrimaryKey;
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    protected $casts = [
        "roles" => "array"
    ];

    protected $primaryKey = ['user_id', 'client_id'];
}
