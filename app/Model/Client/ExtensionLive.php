<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;

class ExtensionLive extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $table = "extension_live";

    protected $primaryKey = "extension";
}
