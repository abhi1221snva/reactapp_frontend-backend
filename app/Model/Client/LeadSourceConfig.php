<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class LeadSourceConfig extends Model
{
    public $timestamps = true;
	//protected $primaryKey = 'api_key'; // or null


    protected $table = "lead_source_config";
    protected $fillable = [
        "api_key",
        "description",
        "list_id",
        "title"
    ];
}
