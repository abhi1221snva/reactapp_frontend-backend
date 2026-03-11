<?php

namespace App\Http\Controllers;

use App\Http\Helper\JwtToken;
use App\Jobs\CreateClientJob;
use App\Model\Authentication;
use App\Model\Master\AsteriskServer;
use App\Model\Master\Client;
use App\Model\Master\ClientServers;
use App\Model\User;
use App\Services\ClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdminClientController extends Controller
{
    // ── List all clients ───────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 25);
        $page    = max(1, (int) $request->input('page', 1));
        $search  = $request->input('search', '');
        $status  = $request->input('status', '');   // 'active' | 'inactive' | ''

        $query = Client::with([])
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

        $data['asterisk_servers']   = $client->getAsteriskServers();
        $data['asterisk_server_list'] = AsteriskServer::list();

        return $this->successResponse('OK', $data);
    }

    // ── Create client ──────────────────────────────────────────────────────────

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

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'company_name'     => 'sometimes|required|string|max:255',
            'trunk'            => 'sometimes|required|string|max:30',
            'enable_2fa'       => 'sometimes|string',
            'api_key'          => 'sometimes|required|string',
            'asterisk_servers' => 'sometimes|required|array',
        ]);

        $client = Client::findOrFail($id);
        $input  = $request->only([
            'company_name', 'trunk', 'address_1', 'address_2', 'logo',
            'enable_2fa', 'api_key', 'mca_crm', 'sms', 'fax', 'chat',
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

    public function activate(Request $request, int $id)
    {
        $client = Client::findOrFail($id);
        $client->update(['is_deleted' => 0]);

        Log::info('Admin activated client', ['admin_id' => $request->auth->id, 'client_id' => $id]);

        return $this->successResponse('Client activated.', ['id' => $id, 'is_deleted' => 0]);
    }

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
