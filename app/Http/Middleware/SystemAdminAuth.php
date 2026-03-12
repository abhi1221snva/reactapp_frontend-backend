<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SystemAdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (($request->auth->level ?? 0) < 11) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        return $next($request);
    }
}
