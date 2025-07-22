<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class Event extends Model
{
    public $timestamps = true;
    protected $table = "events";
    protected $fillable = ['user_id','title','color','start_date','end_date'];
    
}