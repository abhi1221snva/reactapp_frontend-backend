<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class UserMenu extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $connection = 'master';

    protected $table = "user_menu";
}
