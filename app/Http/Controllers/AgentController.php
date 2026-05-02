<?php

namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Role;
use App\Model\Master\OnboardingProgress;
use App\Services\WelcomeEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Services\CacheService;
use App\Services\PlanService;
use App\Services\PjsipRealtimeService;

/**
 * Agent management for client admins.
 *
 * @OA\Get(
 *   path="/agents",
 *   summary="List agents",
 *   description="Returns a paginated list of agents for the authenticated client.",
 *   operationId="listAgents",
 *   tags={"Agent"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
 *   @OA\Parameter(name="status", in="query", @OA\Schema(type="integer", enum={0,1})),
 *   @OA\Parameter(name="role_id", in="query", @OA\Schema(type="integer")),
 *   @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
 *   @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=25)),
 *   @OA\Response(response=200, description="Agent list"),
 *   @OA\Response(response=401, description="Unauthenticated"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 *
 * @OA\Post(
 *   path="/agents",
 *   summary="Create agent",
 *   operationId="createAgent",
 *   tags={"Agent"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"name","email","password","role"},
 *       @OA\Property(property="name", type="string"),
 *       @OA\Property(property="email", type="string", format="email"),
 *       @OA\Property(property="password", type="string", format="password"),
 *       @OA\Property(property="role", type="integer"),
 *       @OA\Property(property="extension", type="string"),
 *       @OA\Property(property="phone", type="string")
 *     )
 *   ),
 *   @OA\Response(response=200, description="Agent created"),
 *   @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Get(
 *   path="/agents/{id}",
 *   summary="Get agent details",
 *   operationId="showAgent",
 *   tags={"Agent"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Agent details"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Put(
 *   path="/agents/{id}",
 *   summary="Update agent",
 *   operationId="updateAgent",
 *   tags={"Agent"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(
 *     @OA\JsonContent(
 *       @OA\Property(property="name", type="string"),
 *       @OA\Property(property="email", type="string"),
 *       @OA\Property(property="phone", type="string"),
 *       @OA\Property(property="extension", type="string")
 *     )
 *   ),
 *   @OA\Response(response=200, description="Agent updated"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Delete(
 *   path="/agents/{id}",
 *   summary="Deactivate agent",
 *   operationId="deactivateAgent",
 *   tags={"Agent"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Agent deactivated"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Post(
 *   path="/agents/{id}/reset-password",
 *   summary="Reset agent password",
 *   operationId="resetAgentPassword",
 *   tags={"Agent"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"password"},
 *       @OA\Property(property="password", type="string", format="password")
 *     )
 *   ),
 *   @OA\Response(response=200, description="Password reset"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Post(
 *   path="/agents/{id}/activate",
 *   summary="Reactivate a deactivated agent",
 *   operationId="activateAgent",
 *   tags={"Agent"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Agent activated"),
 *   @OA\Response(response=404, description="Not found")
 * )
 */
class AgentController extends Controller
{
    private WelcomeEmailService $welcomeEmail;

    public function __construct(WelcomeEmailService $welcomeEmail)
    {
        $this->welcomeEmail = $welcomeEmail;
    }

    // ----------------------------------------------------------------
    // List agents
    // ----------------------------------------------------------------

