<?php

namespace App\Http\Controllers;

use App\Model\Master\AuthEvent;
use App\Model\Master\UserSession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthEventController extends Controller
{
    /**
     * @OA\Post(
     *     path="/admin/auth-events",
     *     summary="List auth security events",
     *     tags={"Admin - Auth Events"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="event_type", type="string", example="login.failed"),
     *             @OA\Property(property="user_id", type="integer", example=5),
     *             @OA\Property(property="ip", type="string", example="203.0.113.42"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2026-05-01"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2026-05-03"),
     *             @OA\Property(property="lower_limit", type="integer", example=0),
     *             @OA\Property(property="upper_limit", type="integer", example=25)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Auth events list")
     * )
     */
    public function index(Request $request)
    {
        $this->validate($request, [
            'event_type' => 'string|max:50',
            'user_id'    => 'numeric',
            'ip'         => 'string|max:45',
            'start_date' => 'date',
            'end_date'   => 'date',
            'lower_limit'=> 'numeric',
            'upper_limit'=> 'numeric',
        ]);

        $userLevel = $request->auth->level;
        $clientId  = $request->auth->parent_id ?: $request->auth->id;

        $query = DB::connection('master')
            ->table('auth_events')
            ->join('users', 'auth_events.user_id', '=', 'users.id')
            ->select([
                'auth_events.id',
                'auth_events.user_id',
                'users.first_name',
                'users.last_name',
                'users.extension',
                'auth_events.event_type',
                'auth_events.ip_address',
                'auth_events.user_agent',
                'auth_events.metadata',
                'auth_events.created_at',
            ]);

        // Multi-tenant: client admins see only their users
        if ($userLevel < 9) {
            $query->where('users.parent_id', $clientId);
        }

        // Filters
        if ($request->filled('event_type')) {
            $query->where('auth_events.event_type', $request->input('event_type'));
        }

        if ($request->filled('user_id')) {
            $query->where('auth_events.user_id', $request->input('user_id'));
        }

        if ($request->filled('ip')) {
            $query->where('auth_events.ip_address', $request->input('ip'));
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = date('Y-m-d', strtotime($request->input('start_date'))) . ' 00:00:00';
            $end   = date('Y-m-d', strtotime($request->input('end_date'))) . ' 23:59:59';
            $query->whereBetween('auth_events.created_at', [$start, $end]);
        }

        // Get total count before pagination
        $countQuery = clone $query;
        $total = $countQuery->count();

        // Order
        $query->orderBy('auth_events.created_at', 'desc');

        // Pagination
        if ($request->filled('lower_limit') && $request->filled('upper_limit')) {
            $query->offset((int) $request->input('lower_limit'))
                  ->limit((int) $request->input('upper_limit'));
        } else {
            $query->limit(25);
        }

        $records = $query->get()->map(function ($row) {
            $row->metadata = $row->metadata ? json_decode($row->metadata, true) : null;
            return $row;
        });

        return response()->json([
            'success' => true,
            'message' => 'Auth events retrieved.',
            'total'   => $total,
            'data'    => $records,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/admin/auth-events/active-users",
     *     summary="Get currently active users",
     *     tags={"Admin - Auth Events"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(response=200, description="Active users list")
     * )
     */
    public function activeUsers(Request $request)
    {
        $userLevel = $request->auth->level;
        $clientId  = $request->auth->parent_id ?: $request->auth->id;

        $cutoff = Carbon::now()->subMinutes(5);

        $query = DB::connection('master')
            ->table('user_sessions')
            ->join('users', 'user_sessions.user_id', '=', 'users.id')
            ->where('user_sessions.last_active_at', '>=', $cutoff)
            ->select([
                'user_sessions.user_id',
                'users.first_name',
                'users.last_name',
                'user_sessions.device_type',
                'user_sessions.browser',
                'user_sessions.os',
                'user_sessions.ip_address',
                'user_sessions.last_active_at',
            ]);

        // Multi-tenant filtering
        if ($userLevel < 9) {
            $query->where('users.parent_id', $clientId);
        }

        $users = $query->orderBy('user_sessions.last_active_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'count' => $users->count(),
                'users' => $users,
            ],
        ]);
    }
}
