<?php

namespace App\Http\Controllers;
use App\Model\Client\LeadStatus;
use App\Model\Client\Lead;
use App\Model\Client\CrmLabel;
use App\Model\Client\Dids;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CrmdashboardController extends Controller
{
    public function index(Request $request)
    {

        $dashboard = array();
        try
        {
            $clientId = $request->auth->parent_id;
            $leadstatus = [];
            $level = $request->auth->user_level;
            if($level > 1)
            {
                $leadstatus = Lead::on("mysql_$clientId")->groupBy('lead_status')->select('lead_status', DB::raw('count(*) as total_lead_status'))->get()->all();
                // $totalLeads = Lead::on("mysql_$clientId")->count();
                $totalDids  =  0;//Dids::on("mysql_$clientId")->count();
                $totalSMS  =  0;//Dids::on("mysql_$clientId")->count();
                





            }
            else
            {
                $leadstatus = Lead::on("mysql_$clientId")->where('assigned_to',$request->auth->id)->groupBy('lead_status')->select('lead_status', DB::raw('count(*) as total_lead_status'))->get()->all();

                $totalLeads = Lead::on("mysql_$clientId")->count();
                $totalDids  =  0;//Dids::on("mysql_$clientId")->count();
                $totalSMS  =  0;//Dids::on("mysql_$clientId")->count();





            }
            foreach($leadstatus as $key=> $leads)
            {
                $lead_status = LeadStatus::on("mysql_$clientId")->where('lead_title_url',$leads->lead_status)->get()->first();
               // $leadstatus[$key]['color_code'] = $lead_status->color_code;
                //$leadstatus[$key]['image'] = $lead_status->image;
            }

            $dashboard['leadstatus'] = $leadstatus;
            // $dashboard['totalLeads'] = $totalLeads;
            $dashboard['totalDids']  = $totalDids;
            $dashboard['totalSMS']   = $totalSMS;







            return $this->successResponse("Label Status", $dashboard);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to list extension groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
}