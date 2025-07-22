<?php

namespace App\Http\Controllers;

use App\Model\Client\ListHeader;
use App\Model\Client\DripCampaignRuns;
use App\Model\Client\DripCampaignSchedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DripCampaignScheduleController extends Controller
{
    public function index(Request $request, int $id)
    {
        $campaign = DripCampaignSchedule::on("mysql_" . $request->auth->parent_id)->where('campaign_id', $id)->get()->all();
        return $this->successResponse("CampaignSchedule List", $campaign);
    }

  

    public function create(Request $request)
    {
        $clientid = $request->auth->parent_id;
        $this->validate($request, [
            'campaign_id' => 'required',
            'send' => 'required',
            'email_template_id' => 'required',
            'email_setting_id' => 'required',
            'created_by' => 'required'
        ]);

        try {
            $campaign = new DripCampaignSchedule($request->all());
            $campaign->setConnection("mysql_$clientid");
            $campaign->saveOrFail();
            return $this->successResponse("Added Successfully", $campaign->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to create Campaign ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $campaign = DripCampaignSchedule::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            //return $campaign;
            return $this->successResponse("campaignSchedule Info", $campaign->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Campaign Not Found", [
                "Invalid Campaign id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch the Campaign ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function update(Request $request, int $id)
    {
        if ($request->send == 1) {
            $this->validate($request, [
                'run_time' => 'required',
                'email_template_id' => 'required',
                'email_setting_id' => 'required',
            ]);
        } elseif ($request->send == 2) {
            $this->validate($request, [
                'run_time' => 'required',
                'sms_template_id' => 'required',
                'sms_setting_id' => 'required',
                'sms_country_code' => 'required|integer'
            ]);
        }

        try {
            $campaign = DripCampaignSchedule::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $status = $campaign->status;
            if ($status == 1) {
                if ($request->send == 1) {
                    if ($request->has("email_template_id"))
                        $campaign->email_template_id = $request->input("email_template_id");
                    if ($request->has("email_setting_id"))
                        $campaign->email_setting_id = $request->input("email_setting_id");
                } elseif ($request->send == 2) {
                    if ($request->has("sms_template_id"))
                        $campaign->sms_template_id = $request->input("sms_template_id");
                    if ($request->has("sms_setting_id"))
                        $campaign->sms_setting_id = $request->input("sms_setting_id");
                    if ($request->has("sms_country_code"))
                        $campaign->sms_country_code = $request->input("sms_country_code");
                }
                if ($request->has("run_time"))
                    $campaign->run_time = $request->input("run_time");
                $campaign->saveOrFail();
                return $this->successResponse("Campaign Update", $campaign->toArray());
            } else {
                return $this->failResponse("Edit allowed only when campaign schedule is in PLANNED status", [
                    "Schedule is in '" . MarketingCampaignSchedule::STATUSES[$campaign->status] . "' status"
                ], null, 400);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Campaign Not Found", [
                "Invalid Campaign id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Edit only when campaign schedule is in PLANNED status", [
                $exception->getMessage()
            ], $exception, 400);
        }
    }

   
}
