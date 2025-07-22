<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class CrmPdfLabels extends Model
{
    public $timestamps = true;
    protected $table = "create_pdf_applications";
    protected $fillable = ["pdf_label","crm_lable_id","status"];
    
}