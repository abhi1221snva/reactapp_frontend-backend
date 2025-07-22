<?php
namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class ModuleComponent extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    protected $primaryKey = 'key';
    protected $keyType = 'string';

    protected $fillable = [
        "key",
        "name",
        "url",
        "logo",
        "min_level",
        "display_order",
        "parent_key",
    ];
}