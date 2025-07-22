<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;


class CrmLeadStatus extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    protected $table = "crm_lead_status";

    protected $fillable = ['id','title','lead_title_url','status','color_code','image','display_order','view_on_dashboard'];

}