<?php

namespace App\Http\Controllers;

use App\Exceptions\RenderableException;
use App\Model\Master\AsteriskServer;
use App\Model\Master\IpWhiteList;
use App\Model\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SSH2;

class IpSettingController extends Controller
{
    /**
     * @OA\Post(
     *     path="/ip/query-ip-whitelist",
     *     summary="IP Whitelist list",
     *     description="Fetches a filtered list of IP whitelist entries for the authenticated client.",
     *     tags={"IpSetting"},
     *     security={{"Bearer": {}}},
     *
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="fromWeb", type="boolean", example=true, description="Filter by web-originated IPs"),
     *             @OA\Property(property="approvalStatus", type="integer", enum={-1, 0, 1}, example=1, description="Approval status filter"),
     *             @OA\Property(property="asteriskServer", type="string", format="ipv4", example="192.168.1.1", description="Asterisk server IP"),
     *             @OA\Property(property="whitelistIp", type="string", format="ipv4", example="203.0.113.4", description="Specific whitelist IP"),
     *             @OA\Property(property="start", type="integer", example=0, description="Start index for pagination"),
     *             @OA\Property(property="limit", type="integer", example=10, description="Number of records to fetch")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Whitelist IPs retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Whitelist Ip Search Result"),
     *             @OA\Property(property="start", type="integer", example=0),
     *             @OA\Property(property="limit", type="integer", example=10),
     *             @OA\Property(property="total", type="integer", example=100),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="whitelist_ip", type="string", example="203.0.113.4"),
     *                     @OA\Property(property="server_ip", type="string", example="192.168.1.1"),
     *                     @OA\Property(property="from_web", type="boolean", example=true),
     *                     @OA\Property(property="approval_status", type="integer", example=1),
     *                     @OA\Property(property="user", type="string", example="John Doe"),
     *                     @OA\Property(property="approvedBy", type="string", example="Admin User")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid parameters")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Internal server error")
     *         )
     *     )
     * )
     */

    public function queryIpWhitelist(Request $request)
    {
        $this->validate($request, [
            'fromWeb' => 'sometimes|boolean',
            'approvalStatus' => 'sometimes|in:0,1,-1',
            'asteriskServer' => 'sometimes|ip',
            'whitelistIp' => 'sometimes|ip',
            'start' => 'sometimes|integer|min:0',
            'limit' => 'sometimes|integer|min:1'
        ]);

        $where = [
            ["client_id", "=", $request->auth->parent_id]
        ];
        if ($request->has("fromWeb")) {
            $where[] = ["from_web", "=", (int)$request->get("fromWeb")];
        }
        if ($request->has("approvalStatus")) {
            $where[] = ["approval_status", "=", $request->get("approvalStatus")];
        }
        if ($request->has("asteriskServer")) {
            $where[] = ["server_ip", "=", $request->get("asteriskServer")];
        }
        if ($request->has("whitelistIp")) {
            $where[] = ["whitelist_ip", "=", $request->get("whitelistIp")];
        }

        // Fetch all and sort
        $ipList = IpWhiteList::where($where)->get()->sortByDesc('created_at')->all();

        $data = [];
        foreach ($ipList as $list) {
            $record = $list->toArray();

            // Get last login user
            if ($list->last_login_user) {
                $user = User::find($list->last_login_user);
                $record["user"] = $user ? $user->first_name . " " . $user->last_name : "";
            } else {
                $record["user"] = "";
            }

            // Get approved by user
            if ($list->updated_by) {
                $user = User::find($list->updated_by);
                $record["approvedBy"] = $user ? $user->first_name . " " . $user->last_name : "Auto";
            } else {
                $record["approvedBy"] = "Auto";
            }

            $data[] = $record;
        }

        // Total before slicing
        $totalRows = count($data);

        // Manual array pagination
        if ($request->has(['start', 'limit'])) {
            $start = (int)$request->input('start');
            $limit = (int)$request->input('limit');
            $data = array_slice($data, $start, $limit, true);
        }

        return response()->json([
            "success" => true,
            "message" => "Whitelist Ip Search Result",
            "start" => $request->input('start', 0),
            "limit" => $request->input('limit', $totalRows),
            "total" => $totalRows,
            "data" => array_values($data) // Reset keys
        ]);
    }

