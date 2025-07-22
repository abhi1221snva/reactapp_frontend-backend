<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class Fcs extends Model
{
    public $timestamps = true;
    protected $table = "fcs_lendor";
    protected $fillable = ['lead_id','bank_name','bank_id','month','deposits','adjustment','revenue','adb','deposits2','nsfs','negatives','ending_balance'];
    
}