<?php

namespace App\Http\Controllers;

use App\Model\PlivoAccount;
use App\Model\PlivoSubaccount;
use App\Model\Client\PlivoUsageLog;
use App\Services\PlivoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Post(
 *   path="/plivo/account/connect",
 *   summary="Connect Plivo account",
 *   operationId="plivoConnect",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"auth_id","auth_token"},
 *     @OA\Property(property="auth_id", type="string"),
 *     @OA\Property(property="auth_token", type="string")
 *   )),
 *   @OA\Response(response=200, description="Account connected"),
 *   @OA\Response(response=401, description="Invalid credentials"),
 *   @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Get(
 *   path="/plivo/account",
 *   summary="Get connected Plivo account",
 *   operationId="plivoGetAccount",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Account details")
 * )
 *
 * @OA\Post(
 *   path="/plivo/account/disconnect",
 *   summary="Disconnect Plivo account",
 *   operationId="plivoDisconnect",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Account disconnected")
 * )
 *
 * @OA\Post(
 *   path="/plivo/subaccount",
 *   summary="Create a Plivo subaccount",
 *   operationId="plivoCreateSubaccount",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(@OA\Property(property="name", type="string"))),
 *   @OA\Response(response=200, description="Subaccount created"),
 *   @OA\Response(response=500, description="Creation failed")
 * )
 *
 * @OA\Get(
 *   path="/plivo/subaccounts",
 *   summary="List Plivo subaccounts",
 *   operationId="plivoListSubaccounts",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Subaccount list")
 * )
 *
 * @OA\Post(
 *   path="/plivo/subaccount/suspend",
 *   summary="Suspend a Plivo subaccount",
 *   operationId="plivoSuspendSubaccount",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"auth_id"},
 *     @OA\Property(property="auth_id", type="string")
 *   )),
 *   @OA\Response(response=200, description="Subaccount suspended")
 * )
 *
 * @OA\Get(
 *   path="/plivo/usage",
 *   summary="Get Plivo usage and billing summary",
 *   operationId="plivoUsage",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="date_till", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Response(response=200, description="Usage summary")
 * )
 */
class PlivoAccountController extends Controller
{
    public function connect(Request $request)
    {
        $clientId  = $request->auth->parent_id ?: $request->auth->id;
        $authId    = trim($request->input("auth_id", ""));
        $authToken = trim($request->input("auth_token", ""));

        if (!$authId || !$authToken) {
            return $this->failResponse("auth_id and auth_token are required.", [], null, 422);
        }

        try {
            $service = new PlivoService($authId, $authToken);
            $info    = $service->verifyCredentials();
        } catch (\Exception $e) {
            Log::warning("Plivo connect failed", ["client" => $clientId, "err" => $e->getMessage()]);
            return $this->failResponse("Invalid Plivo credentials.", [], $e, 401);
        }

        $account = PlivoAccount::updateOrCreate(
            ["client_id" => $clientId],
            [
                "auth_id"      => $authId,
                "auth_token"   => $authToken,
                "name"         => $info["name"] ?? null,
                "account_type" => $info["account_type"] ?? null,
                "status"       => "active",
            ]
        );

        Log::info("Plivo account connected", ["client" => $clientId]);

        return $this->successResponse("Plivo account connected successfully.", [
            "account" => $this->_safe($account),
        ]);
    }

    public function getAccount(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $account  = PlivoAccount::where("client_id", $clientId)->first();

        if (!$account) {
            return $this->successResponse("No Plivo account configured.", ["account" => null]);
        }

        return $this->successResponse("OK", ["account" => $this->_safe($account)]);
    }

    public function disconnect(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        PlivoAccount::where("client_id", $clientId)->delete();
        return $this->successResponse("Plivo account disconnected.");
    }

    public function createSubaccount(Request $request)
    {
        // Only system administrators (level >= 9) can create platform subaccounts
        if (($request->auth->level ?? 0) < 9) {
            return $this->failResponse("Only system administrators can create platform subaccounts.", [], null, 403);
        }

        $clientId     = $request->auth->parent_id ?: $request->auth->id;
        $friendlyName = $request->input("name", "Client {$clientId}");

        try {
            $masterAuthId    = env("PLIVO_AUTH_ID");
            $masterAuthToken = env("PLIVO_AUTH_TOKEN");

            if (!$masterAuthId || !$masterAuthToken) {
                return $this->failResponse("Platform Plivo credentials not configured.", [], null, 500);
            }

            $service = new PlivoService($masterAuthId, $masterAuthToken);
            $sub     = $service->createSubaccount($friendlyName, true);

            $account = PlivoAccount::updateOrCreate(
                ["client_id" => $clientId],
                [
                    "name"                  => $friendlyName,
                    "status"                => "active",
                    "subaccount_auth_id"    => $sub["auth_id"],
                    "subaccount_auth_token" => $sub["auth_token"],
                ]
            );

            PlivoSubaccount::updateOrCreate(
                ["auth_id" => $sub["auth_id"]],
                [
                    "plivo_account_id" => $account->id,
                    "auth_token"       => $sub["auth_token"],
                    "name"             => $friendlyName,
                    "enabled"          => true,
                ]
            );

            return $this->successResponse("Subaccount created.", ["subaccount" => $sub]);

        } catch (\Exception $e) {
            Log::error("Plivo subaccount create failed", ["client" => $clientId, "err" => $e->getMessage()]);
            return $this->failResponse("Failed to create subaccount.", [$e->getMessage()], $e, 500);
        }
    }

