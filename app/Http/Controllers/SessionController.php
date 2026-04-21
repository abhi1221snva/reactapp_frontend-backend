<?php

namespace App\Http\Controllers;

use App\Model\Master\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SessionController extends Controller
{
    /**
     * GET /auth/sessions
     * List active sessions for the current user.
     */
    public function index(Request $request)
    {
        $currentHash = hash('sha256', $request->bearerToken() ?? '');

        $sessions = UserSession::where('user_id', $request->auth->id)
            ->orderByDesc('last_active_at')
            ->get()
            ->map(fn($s) => [
                'id'          => $s->id,
                'device'      => $s->device_type,
                'browser'     => trim(($s->browser ?? 'Unknown') . ' on ' . ($s->os ?? 'Unknown')),
                'ip_address'  => $s->ip_address,
                'last_active' => $s->last_active_at?->toIso8601String(),
                'created_at'  => $s->created_at?->toIso8601String(),
                'is_current'  => $s->token_hash === $currentHash,
            ]);

        return $this->successResponse('Active sessions', ['sessions' => $sessions]);
    }

    /**
     * DELETE /auth/sessions/{id}
     * Revoke a specific session by blacklisting its token.
     */
    public function destroy(Request $request, $id)
    {
        $session = UserSession::where('id', $id)
            ->where('user_id', $request->auth->id)
            ->first();

        if (!$session) {
            return $this->failResponse('Session not found.', [], null, 404);
        }

        // Blacklist the JWT associated with this session
        Cache::put(
            'jwt_blacklist:' . $session->token_hash,
            true,
            14 * 60 * 60 // max possible JWT lifetime
        );
        $session->delete();

        \App\Services\AuthAuditService::log($request->auth->id, 'session.revoked', [
            'session_id' => $id,
        ]);

        return $this->successResponse('Session revoked.');
    }

    /**
     * DELETE /auth/sessions
     * Revoke all sessions except the current one.
     */
    public function destroyAll(Request $request)
    {
        $currentHash = hash('sha256', $request->bearerToken() ?? '');

        $otherSessions = UserSession::where('user_id', $request->auth->id)
            ->where('token_hash', '!=', $currentHash)
            ->get();

        foreach ($otherSessions as $session) {
            Cache::put(
                'jwt_blacklist:' . $session->token_hash,
                true,
                14 * 60 * 60
            );
            $session->delete();
        }

        \App\Services\AuthAuditService::log($request->auth->id, 'session.revoked', [
            'count' => $otherSessions->count(),
            'action' => 'revoke_all_others',
        ]);

        return $this->successResponse('All other sessions revoked.', [
            'revoked_count' => $otherSessions->count(),
        ]);
    }
}
