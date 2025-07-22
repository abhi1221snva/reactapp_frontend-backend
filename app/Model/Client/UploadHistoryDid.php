<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;

class UploadHistoryDid extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
   // public $timestamps = false;

    protected $table = "upload_history_did";

    protected $fillable = ['id','user_id','file_name','upload_url','url_title'];

}
