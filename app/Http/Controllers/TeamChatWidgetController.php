<?php

namespace App\Http\Controllers;

use App\Model\TeamChatWidgetToken;
use App\Model\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class TeamChatWidgetController extends Controller
{
    /**
     * Validate widget token and return user/config data
     * This endpoint uses widget token auth, not JWT
     */
    public function validateToken(Request $request)
    {
        try {
            $token = $this->extractBearerToken($request);

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Widget token is required'
                ], 401);
            }

            $widgetToken = TeamChatWidgetToken::where('token', $token)
                ->where('is_active', true)
                ->first();

            if (!$widgetToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid widget token'
                ], 401);
            }

            if (!$widgetToken->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Widget token has expired'
                ], 401);
            }

            // Check domain if Origin header is present
            $origin = $request->header('Origin');
            if ($origin) {
                $domain = parse_url($origin, PHP_URL_HOST);
                if (!$widgetToken->isDomainAllowed($domain)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Domain not authorized for this widget'
                    ], 403);
                }
            }

            // Get user data
            $user = User::find($widgetToken->user_id);
            if (!$user || $user->is_deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found or deleted'
                ], 404);
            }

            // Update last used
            $widgetToken->markAsUsed();

            return response()->json([
                'success' => true,
                'message' => 'Token validated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => trim($user->first_name . ' ' . $user->last_name),
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'profile_pic' => $user->profile_pic,
                    ],
                    'parent_id' => $widgetToken->parent_id,
                    'pusher_key' => env('PUSHER_APP_KEY'),
                    'pusher_cluster' => env('PUSHER_APP_CLUSTER'),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Widget token validation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Token validation failed'
            ], 500);
        }
    }

    /**
     * Get all widget tokens for the authenticated user's organization
     */
    public function getTokens(Request $request)
    {
        try {
            $parentId = $request->auth->parent_id;
            $userId = $request->auth->id;
            $role = $request->auth->role;

            // Only admins can view all tokens, users can only view their own
            $query = TeamChatWidgetToken::where('parent_id', $parentId);

            if ($role !== 'owner' && $role !== 'admin') {
                $query->where('user_id', $userId);
            }

            $tokens = $query->with('user:id,first_name,last_name,email')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($token) {
                    return [
                        'id' => $token->id,
                        'name' => $token->name,
                        'token' => $this->maskToken($token->token),
                        'user' => $token->user ? [
                            'id' => $token->user->id,
                            'name' => trim($token->user->first_name . ' ' . $token->user->last_name),
                            'email' => $token->user->email,
                        ] : null,
                        'allowed_domains' => $token->allowed_domains,
                        'is_active' => $token->is_active,
                        'expires_at' => $token->expires_at?->toIso8601String(),
                        'last_used_at' => $token->last_used_at?->toIso8601String(),
                        'created_at' => $token->created_at->toIso8601String(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $tokens
            ]);
        } catch (\Exception $e) {
            Log::error('Get widget tokens error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve tokens'
            ], 500);
        }
    }

    /**
     * Create a new widget token
     */
    public function createToken(Request $request)
    {
        try {
            $this->validate($request, [
                'user_id' => 'required|integer',
                'name' => 'nullable|string|max:100',
                'allowed_domains' => 'nullable|array',
                'allowed_domains.*' => 'string|max:255',
                'expires_at' => 'nullable|date|after:now',
            ]);

            $parentId = $request->auth->parent_id;
            $authUserId = $request->auth->id;
            $role = $request->auth->role;
            $targetUserId = $request->input('user_id');

            // Verify the target user belongs to the same organization
            $targetUser = User::where('id', $targetUserId)
                ->where('parent_id', $parentId)
                ->where('is_deleted', 0)
                ->first();

            if (!$targetUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found in your organization'
                ], 404);
            }

            // Non-admins can only create tokens for themselves
            if ($role !== 'owner' && $role !== 'admin' && $targetUserId != $authUserId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only create tokens for yourself'
                ], 403);
            }

            $token = TeamChatWidgetToken::create([
                'user_id' => $targetUserId,
                'parent_id' => $parentId,
                'token' => TeamChatWidgetToken::generateToken(),
                'name' => $request->input('name', 'Widget Token'),
                'allowed_domains' => $request->input('allowed_domains', []),
                'is_active' => true,
                'expires_at' => $request->input('expires_at'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Widget token created successfully',
                'data' => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'token' => $token->token, // Return full token only on creation
                    'user' => [
                        'id' => $targetUser->id,
                        'name' => trim($targetUser->first_name . ' ' . $targetUser->last_name),
                        'email' => $targetUser->email,
                    ],
                    'allowed_domains' => $token->allowed_domains,
                    'is_active' => $token->is_active,
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'created_at' => $token->created_at->toIso8601String(),
                    'embed_code' => $this->generateEmbedCode($token),
                ]
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Create widget token error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create token'
            ], 500);
        }
    }

    /**
     * Get embed code for a specific token
     */
    public function getEmbedCode(Request $request, $tokenId)
    {
        try {
            $parentId = $request->auth->parent_id;
            $userId = $request->auth->id;
            $role = $request->auth->role;

            $query = TeamChatWidgetToken::where('id', $tokenId)
                ->where('parent_id', $parentId);

            if ($role !== 'owner' && $role !== 'admin') {
                $query->where('user_id', $userId);
            }

            $widgetToken = $query->first();

            if (!$widgetToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'embed_code' => $this->generateEmbedCode($widgetToken),
                    'embed_code_with_options' => $this->generateEmbedCodeWithOptions($widgetToken),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Get embed code error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get embed code'
            ], 500);
        }
    }

    /**
     * Revoke/delete a widget token
     */
    public function revokeToken(Request $request, $tokenId)
    {
        try {
            $parentId = $request->auth->parent_id;
            $userId = $request->auth->id;
            $role = $request->auth->role;

            $query = TeamChatWidgetToken::where('id', $tokenId)
                ->where('parent_id', $parentId);

            if ($role !== 'owner' && $role !== 'admin') {
                $query->where('user_id', $userId);
            }

            $widgetToken = $query->first();

            if (!$widgetToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not found'
                ], 404);
            }

            $widgetToken->delete();

            return response()->json([
                'success' => true,
                'message' => 'Token revoked successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Revoke widget token error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke token'
            ], 500);
        }
    }

    /**
     * Toggle token active status
     */
    public function toggleToken(Request $request, $tokenId)
    {
        try {
            $parentId = $request->auth->parent_id;
            $userId = $request->auth->id;
            $role = $request->auth->role;

            $query = TeamChatWidgetToken::where('id', $tokenId)
                ->where('parent_id', $parentId);

            if ($role !== 'owner' && $role !== 'admin') {
                $query->where('user_id', $userId);
            }

            $widgetToken = $query->first();

            if (!$widgetToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not found'
                ], 404);
            }

            $widgetToken->update(['is_active' => !$widgetToken->is_active]);

            return response()->json([
                'success' => true,
                'message' => 'Token ' . ($widgetToken->is_active ? 'activated' : 'deactivated') . ' successfully',
                'data' => [
                    'is_active' => $widgetToken->is_active
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Toggle widget token error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle token'
            ], 500);
        }
    }

    /**
     * Update token settings
     */
    public function updateToken(Request $request, $tokenId)
    {
        try {
            $this->validate($request, [
                'name' => 'nullable|string|max:100',
                'allowed_domains' => 'nullable|array',
                'allowed_domains.*' => 'string|max:255',
                'expires_at' => 'nullable|date',
            ]);

            $parentId = $request->auth->parent_id;
            $userId = $request->auth->id;
            $role = $request->auth->role;

            $query = TeamChatWidgetToken::where('id', $tokenId)
                ->where('parent_id', $parentId);

            if ($role !== 'owner' && $role !== 'admin') {
                $query->where('user_id', $userId);
            }

            $widgetToken = $query->first();

            if (!$widgetToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not found'
                ], 404);
            }

            $updateData = [];
            if ($request->has('name')) {
                $updateData['name'] = $request->input('name');
            }
            if ($request->has('allowed_domains')) {
                $updateData['allowed_domains'] = $request->input('allowed_domains');
            }
            if ($request->has('expires_at')) {
                $updateData['expires_at'] = $request->input('expires_at');
            }

            $widgetToken->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Token updated successfully',
                'data' => [
                    'id' => $widgetToken->id,
                    'name' => $widgetToken->name,
                    'allowed_domains' => $widgetToken->allowed_domains,
                    'expires_at' => $widgetToken->expires_at?->toIso8601String(),
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Update widget token error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update token'
            ], 500);
        }
    }

    /**
     * Get list of users available for widget tokens
     */
    public function getAvailableUsers(Request $request)
    {
        try {
            $parentId = $request->auth->parent_id;

            $users = User::where('parent_id', $parentId)
                ->where('is_deleted', 0)
                ->select('id', 'first_name', 'last_name', 'email', 'profile_pic')
                ->orderBy('first_name')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => trim($user->first_name . ' ' . $user->last_name),
                        'email' => $user->email,
                        'profile_pic' => $user->profile_pic,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Get available users error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get users'
            ], 500);
        }
    }

    /**
     * Generate simple embed code
     */
    private function generateEmbedCode(TeamChatWidgetToken $token)
    {
        $baseUrl = env('APP_URL', request()->getSchemeAndHttpHost());

        return <<<HTML
<!-- Team Chat Widget -->
<script>
  window.TEAM_CHAT_CONFIG = {
    token: "{$token->token}",
    baseUrl: "{$baseUrl}"
  };
</script>
<script src="{$baseUrl}/js/team-chat-widget-embed.js" async></script>
HTML;
    }

    /**
     * Generate embed code with customization options
     */
    private function generateEmbedCodeWithOptions(TeamChatWidgetToken $token)
    {
        $baseUrl = env('APP_URL', request()->getSchemeAndHttpHost());

        return <<<HTML
<!-- Team Chat Widget with Options -->
<script>
  window.TEAM_CHAT_CONFIG = {
    token: "{$token->token}",
    baseUrl: "{$baseUrl}",
    // Customization Options (optional)
    position: "bottom-right",    // "bottom-right", "bottom-left", "top-right", "top-left"
    theme: "light",              // "light" or "dark"
    primaryColor: "#4F46E5",     // Any hex color
    title: "Team Chat",          // Widget title
    autoOpen: false,             // Auto-open on page load
    enableNotifications: true,   // Browser notifications
    enableSounds: true           // Notification sounds
  };
</script>
<script src="{$baseUrl}/js/team-chat-widget-embed.js" async></script>
HTML;
    }

    /**
     * Mask token for display
     */
    private function maskToken($token)
    {
        if (strlen($token) <= 12) {
            return str_repeat('*', strlen($token));
        }
        return substr($token, 0, 8) . str_repeat('*', strlen($token) - 12) . substr($token, -4);
    }

    /**
     * Extract bearer token from request
     */
    private function extractBearerToken(Request $request)
    {
        $header = $request->header('Authorization', '');
        if (strpos($header, 'Bearer ') === 0) {
            return substr($header, 7);
        }
        return $request->input('token');
    }
}