    /**
     * GET /agents/all-users
     *
     * Returns all active users (including admins) for dropdowns.
     */
    public function allUsers(Request $request)
    {
        $clientId = $request->auth->parent_id;

        $users = User::join('roles', 'users.role', '=', 'roles.id')
            ->where('users.parent_id', $clientId)
            ->where('users.is_deleted', 0)
            ->where('users.status', 1)
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.email', 'roles.name as role_name', 'roles.level as role_level')
            ->orderBy('roles.level', 'desc')
            ->orderBy('users.first_name')
            ->get();

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * GET /agents
     *
     * Query params: search, status (0|1), role_id, start, limit
     */
    public function index(Request $request)
    {
        $clientId       = $request->auth->parent_id;
        $requestorLevel = (int) ($request->auth->level ?? 0);
        $requestorId    = (int) $request->auth->id;

        $search  = $request->input('search', '');
        $status  = $request->input('status', null);
        $roleId  = $request->input('role_id', null);
        $start   = (int) $request->input('start', 0);
        $limit   = (int) $request->input('limit', 25);

        // Only show users with agent-level roles (level < 7, i.e. below admin)
        $query = User::join('roles', 'users.role', '=', 'roles.id')
            ->where('users.parent_id', $clientId)
            ->where('roles.level', '<', 7)
            ->where('users.is_deleted', 0);

        // Low-level agents (level ≤ 2) can only see themselves
        if ($requestorLevel <= 2) {
            $query->where('users.id', $requestorId);
        }

        $query
            ->select(
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.mobile',
                'users.extension',
                'users.alt_extension',
                'users.status',
                'users.role',
                'roles.name as role_name',
                'roles.level as role_level',
                'users.created_at',
                'users.updated_at'
            )
            ->orderBy('users.id', 'desc');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('users.first_name', 'LIKE', "%{$search}%")
                  ->orWhere('users.last_name',  'LIKE', "%{$search}%")
                  ->orWhere('users.email',       'LIKE', "%{$search}%")
                  ->orWhere('users.extension',   'LIKE', "%{$search}%")
                  ->orWhere('users.mobile',      'LIKE', "%{$search}%")
                  ->orWhere('roles.name',        'LIKE', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('users.status', (int) $status);
        }

        if ($roleId) {
            $query->where('users.role', (int) $roleId);
        }

        $total  = $query->count();
        $agents = $query->offset($start)->limit($limit)->get();

        return response()->json([
            'success'    => true,
            'message'    => 'Agents retrieved',
            'total'      => $total,
            'data'       => $agents,
        ]);
    }

    // ----------------------------------------------------------------
    // Show one agent
    // ----------------------------------------------------------------

    /**
     * GET /agents/{id}
     */
    public function show(Request $request, int $id)
    {
        $clientId       = $request->auth->parent_id;
        $requestorLevel = (int) ($request->auth->level ?? 0);
        $requestorId    = (int) $request->auth->id;

        // Low-level agents can only view their own profile
        if ($requestorLevel <= 2 && $id !== $requestorId) {
            return $this->failResponse('You can only view your own profile', [], null, 403);
        }

        $agent = User::join('roles', 'users.role', '=', 'roles.id')
            ->where('users.id', $id)
            ->where('users.parent_id', $clientId)
            ->select('users.*', 'roles.name as role_name', 'roles.level as role_level')
            ->first();

        if (!$agent) {
            return $this->failResponse('Agent not found', [], null, 404);
        }

        unset($agent->password);
        return $this->successResponse('Agent retrieved', $agent->toArray());
    }

    // ----------------------------------------------------------------
    // Create agent
    // ----------------------------------------------------------------

    /**
     * POST /agents
     *
     * Required: first_name, email, password, role_id
     * Optional: last_name, mobile, country_code, send_welcome_email
     */
    public function store(Request $request)
    {
        $clientId      = $request->auth->parent_id;
        $requestorLevel = (int) ($request->auth->level ?? 0);

        if ($requestorLevel < 7) {
            return $this->failResponse('Unauthorized — admin role required', [], null, 403);
        }

        $validator = Validator::make($request->all(), [
            'first_name'   => 'required|string|max:100',
            'last_name'    => 'nullable|string|max:100',
            'email'        => 'required|email|unique:master.users,email',
            'mobile'       => 'nullable|numeric|digits_between:7,15',
            'country_code' => 'nullable|string|max:5',
            'password'     => 'required|string|min:8|max:64|confirmed',
            'role_id'      => 'required|integer|exists:master.roles,id',
        ], [
            'email.unique'    => 'This email address is already registered.',
            'password.min'    => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
            'role_id.exists'  => 'Invalid role selected.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Verify the role level is lower than the requestor (can't create equal/higher roles)
        $role = Role::find($request->input('role_id'));
        if ($role && $role->level >= $requestorLevel) {
            return $this->failResponse('You cannot assign a role equal to or higher than your own.', [], null, 403);
        }

        // ── Seat limit check ────────────────────────────────────────────
        $seatCheck = PlanService::checkSeatLimit($clientId);
        if (!$seatCheck['allowed']) {
            return response()->json([
                'success' => false,
                'message' => "Agent seat limit reached ({$seatCheck['current']}/{$seatCheck['max']}). Upgrade your plan to add more agents.",
                'code'    => 'SEAT_LIMIT_REACHED',
                'data'    => ['current' => $seatCheck['current'], 'max' => $seatCheck['max']],
            ], 402);
        }

        try {
            DB::beginTransaction();

            // Fetch admin user's company/asterisk details
            $adminUser = User::where('id', $request->auth->id)->first();

            $plainPassword = $request->input('password');

            $agentData = [
                'first_name'        => $request->input('first_name'),
                'last_name'         => $request->input('last_name', ''),
                'email'             => $request->input('email'),
                'mobile'            => $request->input('mobile'),
                'password'          => Hash::make($plainPassword),
                'parent_id'         => $clientId,
                'base_parent_id'    => $adminUser->base_parent_id ?? $clientId,
                'role'              => $request->input('role_id'),
                'company_name'      => $adminUser->company_name ?? null,
                'asterisk_server_id'=> $adminUser->asterisk_server_id ?? null,
                'status'            => 1,
                'is_deleted'        => 0,
                'created_at'        => Carbon::now(),
                'updated_at'        => Carbon::now(),
            ];

            $agentId = DB::connection('master')->table('users')->insertGetId($agentData);

            DB::commit();

            // Fire-and-forget welcome email with credentials
            if ($request->input('send_welcome_email', true)) {
                $this->welcomeEmail->sendAgentWelcome(
                    email:        $request->input('email'),
                    agentName:    $request->input('first_name') . ' ' . $request->input('last_name', ''),
                    username:     $request->input('email'),
                    plainPassword: $plainPassword,
                    loginUrl:     env('PORTAL_NAME', '#'),
                    companyName:  $adminUser->company_name ?? env('SITE_NAME', 'Dialer')
                );
            }

            // Update admin's onboarding progress (first agent created)
            try {
                $progress = OnboardingProgress::findOrInit($request->auth->id, $clientId);
                if (!$progress->first_agent_created) {
                    $progress->completeStep('first_agent_created');
                }
            } catch (\Throwable $e) {
                Log::warning('AgentController: onboarding step update failed', ['error' => $e->getMessage()]);
            }

            Log::info('AgentController: agent created', [
                'agent_id'   => $agentId,
                'email'      => $request->input('email'),
                'created_by' => $request->auth->id,
                'client_id'  => $clientId,
            ]);

            // Invalidate dashboard stats so user count reflects the new agent
            CacheService::tenantForget($clientId, CacheService::KEY_DASHBOARD_STATS);

            return $this->successResponse('Agent created successfully', [
                'agent_id' => $agentId,
                'email'    => $request->input('email'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failResponse('Failed to create agent', [], $e);
        }
    }

    // ----------------------------------------------------------------
    // Update agent
    // ----------------------------------------------------------------

    /**
     * PUT /agents/{id}
     *
     * Updatable: first_name, last_name, mobile, country_code, role_id, status
     */
    public function update(Request $request, int $id)
    {
        $clientId       = $request->auth->parent_id;
        $requestorLevel = (int) ($request->auth->level ?? 0);

        if ($requestorLevel < 7) {
            return $this->failResponse('Unauthorized — admin role required', [], null, 403);
        }

        $agent = User::where('id', $id)->where('parent_id', $clientId)->first();
        if (!$agent) {
            return $this->failResponse('Agent not found', [], null, 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name'   => 'sometimes|required|string|max:100',
            'last_name'    => 'nullable|string|max:100',
            'mobile'       => 'nullable|numeric|digits_between:7,15',
            'country_code' => 'nullable|string|max:5',
            'role_id'      => 'sometimes|required|integer|exists:master.roles,id',
            'status'       => 'sometimes|required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        if ($request->has('role_id')) {
            $role = Role::find($request->input('role_id'));
            if ($role && $role->level >= $requestorLevel) {
                return $this->failResponse('Cannot assign a role equal to or higher than your own.', [], null, 403);
            }
        }

        $updates = array_filter([
            'first_name'   => $request->input('first_name'),
            'last_name'    => $request->input('last_name'),
            'mobile'       => $request->input('mobile'),
            'country_code' => $request->input('country_code'),
            'role'         => $request->input('role_id'),
            'status'       => $request->input('status') !== null ? (int) $request->input('status') : null,
            'updated_at'   => Carbon::now(),
        ], fn($v) => $v !== null);

        $agent->update($updates);

        Log::info('AgentController: agent updated', ['agent_id' => $id, 'by' => $request->auth->id]);

        // Invalidate dashboard stats (status/role changes affect visible counts)
        CacheService::tenantForget($clientId, CacheService::KEY_DASHBOARD_STATS);

        unset($agent->password);
        return $this->successResponse('Agent updated successfully', $agent->fresh()->toArray());
    }

    // ----------------------------------------------------------------
    // Deactivate agent (soft delete)
    // ----------------------------------------------------------------

    /**
     * DELETE /agents/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $clientId       = $request->auth->parent_id;
        $requestorLevel = (int) ($request->auth->level ?? 0);

        if ($requestorLevel < 7) {
            return $this->failResponse('Unauthorized — admin role required', [], null, 403);
        }

        // Prevent users from deleting themselves
        if ((int) $request->auth->id === $id) {
            return $this->failResponse('You cannot deactivate your own account', [], null, 403);
        }

        $agent = User::where('id', $id)->where('parent_id', $clientId)->first();
        if (!$agent) {
            return $this->failResponse('Agent not found', [], null, 404);
        }

        $agent->is_deleted = 1;
        $agent->status     = 0;
        $agent->updated_at = Carbon::now();
        $agent->save();

        Log::info('AgentController: agent deactivated', ['agent_id' => $id, 'by' => $request->auth->id]);

        // Invalidate dashboard stats so user count is refreshed
        CacheService::tenantForget($clientId, CacheService::KEY_DASHBOARD_STATS);

        return $this->successResponse('Agent deactivated successfully', ['agent_id' => $id]);
    }

    // ----------------------------------------------------------------
    // Activate agent
    // ----------------------------------------------------------------

    /**
     * POST /agents/{id}/activate
     */
    public function activate(Request $request, int $id)
    {
        $clientId       = $request->auth->parent_id;
        $requestorLevel = (int) ($request->auth->level ?? 0);

        if ($requestorLevel < 7) {
            return $this->failResponse('Unauthorized — admin role required', [], null, 403);
        }

        $agent = User::where('id', $id)->where('parent_id', $clientId)->first();
        if (!$agent) {
            return $this->failResponse('Agent not found', [], null, 404);
        }

        // ── Seat limit check before reactivation ────────────────────────
        $seatCheck = PlanService::checkSeatLimit($clientId);
        if (!$seatCheck['allowed']) {
            return response()->json([
                'success' => false,
                'message' => "Cannot reactivate — agent seat limit reached ({$seatCheck['current']}/{$seatCheck['max']}). Upgrade your plan.",
                'code'    => 'SEAT_LIMIT_REACHED',
                'data'    => ['current' => $seatCheck['current'], 'max' => $seatCheck['max']],
            ], 402);
        }

        $agent->is_deleted = 0;
        $agent->status     = 1;
        $agent->updated_at = Carbon::now();
        $agent->save();

        Log::info('AgentController: agent activated', ['agent_id' => $id, 'by' => $request->auth->id]);

        // Invalidate dashboard stats so user count is refreshed
        CacheService::tenantForget($clientId, CacheService::KEY_DASHBOARD_STATS);

        return $this->successResponse('Agent activated successfully', ['agent_id' => $id]);
    }

    // ----------------------------------------------------------------
    // Reset agent password
    // ----------------------------------------------------------------

    /**
     * POST /agents/{id}/reset-password
     *
     * Body: { "password": "...", "password_confirmation": "...", "notify_agent": true }
     */
    public function resetPassword(Request $request, int $id)
    {
        $clientId       = $request->auth->parent_id;
        $requestorLevel = (int) ($request->auth->level ?? 0);

        if ($requestorLevel < 7) {
            return $this->failResponse('Unauthorized — admin role required', [], null, 403);
        }

        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8|max:64|confirmed',
        ], [
            'password.confirmed' => 'Passwords do not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $agent = User::where('id', $id)->where('parent_id', $clientId)->first();
        if (!$agent) {
            return $this->failResponse('Agent not found', [], null, 404);
        }

        $plainPassword = $request->input('password');
        $agent->password   = Hash::make($plainPassword);
        $agent->updated_at = Carbon::now();
        $agent->save();

        // Also update SIP extension secret if applicable
        if ($agent->extension) {
            DB::connection('master')->table('user_extensions')
                ->where('username', $agent->extension)
                ->update(['secret' => $plainPassword, 'updated_at' => Carbon::now()]);
            PjsipRealtimeService::syncPassword($agent->extension, $plainPassword);
        }
        if ($agent->alt_extension) {
            DB::connection('master')->table('user_extensions')
                ->where('username', $agent->alt_extension)
                ->update(['secret' => $plainPassword, 'updated_at' => Carbon::now()]);
            PjsipRealtimeService::syncPassword($agent->alt_extension, $plainPassword);
        }

        // Optionally email the agent their new password
        if ($request->input('notify_agent', false)) {
            try {
                $adminUser = User::find($request->auth->id);
                $this->welcomeEmail->sendAgentWelcome(
                    email:        $agent->email,
                    agentName:    $agent->first_name . ' ' . $agent->last_name,
                    username:     $agent->email,
                    plainPassword: $plainPassword,
                    loginUrl:     env('PORTAL_NAME', '#'),
                    companyName:  $adminUser->company_name ?? env('SITE_NAME', 'Dialer')
                );
            } catch (\Throwable $e) {
                Log::warning('AgentController: reset-password notification failed', ['error' => $e->getMessage()]);
            }
        }

        Log::info('AgentController: agent password reset', ['agent_id' => $id, 'by' => $request->auth->id]);

        return $this->successResponse('Password reset successfully', ['agent_id' => $id]);
    }

    // ----------------------------------------------------------------
    // List available roles (for dropdowns)
    // ----------------------------------------------------------------

    /**
     * GET /agents/roles
     */
    public function roles(Request $request)
    {
        $requestorLevel = (int) ($request->auth->level ?? 0);

        // Return only roles the requestor can assign
        $roles = Role::where('level', '<', $requestorLevel)
            ->where('status', 1)
            ->orderBy('level', 'asc')
            ->get(['id', 'name', 'level']);

        return $this->successResponse('Roles', $roles->toArray());
    }
}
