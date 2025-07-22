<?php


namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;

class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->auth->level < 7) {
            throw new UnauthorizedException("Unauthorized");
        }
        return $next($request);
    }
}
