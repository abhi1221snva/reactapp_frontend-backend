<?php
namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    protected $primaryKey = 'key';
    protected $keyType = 'string';

    protected $casts = [
        "components" => "array",
        "attributes" => "array"
    ];

    protected $fillable = [
        "key",
        "name",
        "components",
        "is_active",
        "attributes",
        "display_order"
    ];
}