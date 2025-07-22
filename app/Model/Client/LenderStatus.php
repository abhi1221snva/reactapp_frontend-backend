<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class LenderStatus extends Model
{
    public $timestamps = true;
    protected $guarded = ['id'];
    
    protected $table = "crm_lender_status";
    protected $fillable = ['title','status'];
   
}
