<?php

namespace App\Http\Middleware;

use App\Model\User;
use App\Services\ExtensionGroupService;
use App\Services\RolesService;
use Closure;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JwtMiddleware
{
    protected $table = 'users';

    public function handle(Request $request, Closure $next, $guard = null)
    {
        $token = $request->bearerToken() ?? $request->get('token');
        // Optional identity verification via query-string only.
        // Using query() instead of get() so POST/PUT body fields named
        // 'user_id' (used by 13+ controllers for business operations like
        // assigning leads, shifts, packages, permissions, etc.) do NOT
        // collide with this auth check.
        $id = $request->query('user_id', null);

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token not provided.',
                'data' => []
            ], 400);
        }

        try {
            $credentials = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
        } catch (ExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Provided token is expired.',
                'data' => []
            ], 401);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while decoding token.',
                'data' => []
            ], 401);
        }

        // Check token revocation blacklist (Redis)
        if (\App\Http\Helper\JwtToken::isBlacklisted($token)) {
            return response()->json([
                'success' => false,
                'message' => 'Token has been revoked.',
                'data'    => []
            ], 401);
        }

        $user = User::find($credentials->sub);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found.',
                'data' => []
            ], 401);
        }

        if ($user->is_deleted) {
            return response()->json([
                'success' => false,
                'code'    => 'ACCOUNT_DEACTIVATED',
                'message' => 'Your account has been deactivated. Please contact support.',
                'data' => []
            ], 403);
        }

        if (isset($user->status) && $user->status == 0) {
            return response()->json([
                'success' => false,
                'code'    => 'ACCOUNT_INACTIVE',
                'message' => 'Your account is not active. Please contact support.',
                'data' => []
            ], 403);
        }

        if ($id && $id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Invalid Token.',
                'data' => []
            ], 403);
        }

        $userData = $user->toArray();

        if (!empty($userData["parent_id"])) {
            $role = $user->getClientRole($userData["parent_id"]);
            if (empty($role)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'data' => []
                ], 403);
            }
            $userData["role"] = $role["roleId"];
            $userData["level"] = $role["roleLevel"];
            try {
                $userData["groups"] = ExtensionGroupService::getExtensionGroups($userData["parent_id"], $userData["extension"]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('JwtMiddleware: extension group lookup failed for client ' . $userData["parent_id"], [
                    'error' => $e->getMessage(),
                ]);
                $userData["groups"] = [0];
            }
        } else {
            $role = RolesService::getById($user->role);
            $userData["level"] = $role["level"];
            $userData["groups"] = [0];
        }

        // ── Client override (system admin switching into a client workspace) ──
        // If the JWT carries a `client_override` claim and the authenticated user
        // is a superadmin (level ≥ 9), swap parent_id so all controllers query
        // the target client's DB. Also update base_parent_id so queries against
        // the master users table (e.g. Extension lookups) use the correct root
        // account, not the admin's own root.
        if (isset($credentials->client_override) && ($userData["level"] ?? 0) >= 9) {
            $overrideParentId = (int) $credentials->client_override;
            $userData["parent_id"] = $overrideParentId;

            // Look up the base_parent_id used by users in the target client.
            // First preference: users that are native to this client (base_parent_id = parent_id).
            // This avoids picking up cross-client sub-account users who share the same
            // parent_id but belong to a different base tree.
            $clientBaseParentId = DB::connection('master')
                ->table('users')
                ->where('parent_id', $overrideParentId)
                ->where('base_parent_id', $overrideParentId)
                ->whereNotNull('base_parent_id')
                ->value('base_parent_id');

            // Fallback for true sub-accounts (base_parent_id != parent_id).
            if (!$clientBaseParentId) {
                $clientBaseParentId = DB::connection('master')
                    ->table('users')
                    ->where('parent_id', $overrideParentId)
                    ->whereNotNull('base_parent_id')
                    ->orderByDesc('user_level')
                    ->value('base_parent_id');
            }

            if ($clientBaseParentId) {
                $userData["base_parent_id"] = (int) $clientBaseParentId;
            }
        }

        $request->setUserResolver(fn () => $user);
        $request->auth = (object) $userData;

        $client = $request->header('x-client', null);
        $request->xClient = ($client === env("X_CLIENT") ? "internal" : "external");

        // Throttled session last_active_at update (every 5 minutes)
        $tokenHash = hash('sha256', $token);
        $sessionCacheKey = "session_update:{$tokenHash}";
        if (!\Illuminate\Support\Facades\Cache::has($sessionCacheKey)) {
            \App\Model\Master\UserSession::where('token_hash', $tokenHash)
                ->update(['last_active_at' => now()]);
            \Illuminate\Support\Facades\Cache::put($sessionCacheKey, true, 300);
        }

        return $next($request);
    }
}
