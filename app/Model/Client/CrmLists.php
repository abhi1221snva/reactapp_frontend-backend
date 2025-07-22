<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class CrmLists extends Model
{
  use SoftDeletes;
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
   // public $timestamps = false;

    protected $table = "crm_lists";

    protected $fillable = ['id','title','title_url','url','key','is_deleted','deleted_at'];

}
