<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomTemplates extends Model
{
    use SoftDeletes;
    public $timestamps = true;
    protected $table = "crm_custom_templates";
    protected $fillable = ['template_name','template_html','custom_type'];
    protected $dates = ['deleted_at'];

}
