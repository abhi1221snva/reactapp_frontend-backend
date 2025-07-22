<?php


namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;

class SuperAdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->auth->level < 9) {
            throw new UnauthorizedException("Unauthorized");
        }
        return $next($request);
    }
}
