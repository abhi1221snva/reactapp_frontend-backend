<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
use DB;

class CallMatrixReport extends Model
{
    public $timestamps = false;

    protected $table = "call_matrix_report";
    
   protected $fillable = [
        'report_type',
        'category',
        'score',
        'score_display',
        'notes',
        'summary_emoji',
        'summary_description',
        'coaching_description',
        'total_score',
        'max_score',
        'percentage',
        'average_score',
        'cdr_id',
        'response_data'
    ];    

    

  

}
