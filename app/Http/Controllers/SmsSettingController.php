<?php

namespace App\Http\Controllers;

use App\Exceptions\RenderableException;
use App\Model\Client\Campaign;
use App\Model\Client\SmsSetting;
use App\Model\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SmsSettingController extends Controller
{

    public function smtpByUserId(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $smtp = SmsSetting::on("mysql_$clientId")->where("user_id","=", $request->auth->id)->first();
        return $this->successResponse("SMTP List", $smtp->toArray());
    }


    public function index(Request $request)
    {
        $clientId = $request->auth->parent_id;
        if ($request->auth->level < 7) {
            $sms = SmsSetting::on("mysql_$clientId")->where("user_id","=", $request->auth->id)->get()->all();
        } else {
            $sms = SmsSetting::on("mysql_$clientId")
                ->orderBy("sender_type")
                ->get()->all();
        }
        return $this->successResponse("SMS List", $sms);
    }

    public function show(Request $request, int $id)
    {
        try {
            $smtp = SmsSetting::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            return $this->successResponse("SMTP setting", $smtp->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Smtp setting not found", ["Invalid smtp id $id"], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Smtp not Found", [$exception->getMessage()], $exception, 500);
        }
    }

    public function query(Request $request, string $senderType)
    {
        try {
            $records = [];
            $where = [];
            array_push($where, ["sender_type", "=", $senderType]);
            if ($request->has("campaign_id")) {
                array_push($where, ["campaign_id", "=", $request->get("campaign_id")]);
            }
            if ($request->has("user_id")) {
                array_push($where, ["user_id", "=", $request->get("user_id")]);
            }
            $smtps = SmsSetting::on("mysql_" . $request->auth->parent_id)->where($where)->get()->all();
            foreach ( $smtps as $smtp ) {
                $record = $smtp->toArray();
                unset($record["mail_password"]);
                $records[] = $record;
            }
            return $this->successResponse("Query result", $records);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to query smtp settings", [$exception->getMessage()], $exception, 500);
        }
    }

    public function create(Request $request)
    {
        $this->validate($request, [
            'sms_url' => 'required|string|max:255',
            'sender_name' => 'required|string|max:255',
            'api_key' => 'required|string',
            'sender_type' => 'required|string',
            'user_id' => 'required_if:sender_type,user|nullable|int',
            //'campaign_id' => 'required_if:sender_type,campaign|nullable|string',
        ]);
        $this->validateCreate($request);

        try {
            $input = $request->all();
            $smtp = new SmsSetting();
            $smtp->setConnection("mysql_" . $request->auth->parent_id);
            if (!empty($input["sms_url"])) $smtp->sms_url = $input["sms_url"];
            if (!empty($input["sender_name"])) $smtp->sender_name = $input["sender_name"];
            if (!empty($input["api_key"])) $smtp->api_key = $input["api_key"];
            if (!empty($input["sender_type"])) $smtp->sender_type = $input["sender_type"];          
            if (!empty($input["user_id"])) $smtp->user_id = $input["user_id"];
            if (!empty($input["campaign_id"])) $smtp->campaign_id = $input["campaign_id"];
            $smtp->saveOrFail();
            return $this->successResponse("Added Successfully", $smtp->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save SMS setting", [$exception->getMessage()], $exception, 500);
        }
    }

    public function update(Request $request, int $id)
    {

        $this->validate($request, [
            'sms_url' => 'required|string|max:255',
            'sender_name' => 'required|string|max:255',
            'api_key' => 'required|string',
            'sender_type' => 'required|string',
            'user_id' => 'required_if:sender_type,user|nullable|int',
            //'campaign_id' => 'required_if:sender_type,campaign|nullable|string',
        ]);
        $this->validateCreate($request, $id);

        try {
            $input = $request->all();
            $smtp = SmsSetting::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            if (!empty($input["sms_url"])) $smtp->sms_url = $input["sms_url"];
            if (!empty($input["sender_name"])) $smtp->sender_name = $input["sender_name"];
            if (!empty($input["api_key"])) $smtp->api_key = $input["api_key"];
            if (!empty($input["sender_type"])) $smtp->sender_type = $input["sender_type"];          
            if (!empty($input["user_id"])) $smtp->user_id = $input["user_id"];
            if (!empty($input["campaign_id"])) $smtp->campaign_id = $input["campaign_id"];
            $smtp->saveOrFail();

            return $this->successResponse("SMS Setting Update", $smtp->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("SMS Not Found", ["Invalid SMS id $id"], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update SMS", [$exception->getMessage()], $exception, 404);
        }
    }

    private function validateCreate(Request $request, $id = null)
    {
        $senderType = $request->get("sender_type");
        Log::info("SmsSettingController.validateCreate($id)", $request->all());

        /**
         * if id blank and system -> no system entry should be present
         * if id passed and system -> system entry should have same id as passed one
         */
        if ($senderType == "system") {
            if ($request->auth->level < 7) {
                throw new RenderableException("You are not authorized to add/update setting for System Emails", [], 401);
            }

            $smtps = SmsSetting::on("mysql_" . $request->auth->parent_id)->where("sender_type", "=", $senderType)->get()->all();
            if (empty($smtps)) return true;

            foreach ( $smtps as $smtp ) {
                if ($smtp->id == $id) return true;
            }
            throw new RenderableException("Only one setting allowed for System Emails", [], 400);
        }

        /**
         * if id blank and campaign -> no campaign and campaign_id entry should be present
         * if id passed and campaign ->  campaign and campaign_id should have same id as passed one
         */
        if ($senderType == "campaign") {
            if ($request->auth->level < 7) {
                throw new RenderableException("You are not authorized to add/update setting for Campaign Emails", [], 401);
            }
            $campaignId = $request->get("campaign_id");
            $campaign = Campaign::on("mysql_" . $request->auth->parent_id)->find($campaignId);

            $smtps = SmsSetting::on("mysql_" . $request->auth->parent_id)->where([["sender_type", "=", $senderType], ["campaign_id", "=", $campaignId]])->get()->all();
            if (empty($smtps)) return true;
            if (count($smtps) > 1) {
                throw new RenderableException("Campaign Emails setting already present for campaign " . $campaign->title, [], 400);
            }

            foreach ( $smtps as $smtp ) {
                if ($smtp->id == $id) return true;
            }
            throw new RenderableException("Campaign Emails setting already present for campaign " . $campaign->title, [], 400);
        }

        /**
         *
         * if id blank and user -> no user and user_id entry should be present
         * if id passed and user ->  user and user_id should have same id as passed one
         */
        if ($senderType == "user") {
            $userId = (int)$request->get("user_id");
            $user = User::find($userId);
            if ($request->auth->level < 7 && ($userId !== $request->auth->id)) {
                throw new RenderableException("You are not authorized to add/update setting for " . $user->first_name . " " . $user->last_name, [], 401);
            }

            $smtps = SmsSetting::on("mysql_" . $request->auth->parent_id)->where([["sender_type", "=", $senderType], ["user_id", "=", $userId]])->get()->all();
            if (empty($smtps)) return true;
            if (count($smtps) > 1) {
                throw new RenderableException("User Emails setting already present for " . $user->first_name . " " . $user->last_name, [], 400);
            }

            foreach ( $smtps as $smtp ) {
                if ($smtp->id == $id) return true;
            }
            throw new RenderableException("User Emails setting already present for " . $user->first_name . " " . $user->last_name, [], 400);
        }

        return true;
    }

    public function delete(Request $request, int $id)
    {
        try {
            $smtp = SmsSetting::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            #admin and below should be able to delete his own setting only
            if ($request->auth->level < 7) {
                if (($smtp->sender_type != "user") || ($smtp->user_id != $request->auth->id)) {
                    return $this->failResponse("You are not authorized to delete setting for other users", [], null, 401);
                }
            }
            $smtp->forceDelete();
            return $this->successResponse("SMS setting deleted", $smtp->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("SMS setting not found", ["Invalid smtp id $id"], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to delete", [$exception->getMessage()], $exception, 500);
        }
    }
}
