<?php


namespace App\Model\Client;


use App\Model\Traits\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;

class ExtensionGroupMap extends Model
{
    use HasCompositePrimaryKey;

    protected $table = "extension_group_map";

    protected $primaryKey = ['extension', 'group_id'];

    public $timestamps = false;
}
