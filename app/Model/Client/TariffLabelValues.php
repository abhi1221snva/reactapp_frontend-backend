<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;

class TariffLabelValues extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
   // public $timestamps = false;

    protected $table = "tariff_label_values";

    protected $fillable = ['id','tariff_id','phone_countries_id','rate'];

}
