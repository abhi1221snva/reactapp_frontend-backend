<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    public $timestamps = true;
    protected $table="schedule_list";
    protected $fillable=['user_id','title','description','start_datetime','end_datetime','timezone'];

}

