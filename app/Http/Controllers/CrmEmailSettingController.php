<?php

namespace App\Http\Controllers;

use App\Model\Client\EmailSetting;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Http\Helper\Log;

class CrmEmailSettingController extends Controller
{

    /**
     * @OA\Get(
     *     path="/crm-email-setting",
     *     summary="Get all email settings",
     *     description="Returns a list of email settings .",
     *     tags={"CrmEmailSetting"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Email settings fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Email Setting"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="online", type="object", nullable=true),
     *                 @OA\Property(property="notification", type="object", nullable=true),
     *                 @OA\Property(property="submission", type="object", nullable=true),
     *                 @OA\Property(property="marketing_campaigns", type="object", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to list email settings",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to list of Email Setting"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */

    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $setting = [];
            $setting['online'] = EmailSetting::on("mysql_$clientId")->where('mail_type', 'online application')->get()->first();
            $setting['notification'] = EmailSetting::on("mysql_$clientId")->where('mail_type', 'notification')->get()->first();
            $setting['submission'] = EmailSetting::on("mysql_$clientId")->where('mail_type', 'submission')->get()->first();
            $setting['marketing_campaigns'] = EmailSetting::on("mysql_$clientId")->where('mail_type', 'marketing_campaigns')->get()->first();


            return $this->successResponse("Email Setting", $setting);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of Email Setting", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Post(
     *     path="/crm-email-setting",
     *     summary="Create a new SMTP email setting",
     *     description="Creates and stores a new email setting configuration for the authenticated client's database.",
     *     tags={"CrmEmailSetting"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mail_driver", "mail_username", "mail_password", "sender_email", "mail_type"},
     *             @OA\Property(property="mail_driver", type="string", example="Sendgrid"),
     *             @OA\Property(property="mail_username", type="string", example="user@example.com"),
     *             @OA\Property(property="mail_password", type="string", example="securepassword"),
     *             @OA\Property(property="mail_encryption", type="string", example="TLS"),
     *             @OA\Property(property="mail_port", type="integer", example=587),
     *             @OA\Property(property="sender_email", type="string", example="noreply@example.com"),
     *             @OA\Property(property="send_email_via", type="string", example="custom"),
     *             @OA\Property(property="sender_name", type="string", example="Support Team"),
     *             @OA\Property(property="mail_type", type="string", example="notification")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMTP setting added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save SMTP setting",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to save SMTP setting"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */


    public function create(Request $request)
    {


        try {
            $input = $request->all();
            $smtp = new EmailSetting();
            $smtp->setConnection("mysql_" . $request->auth->parent_id);
            //$smtp->mail_type = 'online application';
            if ($input["mail_driver"] === "Sendgrid") {
                $smtp->mail_host = "smtp.sendgrid.net";
                $smtp->mail_encryption = "TLS";
                $smtp->mail_port = 587;
            } elseif (($input["mail_driver"] === "Zoho")) {
                $smtp->mail_host = "smtp.zoho.com";
                $smtp->mail_encryption = "TLS";
                $smtp->mail_port = 587;
            } elseif (($input["mail_driver"] === "Google")) {
                $smtp->mail_host = "smtp.gmail.com";
                $smtp->mail_encryption = "TLS";
                $smtp->mail_port = 587;
            }
            if (!empty($input["mail_driver"])) $smtp->mail_driver = $input["mail_driver"];
            if (!empty($input["mail_username"])) $smtp->mail_username = $input["mail_username"];
            if (!empty($input["mail_password"])) $smtp->mail_password = $input["mail_password"];
            if (!empty($input["mail_encryption"])) $smtp->mail_encryption = $input["mail_encryption"];
            if (!empty($input["mail_port"])) $smtp->mail_port = $input["mail_port"];
            if (!empty($input["sender_email"])) $smtp->sender_email = $input["sender_email"];
            if ($input["mail_type"] == 'notification') {
                $smtp->send_email_via = 'custom';
            } else {
                if (!empty($input["send_email_via"])) $smtp->send_email_via = $input["send_email_via"];
            }
            if (!empty($input["sender_name"])) $smtp->sender_name = $input["sender_name"];
            if (!empty($input["mail_type"])) $smtp->mail_type = $input["mail_type"];


            //  return $smtp;

            $smtp->saveOrFail();
            return $this->successResponse("Added Successfully", $smtp->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save SMTP setting", [$exception->getMessage()], $exception, 500);
        }
    }


    /**
     * @OA\Post(
     *     path="/update-crm-email-setting/{id}",
     *     summary="Update an existing SMTP email setting",
     *     description="Updates a specific SMTP email setting configuration for the authenticated client's database by ID.",
     *     tags={"CrmEmailSetting"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the SMTP setting to update",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="mail_driver", type="string", example="Zoho"),
     *             @OA\Property(property="mail_username", type="string", example="user@example.com"),
     *             @OA\Property(property="mail_password", type="string", example="securepassword"),
     *             @OA\Property(property="sender_name", type="string", example="Support Team"),
     *             @OA\Property(property="sender_email", type="string", example="noreply@example.com"),
     *             @OA\Property(property="send_email_via", type="string", example="custom")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMTP setting updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="System Setting Updated"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="SMTP setting not found or update failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="System Setting Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */


    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;

        try {
            $System = EmailSetting::on("mysql_$clientId")->findOrFail($id);

            if ($request->input("mail_driver") === "Sendgrid") {
                $System->mail_host = "smtp.sendgrid.net";
                $System->mail_encryption = "TLS";
                $System->mail_port = 587;
            } elseif (($request->input("mail_driver") === "Zoho")) {
                $System->mail_host = "smtp.zoho.com";
                $System->mail_encryption = "TLS";
                $System->mail_port = 587;
            } elseif (($request->input("mail_driver") === "Google")) {
                $System->mail_host = "smtp.gmail.com";
                $System->mail_encryption = "TLS";
                $System->mail_port = 587;
            }


            if ($request->has("mail_driver")) {
                $System->mail_driver = $request->input("mail_driver");
            }
            if ($request->has("mail_username")) {
                $System->mail_username = $request->input("mail_username");
            }
            if ($request->has("mail_password")) {
                $System->mail_password = $request->input("mail_password");
            }
            if ($request->has("sender_name")) {
                $System->sender_name = $request->input("sender_name");
            }
            if ($request->has("sender_email")) {
                $System->sender_email = $request->input("sender_email");
            }

            if ($request->has("send_email_via")) {
                if ($request->input("send_email_via") == 'user_email') {
                    $System->sender_email = '';
                    $System->sender_name = '';
                }
                $System->send_email_via = $request->input("send_email_via");
            }
            $System->saveOrFail();

            return $this->successResponse("System Setting Updated", $System->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("System Setting  Not Found", [
                "Invalid System Setting  id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update System Setting ", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
}
