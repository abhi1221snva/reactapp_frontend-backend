<?php

namespace App\Http\Controllers;

use App\Http\Helper\JwtToken;
use App\Jobs\CreateClientJob;
use App\Model\Authentication;
use App\Model\Master\AsteriskServer;
use App\Model\Master\Client;
use App\Model\Master\ClientServers;
use App\Model\User;
use App\Model\Master\SubscriptionPlan;
use App\Services\ClientService;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdminClientController extends Controller
{
    // ── List all clients ───────────────────────────────────────────────────────

    /**
     * @OA\Get(
     *     path="/admin/clients",
     *     summary="List all tenant clients (paginated)",
     *     tags={"Admin"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=25)),
     *     @OA\Parameter(name="page",     in="query", required=false, @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="search",   in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="status",   in="query", required=false, @OA\Schema(type="string", enum={"active","inactive",""})),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated client list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="clients",      type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="total",        type="integer"),
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="per_page",     type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden — system_administrator only")
     * )
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 25);
        $page    = max(1, (int) $request->input('page', 1));
        $search  = $request->input('search', '');
        $status  = $request->input('status', '');   // 'active' | 'inactive' | ''

        $query = Client::with(['subscriptionPlan:id,slug,name'])
            ->orderByDesc('id');

        if ($search) {
            $query->where('company_name', 'like', '%' . $search . '%');
        }

        if ($status === 'active') {
            $query->where('is_deleted', 0);
        } elseif ($status === 'inactive') {
            $query->where('is_deleted', 1);
        }

        $total   = $query->count();
        $clients = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        // Attach primary admin user info for each client
        $clientIds = $clients->pluck('id')->toArray();
        $adminUsers = User::whereIn('parent_id', $clientIds)
            ->where('role', 6)                 // role 6 = super admin
            ->get(['id', 'parent_id', 'email', 'first_name', 'last_name'])
            ->keyBy('parent_id');

        $result = $clients->map(function (Client $client) use ($adminUsers) {
            $arr = $client->toArray();
            $admin = $adminUsers[$client->id] ?? null;
            $arr['admin_user'] = $admin ? [
                'id'         => $admin->id,
                'email'      => $admin->email,
                'first_name' => $admin->first_name,
                'last_name'  => $admin->last_name,
            ] : null;
            return $arr;
        });

        return $this->successResponse('OK', [
            'clients'      => $result,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
        ]);
    }

    // ── Get single client ──────────────────────────────────────────────────────

    /**
     * @OA\Get(
     *     path="/admin/clients/{id}",
     *     summary="Get a single client by ID",
     *     tags={"Admin"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Client details including admin user and server assignments",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Client not found"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function show(int $id)
    {
        $client = Client::findOrFail($id);
        $data   = $client->toArray();

        $adminUser = User::where('parent_id', $id)->where('role', 6)->first();
        $data['admin_user'] = $adminUser ? [
            'id'         => $adminUser->id,
            'email'      => $adminUser->email,
            'first_name' => $adminUser->first_name,
            'last_name'  => $adminUser->last_name,
        ] : null;

        $data['asterisk_servers']    = $client->getAsteriskServers();
        $data['asterisk_server_list'] = AsteriskServer::list();
        $data['subscription_plan']   = $client->subscriptionPlan;
        $data['subscription_usage']  = PlanService::getUsageSummary($id);

        return $this->successResponse('OK', $data);
    }

    // ── Create client ──────────────────────────────────────────────────────────

    /**
     * @OA\Post(
     *     path="/admin/clients",
     *     summary="Create a new tenant client",
     *     tags={"Admin"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"company_name","asterisk_servers","trunk","api_key"},
     *             @OA\Property(property="company_name",     type="string"),
     *             @OA\Property(property="asterisk_servers", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="trunk",            type="string"),
     *             @OA\Property(property="api_key",          type="string"),
     *             @OA\Property(property="enable_2fa",       type="string", enum={"on","off"}),
     *             @OA\Property(property="address_1",        type="string"),
     *             @OA\Property(property="address_2",        type="string")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Client created successfully"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'company_name'     => 'required|string|max:255|unique:master.clients',
            'asterisk_servers' => 'required|array',
            'trunk'            => 'required|string|max:30',
            'enable_2fa'       => 'sometimes|string',
            'api_key'          => 'required|string',
        ]);

        $attributes         = $request->only([
            'company_name', 'trunk', 'address_1', 'address_2', 'logo',
            'enable_2fa', 'api_key', 'mca_crm', 'sms', 'fax', 'chat',
            'webphone', 'ringless', 'callchex', 'predictive_dial',
        ]);
        $attributes['stage'] = Client::RECORD_SAVED;

        // Default to per-seat plan on trial with 1 seat
        try {
            $perSeatPlan = SubscriptionPlan::getPerSeatPlan();
        } catch (\Throwable $e) {
            $perSeatPlan = null;
        }
        if ($perSeatPlan) {
            $attributes['subscription_plan_id']    = $perSeatPlan->id;
            $attributes['subscription_status']     = 'trial';
            $attributes['billing_cycle']           = 'monthly';
            $attributes['subscription_started_at'] = Carbon::now();
            $attributes['subscription_ends_at']    = Carbon::now()->addDays($perSeatPlan->trial_days ?: 14);
            $attributes['seat_quantity']           = 1;
        }

        $client = Client::create($attributes);

        // Give the requesting admin permission over the new client
        $requester = User::findOrFail($request->auth->id);
        $requester->addPermission($client->id, 1);

        $client->stage = Client::ADMIN_ASSIGNED;
        $client->saveOrFail();

        dispatch(new CreateClientJob($client, $request->input('asterisk_servers')))->onConnection('clients');

        Log::info('Admin created client', [
            'admin_id'  => $request->auth->id,
            'client_id' => $client->id,
        ]);

        return $this->successResponse('Client created successfully.', $client->toArray());
    }

    // ── Update client ──────────────────────────────────────────────────────────

    /**
     * @OA\Put(
     *     path="/admin/clients/{id}",
     *     summary="Update an existing tenant client",
     *     tags={"Admin"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="company_name",     type="string"),
     *             @OA\Property(property="trunk",            type="string"),
     *             @OA\Property(property="api_key",          type="string"),
     *             @OA\Property(property="enable_2fa",       type="string"),
     *             @OA\Property(property="asterisk_servers", type="array", @OA\Items(type="integer"))
     *         )
     *     ),
     *     @OA\Response(response=200, description="Client updated successfully"),
     *     @OA\Response(response=404, description="Client not found"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'company_name'     => 'sometimes|required|string|max:255',
            'trunk'            => 'sometimes|required|string|max:30',
            'enable_2fa'       => 'sometimes|string',
            'require_2fa'      => 'sometimes|boolean',
            'api_key'          => 'sometimes|required|string',
            'asterisk_servers' => 'sometimes|required|array',
        ]);

        $client = Client::findOrFail($id);
        $input  = $request->only([
            'company_name', 'trunk', 'address_1', 'address_2', 'logo',
            'enable_2fa', 'require_2fa', 'api_key', 'mca_crm', 'sms', 'fax', 'chat',
            'webphone', 'ringless', 'callchex', 'predictive_dial',
        ]);

        $client->update($input);

        if ($request->has('asterisk_servers')) {
            $newServers = $request->input('asterisk_servers');
            $existing   = ClientServers::where('client_id', $id)->get()->all();

            foreach ($existing as $server) {
                if (!in_array($server->server_id, $newServers)) {
                    $server->delete();
                }
            }
            $existingIds = array_column($existing, 'server_id');
            foreach ($newServers as $serverId) {
                if (!in_array($serverId, $existingIds)) {
                    ClientServers::create(['client_id' => $id, 'server_id' => $serverId, 'ip_address' => $serverId]);
                }
            }
        }

        ClientService::clearCache();

        Log::info('Admin updated client', ['admin_id' => $request->auth->id, 'client_id' => $id]);

        $data                       = $client->fresh()->toArray();
        $data['asterisk_servers']   = $client->getAsteriskServers();

        return $this->successResponse('Client updated successfully.', $data);
    }

    // ── Activate / Deactivate ──────────────────────────────────────────────────

    /**
     * @OA\Post(
     *     path="/admin/clients/{id}/activate",
     *     summary="Activate a deactivated client",
     *     tags={"Admin"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Client activated"),
     *     @OA\Response(response=404, description="Client not found"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function activate(Request $request, int $id)
    {
        $client = Client::findOrFail($id);
        $client->update(['is_deleted' => 0]);

        Log::info('Admin activated client', ['admin_id' => $request->auth->id, 'client_id' => $id]);

        return $this->successResponse('Client activated.', ['id' => $id, 'is_deleted' => 0]);
    }

    /**
     * @OA\Post(
     *     path="/admin/clients/{id}/deactivate",
     *     summary="Deactivate (soft-delete) a client",
     *     tags={"Admin"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Client deactivated"),
     *     @OA\Response(response=422, description="Cannot deactivate own account"),
     *     @OA\Response(response=404, description="Client not found"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function deactivate(Request $request, int $id)
    {
        $client = Client::findOrFail($id);

        // Prevent deactivating your own client
        $myClientId = $request->auth->parent_id ?: $request->auth->id;
        if ($myClientId == $id) {
            return $this->failResponse('Cannot deactivate your own client account.', [], null, 422);
        }

        $client->update(['is_deleted' => 1]);

        Log::info('Admin deactivated client', ['admin_id' => $request->auth->id, 'client_id' => $id]);

        return $this->successResponse('Client deactivated.', ['id' => $id, 'is_deleted' => 1]);
    }

    // ── Switch into client workspace ───────────────────────────────────────────
    // The system admin stays logged in as their own account. A JWT is issued
    // with a `client_override` claim so JwtMiddleware routes all subsequent
    // DB queries to the target client's database.

    /**
     * @OA\Post(
     *     path="/admin/clients/{id}/switch",
     *     summary="Impersonate a client — returns a JWT with client_override claim",
     *     tags={"Admin"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="JWT token for impersonating the target client",
     *         @OA\JsonContent(
     *             @OA\Property(property="success",                 type="boolean"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="token",                    type="string"),
     *                 @OA\Property(property="expires_at",               type="string"),
     *                 @OA\Property(property="impersonating",            type="boolean"),
     *                 @OA\Property(property="impersonating_client_id",  type="integer"),
     *                 @OA\Property(property="impersonating_company",    type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Cannot switch to deactivated client"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function switchTo(Request $request, int $id)
    {
        $client = Client::findOrFail($id);

        if ($client->is_deleted) {
            return $this->failResponse('Cannot switch to a deactivated client.', [], null, 422);
        }

        $adminUserId = $request->auth->id;
        $adminUser   = User::findOrFail($adminUserId);

        // Issue a JWT that carries client_override = $id
        $token = JwtToken::createTokenWithOverride($adminUserId, $id);

        $server = \App\Model\Master\AsteriskServer::find($adminUser->asterisk_server_id ?? null);

        $response = [
            'id'                      => $adminUser->id,
            'parent_id'               => $id,             // target client
            'first_name'              => $adminUser->first_name,
            'last_name'               => $adminUser->last_name,
            'email'                   => $adminUser->email,
            'companyName'             => $client->company_name,
            'companyLogo'             => $client->logo ?? null,
            'profile_pic'             => $adminUser->profile_pic ?? null,
            'extension'               => $adminUser->extension ?? null,
            'alt_extension'           => $adminUser->alt_extension ?? null,
            'app_extension'           => $adminUser->app_extension ?? null,
            'dialer_mode'             => $adminUser->dialer_mode ?? 'extension',
            'token'                   => $token[0],
            'expires_at'              => $token[1],
            'server'                  => $server->host ?? null,
            'domain'                  => $server->domain ?? null,
            'did'                     => null,
            'level'                   => $request->auth->level, // keep superadmin level
            'impersonating'           => true,
            'impersonating_client_id' => $id,
            'impersonating_company'   => $client->company_name,
        ];

        Log::info('Super admin switched to client workspace', [
            'admin_id'       => $adminUserId,
            'target_client'  => $id,
        ]);

        return $this->successResponse('Switched to client workspace.', $response);
    }
}
