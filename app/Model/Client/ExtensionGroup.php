<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;

class ExtensionGroup extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $table = "extension_group";

    protected $hidden = ["is_deleted"];
}
