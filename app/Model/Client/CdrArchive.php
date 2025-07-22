<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;

class CdrArchive extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $table = "cdr_archive";
}
