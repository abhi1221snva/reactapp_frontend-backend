<?php

namespace App\Http\Controllers;

use App\Exceptions\RenderableException;
use App\Model\Client\Campaign;
use App\Model\Client\SmtpSetting;
use App\Model\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SmtpController extends Controller
{

    public function smtpByUserId(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $smtp = SmtpSetting::on("mysql_$clientId")->where("user_id", "=", $request->auth->id)->first();
        return $this->successResponse("SMTP List", $smtp->toArray());
    }

    /**
     * 
     * 
     */
    /**
     * @OA\Get(
     *     path="/smtps",
     *     summary="Retrieve SMTP settings",
     *     tags={"SMTP"},
     *     security={{"Bearer":{}}},
     *    @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         description="Search term to filter smtps",
     *         @OA\Schema(type="string")
     *     ),
     *       @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="extension data"  
     *     )
     * )
     */
    public function index(Request $request)
    {
        $clientId = $request->auth->parent_id;

        if ($request->auth->level < 7) {
            $baseQuery = SmtpSetting::on("mysql_$clientId")->where("user_id", "=", $request->auth->id);
        } else {
            $baseQuery = SmtpSetting::on("mysql_$clientId")->orderBy("sender_type")->orderBy("mail_username");
        }

        // 🔍 Search handling
        if ($request->has('search')) {
            $searchTerm = $request->input('search');

            $query = clone $baseQuery; // clone to avoid modifying the original if reused later

            $query->where(function ($q) use ($searchTerm) {
                $q->where('mail_username', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('mail_driver', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('mail_host', 'LIKE', "%{$searchTerm}%");
            });

            $allResults = $query->get()->all();
            $total_row = count($allResults);

            // Optional: add pagination to search results
            if ($request->has('start') && $request->has('limit')) {
                $start = (int)$request->input('start');
                $limit = (int)$request->input('limit');
                $allResults = array_slice($allResults, $start, $limit, false);
            }

            return $this->successResponse("SMTP List", [
                'total' => $total_row,
                'data' => $allResults
            ]);
        }

        // 🧾 Default listing with pagination
        $smtp = $baseQuery->get()->all();

        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($smtp);
            $start = (int)$request->input('start');
            $limit = (int)$request->input('limit');
            $smtp = array_slice($smtp, $start, $limit, false);

            return $this->successResponse("SMTP List", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $smtp
            ]);
        }

        return $this->successResponse("SMTP List", $smtp);
    }

    public function index_old(Request $request)
    {
        $clientId = $request->auth->parent_id;
        if ($request->auth->level < 7) {
            $smtp = SmtpSetting::on("mysql_$clientId")->where("user_id", "=", $request->auth->id)->get()->all();
        } else {
            $smtp = SmtpSetting::on("mysql_$clientId")
                ->orderBy("sender_type")
                ->orderBy("mail_username")
                ->get()->all();
        }
        return $this->successResponse("SMTP List", $smtp);
    }


    /**
     * @OA\Get(
     *     path="/smtp/{id}",
     *     summary="Retrieve a specific SMTP setting",
     *     description="Fetches the details of a specific SMTP configuration by ID for the authenticated client.",
     *     tags={"SMTP"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the SMTP setting",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="SMTP setting found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMTP setting"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="mail_driver", type="string", example="smtp"),
     *                 @OA\Property(property="mail_host", type="string", example="smtp.mailtrap.io"),
     *                 @OA\Property(property="mail_port", type="string", example="2525"),
     *                 @OA\Property(property="mail_username", type="string", example="user@mailtrap.io"),
     *                 @OA\Property(property="mail_encryption", type="string", example="tls"),
     *                 @OA\Property(property="from_email", type="string", example="no-reply@example.com"),
     *                 @OA\Property(property="from_name", type="string", example="Example App"),
     *                 @OA\Property(property="sender_type", type="string", example="user"),
     *                 @OA\Property(property="user_id", type="integer", example=123),
     *                 @OA\Property(property="campaign_id", type="integer", example=456),
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="SMTP setting not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Smtp setting not found"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *                 @OA\Items(type="string", example="Invalid smtp id 1")
     *             )
     *         )
     *     )
     * )
     */

    public function show(Request $request, int $id)
    {
        try {
            $smtp = SmtpSetting::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            return $this->successResponse("SMTP setting", $smtp->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Smtp setting not found", ["Invalid smtp id $id"], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Smtp not Found", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/smtp/type/{senderType}",
     *     summary="Fetch SMTP settings by sender type",
     *     tags={"SMTP Settings"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="senderType",
     *         in="path",
     *         required=true,
     *         description="Sender type: system, campaign, or user",
     *         @OA\Schema(type="string", example="campaign")
     *     ),
     *     @OA\Parameter(
     *         name="campaign_id",
     *         in="query",
     *         required=false,
     *         description="Campaign ID (required if senderType is campaign)",
     *         @OA\Schema(type="integer", example=12)
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         required=false,
     *         description="User ID (required if senderType is user)",
     *         @OA\Schema(type="integer", example=45)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMTP settings fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Query result"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="from_email", type="string", example="no-reply@example.com"),
     *                     @OA\Property(property="from_name", type="string", example="Campaign Bot"),
     *                     @OA\Property(property="sender_type", type="string", example="campaign"),
     *                     @OA\Property(property="host", type="string", example="smtp.example.com"),
     *                     @OA\Property(property="port", type="integer", example=587),
     *                     @OA\Property(property="username", type="string", example="smtp_user")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to query SMTP settings",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to query smtp settings"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="Error message"))
     *         )
     *     )
     * )
     */

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
            $smtps = SmtpSetting::on("mysql_" . $request->auth->parent_id)->where($where)->get()->all();
            foreach ($smtps as $smtp) {
                $record = $smtp->toArray();
                unset($record["mail_password"]);
                $records[] = $record;
            }
            return $this->successResponse("Query result", $records);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to query smtp settings", [$exception->getMessage()], $exception, 500);
        }
    }



    /**
     * @OA\Put(
     *     path="/smtp",
     *     summary="Create SMTP settings",
     *     description="Adds new SMTP configuration for the authenticated client.",
     *     tags={"SMTP"},
     *     security={{"Bearer":{}}},
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mail_driver", "mail_host", "mail_port", "mail_username", "mail_password", "mail_encryption", "sender_type"},
     *             @OA\Property(property="mail_driver", type="string", example="smtp"),
     *             @OA\Property(property="mail_host", type="string", example="smtp.mailtrap.io"),
     *             @OA\Property(property="mail_port", type="string", example="2525"),
     *             @OA\Property(property="mail_username", type="string", example="user@mailtrap.io"),
     *             @OA\Property(property="mail_password", type="string", example="securepassword"),
     *             @OA\Property(property="mail_encryption", type="string", example="tls"),
     *             @OA\Property(property="sender_type", type="string", enum={"default", "user", "campaign"}, example="user"),
     *             @OA\Property(property="from_email", type="string", format="email", example="no-reply@example.com"),
     *             @OA\Property(property="from_name", type="string", example="Example App"),
     *             @OA\Property(property="user_id", type="integer", example=123),
     *             @OA\Property(property="campaign_id", type="integer", example=456)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="SMTP setting added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="mail_driver", type="string", example="smtp"),
     *                 @OA\Property(property="mail_host", type="string", example="smtp.mailtrap.io"),
     *                 @OA\Property(property="mail_port", type="string", example="2525"),
     *                 @OA\Property(property="mail_username", type="string", example="user@mailtrap.io"),
     *                 @OA\Property(property="mail_encryption", type="string", example="tls"),
     *                 @OA\Property(property="from_email", type="string", example="no-reply@example.com"),
     *                 @OA\Property(property="from_name", type="string", example="Example App"),
     *                 @OA\Property(property="sender_type", type="string", example="user"),
     *                 @OA\Property(property="user_id", type="integer", example=123),
     *                 @OA\Property(property="campaign_id", type="integer", example=456),
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(
     *                     property="mail_host",
     *                     type="array",
     *                     @OA\Items(type="string", example="The mail host field is required.")
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $this->validate($request, [
            'mail_driver' => 'required|string|max:255',
            'mail_host' => 'required|string|max:255',
            'mail_port' => 'required|string',
            'mail_username' => 'required|string',
            'mail_password' => 'required|string',
            'mail_encryption' => 'required|string',
            'sender_type' => 'required|string',
            'from_email' => 'required_unless:sender_type,user|nullable|email',
            'from_name' => 'required_unless:sender_type,user|nullable|string',
            'user_id' => 'required_if:sender_type,user|nullable|numeric',
            'campaign_id' => 'required_if:sender_type,campaign|nullable|numeric',
        ]);
        $this->validateCreate($request);

        try {
            $input = $request->all();
            $smtp = new SmtpSetting();
            $smtp->setConnection("mysql_" . $request->auth->parent_id);
            if (!empty($input["mail_driver"])) $smtp->mail_driver = $input["mail_driver"];
            if (!empty($input["mail_host"])) $smtp->mail_host = $input["mail_host"];
            if (!empty($input["mail_username"])) $smtp->mail_username = $input["mail_username"];
            if (!empty($input["mail_password"])) $smtp->mail_password = $input["mail_password"];
            if (!empty($input["mail_encryption"])) $smtp->mail_encryption = $input["mail_encryption"];
            if (!empty($input["mail_port"])) $smtp->mail_port = $input["mail_port"];
            if (!empty($input["sender_type"])) $smtp->sender_type = $input["sender_type"];
            if (!empty($input["from_email"])) $smtp->from_email = $input["from_email"];
            if (!empty($input["from_name"])) $smtp->from_name = $input["from_name"];
            if (!empty($input["user_id"])) $smtp->user_id = $input["user_id"];
            if (!empty($input["campaign_id"])) $smtp->campaign_id = $input["campaign_id"];
            $smtp->saveOrFail();
            return $this->successResponse("Added Successfully", $smtp->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save SMTP setting", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/smtp/{id}",
     *     summary="Update an SMTP setting",
     *     description="Update the SMTP configuration details by ID for the authenticated client.",
     *     tags={"SMTP"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="SMTP setting ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={
     *                 "mail_driver","mail_host","mail_port","mail_username","mail_password",
     *                 "mail_encryption","sender_type"
     *             },
     *             @OA\Property(property="mail_driver", type="string", example="smtp"),
     *             @OA\Property(property="mail_host", type="string", example="smtp.mailtrap.io"),
     *             @OA\Property(property="mail_port", type="string", example="2525"),
     *             @OA\Property(property="mail_username", type="string", example="user@mailtrap.io"),
     *             @OA\Property(property="mail_password", type="string", example="secure_password"),
     *             @OA\Property(property="mail_encryption", type="string", example="tls"),
     *             @OA\Property(property="sender_type", type="string", example="user"),
     *             @OA\Property(property="from_email", type="string", example="no-reply@example.com"),
     *             @OA\Property(property="from_name", type="string", example="Example App"),
     *             @OA\Property(property="user_id", type="integer", example=123),
     *             @OA\Property(property="campaign_id", type="integer", example=456)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="SMTP setting updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMTP Update"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="SMTP setting not found or update failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Smtp Not Found"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *                 @OA\Items(type="string", example="Invalid Smtp id 1")
     *             )
     *         )
     *     )
     * )
     */

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'mail_driver' => 'required|string|max:255',
            'mail_host' => 'required|string|max:255',
            'mail_port' => 'required|string',
            'mail_username' => 'required|string',
            'mail_password' => 'required|string',
            'mail_encryption' => 'required|string',
            'sender_type' => 'required|string',
            'from_email' => 'required_unless:sender_type,user|nullable|email',
            'from_name' => 'required_unless:sender_type,user|nullable|string',
            'user_id' => 'required_if:sender_type,user|nullable|numeric',
            'campaign_id' => 'required_if:sender_type,campaign|nullable|numeric',
        ]);
        $this->validateCreate($request, $id);

        try {
            $smtp = SmtpSetting::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            if ($request->has("mail_host")) $smtp->mail_host = $request->input("mail_host");
            if ($request->has("mail_username")) $smtp->mail_username = $request->input("mail_username");
            if ($request->has("mail_password")) $smtp->mail_password = $request->input("mail_password");
            if ($request->has("mail_encryption")) $smtp->mail_encryption = $request->input("mail_encryption");
            if ($request->has("mail_port")) $smtp->mail_port = $request->input("mail_port");
            if ($request->has("sender_type")) $smtp->sender_type = $request->input("sender_type");
            if ($request->has("from_email")) $smtp->from_email = $request->input("from_email");
            if ($request->has("from_name")) $smtp->from_name = $request->input("from_name");
            if ($request->has("user_id")) $smtp->user_id = $request->input("user_id");
            if ($request->has("campaign_id")) $smtp->campaign_id = $request->input("campaign_id");
            $smtp->saveOrFail();

            return $this->successResponse("SMTP Update", $smtp->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Smtp Not Found", ["Invalid Smtp id $id"], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update smtp", [$exception->getMessage()], $exception, 404);
        }
    }

    private function validateCreate(Request $request, $id = null)
    {
        $senderType = $request->get("sender_type");
        Log::info("SmtpController.validateCreate($id)", $request->all());

        /**
         * if id blank and system -> no system entry should be present
         * if id passed and system -> system entry should have same id as passed one
         */
        if ($senderType == "system") {
            if ($request->auth->level < 7) {
                throw new RenderableException("You are not authorized to add/update setting for System Emails", [], 401);
            }

            $smtps = SmtpSetting::on("mysql_" . $request->auth->parent_id)->where("sender_type", "=", $senderType)->get()->all();
            if (empty($smtps)) return true;

            foreach ($smtps as $smtp) {
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

            $smtps = SmtpSetting::on("mysql_" . $request->auth->parent_id)->where([["sender_type", "=", $senderType], ["campaign_id", "=", $campaignId]])->get()->all();
            if (empty($smtps)) return true;
            if (count($smtps) > 1) {
                throw new RenderableException("Campaign Emails setting already present for campaign " . $campaign->title, [], 400);
            }

            foreach ($smtps as $smtp) {
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

            $smtps = SmtpSetting::on("mysql_" . $request->auth->parent_id)->where([["sender_type", "=", $senderType], ["user_id", "=", $userId]])->get()->all();
            if (empty($smtps)) return true;
            if (count($smtps) > 1) {
                throw new RenderableException("User Emails setting already present for " . $user->first_name . " " . $user->last_name, [], 400);
            }

            foreach ($smtps as $smtp) {
                if ($smtp->id == $id) return true;
            }
            throw new RenderableException("User Emails setting already present for " . $user->first_name . " " . $user->last_name, [], 400);
        }

        return true;
    }

    /**
     * @OA\Delete(
     *     path="/sms-delete/{id}",
     *     summary="Delete an SMTP setting",
     *     description="Deletes an SMTP setting by ID. Admins can delete any setting; regular users can only delete their own.",
     *     tags={"SMTP"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="SMTP setting ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="SMTP setting deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMTP setting deleted"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized delete attempt",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="You are not authorized to delete setting for other users"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="SMTP setting not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Smtp setting not found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid smtp id 1"))
     *         )
     *     )
     * )
     */

    public function delete(Request $request, int $id)
    {
        try {
            $smtp = SmtpSetting::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            #admin and below should be able to delete his own setting only
            if ($request->auth->level < 7) {
                if (($smtp->sender_type != "user") || ($smtp->user_id != $request->auth->id)) {
                    return $this->failResponse("You are not authorized to delete setting for other users", [], null, 401);
                }
            }
            $smtp->forceDelete();
            return $this->successResponse("SMTP setting deleted", $smtp->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Smtp setting not found", ["Invalid smtp id $id"], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to delete", [$exception->getMessage()], $exception, 500);
        }
    }

    function copySmtp(Request $request)
    {

        $smtp_data = SmtpSetting::on("mysql_" . $request->auth->parent_id)->findOrFail($request->c_id);

        try {
            $input = $request->all();
            $smtp = new SmtpSetting();
            $smtp->setConnection("mysql_" . $request->auth->parent_id);
            if (!empty($smtp_data->mail_driver)) $smtp->mail_driver = $smtp_data->mail_driver;
            if (!empty($smtp_data->mail_host)) $smtp->mail_host = $smtp_data->mail_host;
            if (!empty($smtp_data->mail_username)) $smtp->mail_username = "Copy-" . $smtp_data->mail_username;
            if (!empty($smtp_data->mail_password)) $smtp->mail_password = $smtp_data->mail_password;
            if (!empty($smtp_data->mail_encryption)) $smtp->mail_encryption = $smtp_data->mail_encryption;
            if (!empty($smtp_data->mail_port)) $smtp->mail_port = $smtp_data->mail_port;
            if (!empty($smtp_data->sender_type)) $smtp->sender_type = $smtp_data->sender_type;
            //if (!empty($smtp_data->from_email)) $smtp->from_email = $smtp_data->from_email;
            if (!empty($smtp_data->from_name)) $smtp->from_name = $smtp_data->from_name;
            //if (!empty($smtp_data->user_id)) $smtp->user_id = $smtp_data->user_id;
            if (!empty($smtp_data->campaign_id)) $smtp->campaign_id = $smtp_data->campaign_id;
            $smtp->saveOrFail();
            return $this->successResponse("Copy Successfully", $smtp->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Copy SMTP setting", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/status-update-smtp",
     *     summary="Update status of an status-update-smtp",
     *     description="Updates the status of an status-update-smtp record by its ID for the authenticated user's account.",
     *     tags={"SMTP"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId", "status"},
     *             @OA\Property(
     *                 property="listId",
     *                 type="integer",
     *                 example=1,
     *                 description="ID of the smtp record to update"
     *             ),
     *             @OA\Property(
     *                 property="status",
     *                 type="string",
     *                 enum={"0", "1"},
     *                 example="1",
     *                 description="New status value (1 for active, 0 for inactive)"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Smtp status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="status", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Smtp Status updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Update failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="status", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="smtp  Status  update failed")
     *         )
     *     )
     * )
     */
    function updateSmtpStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = SmtpSetting::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('status' => $status));


        // Log::debug('Received listId: ', ['listId' => $listId]);
        // Log::debug('Received status: ', ['status' => $status]);
        // Log::debug('Number of updated rows: ', ['saveRecord' => $saveRecord]);
        if ($saveRecord > 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'Smtp Status updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'Smtp  Status  update failed'
            ]);
        }
    }
}
