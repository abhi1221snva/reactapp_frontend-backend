<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;

class CustomFieldLabels extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $table = "custom_field_labels";

    protected $fillable = ['id','title'];

}
