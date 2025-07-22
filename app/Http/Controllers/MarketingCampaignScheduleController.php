<?php

namespace App\Http\Controllers;

use App\Jobs\MarketingCampaignSchedules;
use App\Model\Client\ListHeader;
use App\Model\Client\MarketingCampaignRuns;
use App\Model\Client\MarketingCampaignSchedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MarketingCampaignScheduleController extends Controller
{
    public function index(Request $request, int $id)
    {
        $campaign = MarketingCampaignSchedule::on("mysql_" . $request->auth->parent_id)->where('campaign_id', $id)->get()->all();
        return $this->successResponse("CampaignSchedule List", $campaign);
    }

    public function createSMS(Request $request)
    {
        $clientid = $request->auth->parent_id;
        $this->validate($request, [
            'campaign_id' => 'required',
            'list_id' => 'required|integer',
            'list_column_name' => 'required',
            'send' => 'required',
            'sms_template_id' => 'required|integer',
            'sms_setting_id' => 'required|integer',
            'sms_country_code' => 'required|integer',
            'created_by' => 'required'
        ]);

        try {
            $campaign = new MarketingCampaignSchedule($request->all());
            $campaign->setConnection("mysql_$clientid");
            $campaign->saveOrFail();
            return $this->successResponse("Added Successfully", $campaign->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to create Campaign ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function create(Request $request)
    {
        $clientid = $request->auth->parent_id;
        $this->validate($request, [
            'campaign_id' => 'required',
            'list_id' => 'required',
            'list_column_name' => 'required',
            'send' => 'required',
            'email_template_id' => 'required',
            'email_setting_id' => 'required',
            'created_by' => 'required'
        ]);

        try {
            $campaign = new MarketingCampaignSchedule($request->all());
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
            $campaign = MarketingCampaignSchedule::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
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
            $campaign = MarketingCampaignSchedule::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
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

    public function abortSchedule(Request $request)
    {
        $id = $request->scheduleId;
        try {
            $connection = "mysql_" . $request->auth->parent_id;
            DB::connection($connection)->statement("UPDATE marketing_campaign_runs SET status=4 WHERE schedule_id='" . $id . "' AND status=1");

            $this->updateCounts($request->auth->parent_id, $id, 7);

            return $this->successResponse("Marketing Campaign Schedule List is Aborted", []);
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Campaign Schedule Not Found", [
                "Invalid campaign id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch the Campaign Schedule ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function deleteSchedule(Request $request)
    {
        $id = $request->scheduleId;
        try {
            $template = MarketingCampaignSchedule::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $deleted = $template->delete();
            if ($deleted) {
                return $this->successResponse("Campaign Schedule List deleted", $template->toArray());
            } else {
                return $this->failResponse("Failed to delete the Campaign ", [
                    "Unkown"
                ]);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Campaign Schedule Not Found", [
                "Invalid template id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch the Campaign Schedule ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function findListHeader(Request $request)
    {
        $headers = ListHeader::on("mysql_" . $request->auth->parent_id)->where('list_id', $request->listid)->get()->all();
        $listHeaders = [];
        foreach ($headers as $header)
            $listHeaders[$header->column_name] = $header->toArray();
        return $this->successResponse("List headers", $listHeaders);
    }

    public function resumeProcessing(Request $request, int $id)
    {
        try {
            $connection = "mysql_" . $request->auth->parent_id;
            $campaign = MarketingCampaignSchedule::on($connection)->findOrFail($id);
            $max_lead_id = MarketingCampaignRuns::on($connection)->where("schedule_id", $id)->get()->max("lead_id");
            $scheduledCount = MarketingCampaignRuns::on($connection)->where("schedule_id", $id)->get()->count();
            if (empty($max_lead_id)) $max_lead_id = 0;
            $campaign->last_lead_id = $max_lead_id;
            $campaign->scheduled_count = $scheduledCount;
            $campaign->saveOrFail();
            dispatch(new MarketingCampaignSchedules($request->auth->parent_id, $campaign->processing_id, $campaign->last_lead_id, $campaign->status))->onConnection("jobs_mc_schedules");
            return $this->successResponse("Campaign schedule retried", $campaign->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Schedule Not Found", [
                "Invalid MarketingCampaignSchedule id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to process MarketingCampaignSchedule", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function getLogs(Request $request, int $id)
    {
        $limit = $request->get('limit', 15);
        if($limit >= 100) {
            $limit = 100;
        }
        $whereClauses = [["schedule_id", "=", $id]];
        if ($request->has('status'))
            array_push($whereClauses, ["status", "=", $request->get('status')]);
        if ($request->has('to'))
            array_push($whereClauses, ["send_to", "=", $request->get('to')]);

        $connection = "mysql_" . $request->auth->parent_id;
        $result = MarketingCampaignRuns::on($connection)->where($whereClauses)->paginate($limit);
        return response()->json($result);
    }

    public function retryRun(Request $request, $id)
    {
        try {
            $connection = "mysql_" . $request->auth->parent_id;
            $run = MarketingCampaignRuns::on($connection)->findOrFail($id);
            if ($run->status == 2 and $run->start_time < Carbon::now("utc")->addMinutes(5)) {
                throw new \Exception("Record may be in processing. Wait for few minutes and retry.");
            }
            $run->status = 1;
            $run->saveOrFail();

            $this->updateCounts($request->auth->parent_id, $run->schedule_id);

            return $this->successResponse("Campaign schedule log retried", $run->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Schedule log not found", [
                "Invalid marketing campaign log id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to process marketing campaign log id $id", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function updateCounts(int $parentId, $id, int $status = null)
    {
        $connection = "mysql_$parentId";
        $campaign = MarketingCampaignSchedule::on($connection)->findOrFail($id);

        $recordSentCount = MarketingCampaignRuns::on($connection)->where('status', '=', 3)->where('schedule_id', $id)->count();
        $recordFailedCount = MarketingCampaignRuns::on($connection)->whereIn('status', [5, 6])->where('schedule_id', $id)->count();

        $campaign->sent_count = $recordSentCount;
        $campaign->failed_count = $recordFailedCount;
        if ($status) $campaign->status = $status;
        $campaign->saveOrFail();

        return $campaign;
    }
}
