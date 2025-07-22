<?php
namespace App\Http\Controllers;
use App\Model\Client\DripCampaigns;
use App\Model\Client\DripCampaignSchedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CrmDripCampaignController extends Controller
{

    public function index(Request $request)
{
    $campaign = DripCampaigns::on("mysql_" . $request->auth->parent_id)
        ->join('drip_campaign_schedules', 'drip_campaigns.id', '=', 'drip_campaign_schedules.campaign_id')
        ->select(
            'drip_campaigns.id as campaign_id', // Alias the id column from drip_campaigns
            'drip_campaigns.status as campaign_status', // Alias the status column from drip_campaigns
            'drip_campaign_schedules.status as schedule_status', // Alias the status column from drip_campaign_schedules
            'drip_campaigns.*', // Other fields from drip_campaigns
            'drip_campaign_schedules.id as schedule_id', // Alias the id column from drip_campaign_schedules
            'drip_campaign_schedules.*' // Other fields from drip_campaign_schedules
        )
        ->get()
        ->toArray();

    return $this->successResponse("Campaigns List", $campaign);
}

    // public function index(Request $request)
    // {
    //     $campaign = DripCampaigns::on("mysql_" . $request->auth->parent_id)
    //     ->join('drip_campaign_schedules', 'drip_campaigns.id', '=', 'drip_campaign_schedules.campaign_id')
    //     ->select(
    //         'drip_campaigns.id as campaign_id', // Alias the id column from drip_campaigns
    //         'drip_campaigns.*', // Other fields from drip_campaigns
    //         'drip_campaign_schedules.id as schedule_id', // Alias the id column from drip_campaign_schedules
    //         'drip_campaign_schedules.*' // Other fields from drip_campaign_schedules
    //     )
    //     ->get()->toArray();
    
    //     return $this->successResponse("Campaigns List", $campaign);
    // }

    public function create(Request $request)
    {
        Log::info('reached',[$request->all()]);
        $clientid = $request->auth->parent_id;
        $this->validate($request, [
            "title" => "required|string|unique:mysql_$clientid.marketing_campaigns",
            "description"=>"required|string"
        ]);
        try {
            $campaign = new DripCampaigns;

            $campaign->setConnection("mysql_$clientid");
            $campaign->title = $request->title;
            $campaign->description = $request->description;
            $campaign->saveOrFail();
            $dripcampaign = new DripCampaignSchedule;
            $dripcampaign->setConnection("mysql_$clientid");
            $dripcampaign->campaign_id = $campaign->id;
            $dripcampaign->send = $request->send;
            $dripcampaign->email_setting_id = $request->email_setting_id;
            $dripcampaign->email_template_id = $request->email_template_id;
            $dripcampaign->run_time = $request->run_time;
            $dripcampaign->processing_id = $request->processing_ids;
            $dripcampaign->created_by = $request->created_by;
            $dripcampaign->complete_time = $request->complete_time;
            $dripcampaign->lead_status_id = $request->lead_status_id;
            $dripcampaign->schedule = $request->schedule;
            $dripcampaign->schedule_day = $request->schedule_day;

            $dripcampaign->saveOrFail();
            return $this->successResponse("Added Successfully", $campaign->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Campaign ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

   

    public function show(Request $request, int $id)
    {
        try {
            $campaignConnection = "mysql_" . $request->auth->parent_id;
    
            // Retrieve the campaign with its associated schedule where campaign_id matches
            $campaign = DripCampaigns::on($campaignConnection)
                ->where('id', $id)
                ->with(['schedule' => function ($query) {
                    $query->select('*'); // Retrieve all columns from `drip_campaign_schedules`
                }])
                ->firstOrFail();
    
            return $this->successResponse("Campaign Info", $campaign->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Campaign Not Found", [
                "Invalid Campaign id $id"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the Campaign", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }
    
    

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'title' => 'string',
            'description' => 'string'
        ]);

        try
        {
            $campaign = DripCampaigns::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            if ($request->has("title"))
                $campaign->title = $request->input("title");
            if ($request->has("description"))
                $campaign->description = $request->input("description");
            $campaign->saveOrFail();
            $dripcampaign = DripCampaignSchedule::on("mysql_" . $request->auth->parent_id)
            ->where('campaign_id', $id)
            ->firstOrFail();  
            if ($request->has("email_setting_id"))
                $dripcampaign->email_setting_id = $request->input("email_setting_id");
                if ($request->has("email_template_id"))
                $dripcampaign->email_template_id = $request->input("email_template_id");
                if ($request->has("run_time"))
                $dripcampaign->run_time = $request->input("run_time");

                $dripcampaign->created_by = $request->auth->id;
                if ($request->has("lead_status_id"))
                $dripcampaign->lead_status_id = $request->input("lead_status_id");
                if ($request->has("schedule"))
                $dripcampaign->schedule = $request->input("schedule");
                if ($request->has("schedule_day"))
                $dripcampaign->schedule_day = $request->input("schedule_day");
            $dripcampaign->saveOrFail();
            
            return $this->successResponse("Campaign Update", $campaign->toArray());
        }
        catch (ModelNotFoundException $exception)
        {
            return $this->failResponse("Campaign Not Found", [
                "Invalid Campaign id $id"
            ], $exception, 404);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to update Campaign", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
    function updateDripStatus(Request $request) {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = DripCampaigns::on('mysql_' . $request->auth->parent_id)
        ->where('id', $listId) // Use the actual listId received from the request
        ->update(array('status' => $status));
     

if ($saveRecord > 0) {
    return response()->json([
        'success'=>'true',
        'status' => 'true',
        'message' => 'drip status updated successfully'
    ]);
} else {
    return response()->json([
        'success'=>'false',
        'status' => 'false',
        'message' => 'drip status  update failed'
    ]);
        }
    }
   
}
