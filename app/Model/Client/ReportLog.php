<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class ReportLog  extends Model
{
    protected $table = "report_logs";

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['report_name', 'report_date', 'sent_to_email','data', 'view_file', 'source'];

    protected $casts = [
        'data' => 'array'
    ];
}
