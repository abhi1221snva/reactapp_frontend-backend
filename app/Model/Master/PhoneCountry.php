<?php
namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class PhoneCountry extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    

    protected $primaryKey = 'id';

    protected $fillable = [
        "id",
        "phone_code",
        "country_code",
        "country_name"
    ];
}