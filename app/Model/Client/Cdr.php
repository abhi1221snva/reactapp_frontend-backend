<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;

class Cdr extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $table = "cdr";
}