    public function queryIpWhitelistold(Request $request)
    {
        $this->validate($request, [
            'fromWeb' => 'required|sometimes|boolean',
            'approvalStatus' => 'required|sometimes|in:0,1,-1',
            'asteriskServer' => 'required|sometimes|ip',
            'whitelistIp' => 'required|sometimes|ip'
        ]);

        $where = [
            ["client_id", "=", $request->auth->parent_id]
        ];
        if ($request->has("fromWeb")) {
            array_push($where, ["from_web", "=", (int)$request->get("fromWeb", 1)]);
        }
        if ($request->has("approvalStatus")) {
            array_push($where, ["approval_status", "=", $request->get("approvalStatus", 0)]);
        }
        if ($request->has("asteriskServer")) {
            array_push($where, ["server_ip", "=", $request->get("asteriskServer")]);
        }
        if ($request->has("whitelistIp")) {
            array_push($where, ["whitelist_ip", "=", $request->get("whitelistIp")]);
        }

        $ipList = IpWhiteList::where($where)->get()->sortByDesc('created_at')->all();
        $data = [];
        foreach ($ipList as $list) {
            $record = $list->toArray();
            if ($list->last_login_user) {
                #fetch user name
                $user = User::find($list->last_login_user);
                if (!empty($user)) {
                    $record["user"] = $user->first_name . " " . $user->last_name;
                }
            } else {
                $record["user"] = "";
            }
            if ($list->updated_by) {
                #fetch user name
                $user = User::find($list->updated_by);
                if (!empty($user)) {
                    $record["approvedBy"] = $user->first_name . " " . $user->last_name;
                }
            } else {
                $record["approvedBy"] = "Auto";
            }
            $data[] = $record;
        }
        return $this->successResponse("Whitelist Ip Search Result", $data);
    }


    /**
     * @OA\Post(
     *     path="/ip/approve",
     *     summary="Approve and Whitelist IP",
     *     description="Approves and adds a client IP to the whitelist on the given Asterisk server.",
     *     tags={"IpSetting"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"serverIp", "whitelistIp"},
     *             @OA\Property(
     *                 property="serverIp",
     *                 type="string",
     *                 format="ipv4",
     *                 example="192.168.1.100",
     *                 description="IP of the Asterisk server"
     *             ),
     *             @OA\Property(
     *                 property="whitelistIp",
     *                 type="string",
     *                 format="ipv4",
     *                 example="203.0.113.45",
     *                 description="Client IP to whitelist"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="IP whitelisted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="IP whitelisted"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="No pending request found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No pending request for 203.0.113.45 on 192.168.1.100"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Server or SSH error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="IP whitelisting failed"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function approveIp(Request $request)
    {
        $this->validate($request, [
            'serverIp' => ["sometimes", "required", "ip"],
            'whitelistIp' => ["sometimes", "required", "ip"]
        ]);
        $serverIP = $request->get("serverIp");
        $whitelistIp = $request->get("whitelistIp");
        try {
            $ipWhiteList = IpWhiteList::find(['server_ip' => $serverIP, 'whitelist_ip' => $whitelistIp]);
        } catch (ModelNotFoundException $notFoundException) {
            return $this->failResponse("No pending request for $whitelistIp on $serverIP");
        }

        if (app()->environment() === "local") {
            $output = "ACCEPT";
        } else {
            $asteriskServer = AsteriskServer::where("host", $serverIP)->first();
            $ssh = new SSH2($asteriskServer->host, $asteriskServer->ssh_port);
            // Load your private key
            $key = new RSA();
            $key->loadKey(env("TEL_PRIVATE_KEY"));
            if (!$ssh->login('root', $key)) {
                throw new RenderableException("Failed to whitelist client IP $whitelistIp on server $serverIP. Unable to login.");
            }
            $output = $ssh->exec("csf -a $whitelistIp");
        }
        if (preg_match("/ACCEPT/", $output) || preg_match("/already in the allow file/", $output)) {
            #save in ip_whitelists
            $ipWhiteList->approval_status = 1;
            $ipWhiteList->updated_by = $request->auth->id;
            $ipWhiteList->saveOrFail();

            return $this->successResponse("IP whitelisted", []);
        } else {
            return $this->failResponse("IP whitelisting failed", [$output], null, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/ip/reject",
     *     summary="Reject a Whitelist IP Request",
     *     description="Rejects a whitelist IP request by updating the approval status to -1 for the given server and IP.",
     *     tags={"IpSetting"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"serverIp", "whitelistIp"},
     *             @OA\Property(
     *                 property="serverIp",
     *                 type="string",
     *                 format="ipv4",
     *                 example="192.168.1.100",
     *                 description="IP of the Asterisk server"
     *             ),
     *             @OA\Property(
     *                 property="whitelistIp",
     *                 type="string",
     *                 format="ipv4",
     *                 example="203.0.113.45",
     *                 description="Client IP to reject from whitelist"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="IP request rejected successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="IP request rejected"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="No pending request found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No pending request for 203.0.113.45 on 192.168.1.100"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */

