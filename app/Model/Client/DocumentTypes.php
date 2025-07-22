<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class DocumentTypes extends Model
{
    public $timestamps = true;
    protected $table = "crm_documents_types";
    protected $fillable = ['title','type_title_url','values','status'];
    
}