    public function listSubaccounts(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $account  = PlivoAccount::where("client_id", $clientId)->first();

        if (!$account) {
            return $this->successResponse("OK", ["subaccounts" => []]);
        }

        $subs = PlivoSubaccount::where("plivo_account_id", $account->id)->get()
            ->map(fn($s) => [
                "id"         => $s->id,
                "auth_id"    => $s->auth_id,
                "name"       => $s->name,
                "enabled"    => $s->enabled,
                "created_at" => $s->created_at,
            ]);

        return $this->successResponse("OK", ["subaccounts" => $subs]);
    }

    public function suspendSubaccount(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $authId   = $request->input("auth_id");

        if (!$authId) {
            return $this->failResponse("auth_id is required.", [], null, 422);
        }

        try {
            $service = PlivoService::forClient($clientId);
            $service->suspendSubaccount($authId);

            PlivoSubaccount::where("auth_id", $authId)->update(["enabled" => false]);

            return $this->successResponse("Subaccount suspended.");
        } catch (\Exception $e) {
            return $this->failResponse("Failed to suspend subaccount.", [$e->getMessage()], $e, 500);
        }
    }

    public function usage(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";
        $dateFrom = $request->input("date_from", \Carbon\Carbon::now()->startOfMonth()->toDateString());
        $dateTill = $request->input("date_till", \Carbon\Carbon::now()->toDateString());

        try {
            $service     = PlivoService::forClient($clientId);
            $callRecords = $service->getCallCdr($dateFrom, $dateTill, 200);
            $smsRecords  = $service->getMessageRecords($dateFrom, $dateTill, 200);

            foreach ($callRecords as $r) {
                PlivoUsageLog::on($conn)->updateOrCreate(
                    ["resource_type" => "call", "resource_id" => $r["call_uuid"]],
                    [
                        "status"       => $r["call_status"],
                        "duration"     => $r["duration"] ?? 0,
                        "total_amount" => $r["total_amount"] ?? "0",
                        "bill_date"    => $r["bill_date"] ?? null,
                        "synced_at"    => \Carbon\Carbon::now(),
                    ]
                );
            }

            foreach ($smsRecords as $r) {
                PlivoUsageLog::on($conn)->updateOrCreate(
                    ["resource_type" => "sms", "resource_id" => $r["message_uuid"]],
                    [
                        "status"       => $r["message_state"],
                        "total_amount" => $r["total_amount"] ?? "0",
                        "total_rate"   => $r["total_rate"] ?? "0",
                        "units"        => $r["units"] ?? 1,
                        "synced_at"    => \Carbon\Carbon::now(),
                    ]
                );
            }

            $totalCallSpend = collect($callRecords)->sum(fn($r) => (float)($r["total_amount"] ?? 0));
            $totalSmsSpend  = collect($smsRecords)->sum(fn($r) => (float)($r["total_amount"] ?? 0));
            $totalSeconds   = collect($callRecords)->sum(fn($r) => (int)($r["duration"] ?? 0));

            return $this->successResponse("OK", [
                "call_records" => $callRecords,
                "sms_records"  => $smsRecords,
                "summary" => [
                    "total_calls"  => count($callRecords),
                    "total_sms"    => count($smsRecords),
                    "minutes_used" => round($totalSeconds / 60, 2),
                    "call_spend"   => round($totalCallSpend, 4),
                    "sms_spend"    => round($totalSmsSpend, 4),
                    "total_spend"  => round($totalCallSpend + $totalSmsSpend, 4),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error("Plivo usage fetch failed", ["client" => $clientId, "err" => $e->getMessage()]);
            return $this->failResponse("Failed to fetch usage.", [$e->getMessage()], $e, 500);
        }
    }

    private function _safe(PlivoAccount $account): array
    {
        return [
            "id"                => $account->id,
            "client_id"         => $account->client_id,
            "name"              => $account->name,
            "account_type"      => $account->account_type,
            "status"            => $account->status,
            "has_own_account"   => !empty($account->getRawOriginal("auth_id")),
            "has_subaccount"    => !empty($account->getRawOriginal("subaccount_auth_id")),
            "masked_token"      => $account->maskedToken(),
            "blocked_countries" => $account->blocked_countries ?? [],
            "created_at"        => $account->created_at,
        ];
    }
}
