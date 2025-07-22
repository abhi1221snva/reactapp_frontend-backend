<?php


namespace App\Model\Master;


use Illuminate\Database\Eloquent\Model;

class TariffLabel extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    //public $timestamps = false;

    protected $table = "tariff_label";

    protected $fillable = ['id','title','description','is_deleted'];

}
