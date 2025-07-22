<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class Documents extends Model
{
    public $timestamps = true;
    protected $table = "crm_documents";
    protected $fillable = ['document_name','document_type','file_name'];
    
}
