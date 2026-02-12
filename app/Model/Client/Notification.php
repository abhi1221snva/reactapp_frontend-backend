<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class Notification extends Model
{
    public $timestamps = true;
    protected $table = "crm_notifications";

    protected $guarded = ['id'];
}
