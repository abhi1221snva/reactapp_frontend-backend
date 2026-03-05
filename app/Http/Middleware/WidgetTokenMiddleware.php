<?php

namespace App\Http\Middleware;

use App\Model\TeamChatWidgetToken;
use App\Model\User;
use App\Services\ExtensionGroupService;
use Closure;
use Illuminate\Http\Request;

class WidgetTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?? $request->get('token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Widget token not provided.',
                'data' => []
            ], 401);
        }

        $widgetToken = TeamChatWidgetToken::where('token', $token)
            ->where('is_active', true)
            ->first();

        if (!$widgetToken) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid widget token.',
                'data' => []
            ], 401);
        }

        if (!$widgetToken->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Widget token has expired.',
                'data' => []
            ], 401);
        }

        // Check domain if Origin header is present
        $origin = $request->header('Origin');
        if ($origin) {
            $domain = parse_url($origin, PHP_URL_HOST);
            if (!$widgetToken->isDomainAllowed($domain)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Domain not authorized for this widget.',
                    'data' => []
                ], 403);
            }
        }

        // Get user data
        $user = User::find($widgetToken->user_id);
        if (!$user || $user->is_deleted) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or deleted.',
                'data' => []
            ], 404);
        }

        // Build user data similar to JwtMiddleware
        $userData = $user->toArray();
        $userData['parent_id'] = $widgetToken->parent_id;

        if (!empty($userData['parent_id'])) {
            $role = $user->getClientRole($userData['parent_id']);
            if (!empty($role)) {
                $userData['role'] = $role['roleId'];
                $userData['level'] = $role['roleLevel'];
                $userData['groups'] = ExtensionGroupService::getExtensionGroups($userData['parent_id'], $userData['extension'] ?? null);
            } else {
                $userData['role'] = 'user';
                $userData['level'] = 1;
                $userData['groups'] = [0];
            }
        } else {
            $userData['role'] = 'user';
            $userData['level'] = 1;
            $userData['groups'] = [0];
        }

        $request->setUserResolver(fn () => $user);
        $request->auth = (object) $userData;
        $request->widgetToken = $widgetToken;
        $request->xClient = 'widget';

        // Update last used
        $widgetToken->markAsUsed();

        return $next($request);
    }
}
