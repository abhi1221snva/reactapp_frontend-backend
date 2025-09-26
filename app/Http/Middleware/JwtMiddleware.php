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

class JwtMiddleware
{
    protected $table = 'users';

    public function handle(Request $request, Closure $next, $guard = null)
    {
        $token = $request->bearerToken() ?? $request->get('token');
        $id = $request->get('id', null);

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

        $user = User::find($credentials->sub);

        if (!$user || $user->is_deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
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
            $userData["groups"] = ExtensionGroupService::getExtensionGroups($userData["parent_id"], $userData["extension"]);
        } else {
            $role = RolesService::getById($user->role);
            $userData["level"] = $role["level"];
            $userData["groups"] = [0];
        }

        $request->setUserResolver(fn () => $user);
        $request->auth = (object) $userData;

        $client = $request->header('x-client', null);
        $request->xClient = ($client === env("X_CLIENT") ? "internal" : "external");

        return $next($request);
    }
}