    public function rejectIp(Request $request)
    {
        $this->validate($request, [
            'serverIp' => ["sometimes", "required", "ip"],
            'whitelistIp' => ["sometimes", "required", "ip"]
        ]);
        $serverIP = $request->get("serverIp");
        $whitelistIp = $request->get("whitelistIp");
        try {
            $ipWhiteList = IpWhiteList::find(['server_ip' => $serverIP, 'whitelist_ip' => $whitelistIp]);
        } catch (ModelNotFoundException $notFoundException) {
            return $this->failResponse("No pending request for $whitelistIp on $serverIP");
        }
        #save in ip_whitelists
        $ipWhiteList->approval_status = -1;
        $ipWhiteList->updated_by = $request->auth->id;
        $ipWhiteList->saveOrFail();

        return $this->successResponse("IP request rejected", []);
    }


    /**
     * @OA\Post(
     *     path="/ip/whitelist-ip",
     *     summary="Whitelist an IP on Multiple Asterisk Servers",
     *     description="Adds a given IP to the whitelist across multiple Asterisk servers.",
     *     tags={"IpSetting"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"whitelistIp", "asteriskServers"},
     *             @OA\Property(
     *                 property="whitelistIp",
     *                 type="string",
     *                 format="ipv4",
     *                 example="203.0.113.45",
     *                 description="The IP address to whitelist"
     *             ),
     *             @OA\Property(
     *                 property="asteriskServers",
     *                 type="array",
     *                 @OA\Items(
     *                     type="integer",
     *                     example=1,
     *                     description="Asterisk server ID"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="IP whitelisted successfully on all specified servers",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Ip whitelisted"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Server not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Server id 3 not defined"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed whitelist an IP on server(s)"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function whitelistIpOnServers(Request $request)
    {
        $this->validate($request, [
            'whitelistIp' => 'required|ip',
            'asteriskServers' => 'required|array',
            'asteriskServers.*' => 'required|integer'
        ]);
        $data = [];
        $whitelistIp = $request->get("whitelistIp");
        foreach ($request->get("asteriskServers", []) as $serverId) {
            try {
                $server = AsteriskServer::find($serverId);
                $data[$serverId] = $server->whiteListIp($whitelistIp, 0, $request->auth->parent_id, $request->auth->id);
            } catch (ModelNotFoundException $notFoundException) {
                return $this->failResponse("Server id $serverId not defined", $data, $notFoundException, 400);
            } catch (\Throwable $exception) {
                Log::error("IpSettingController.whitelistIpOnServers", [
                    "data" => $data,
                    "serverId" => $serverId,
                    "whitelistIp" => $whitelistIp,
                    "message" => $exception->getMessage(),
                    "file" => $exception->getFile(),
                    "line" => $exception->getLine(),
                    "code" => $exception->getCode()
                ]);
                return $this->failResponse("Failed whitelist an IP on server(s)", [$exception->getMessage()], $exception, 500);
            }
        }
        return $this->successResponse("Ip whitelisted", $data);
    }
}
