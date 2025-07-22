<?php


namespace App\Model\Master;


use Illuminate\Database\Eloquent\Model;

class Timezone extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    //public $timestamps = false;

    protected $table = "timezone";

    protected $fillable = ['areacode','timezone','timezone_name'];

}
