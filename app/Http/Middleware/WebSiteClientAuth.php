<?php

namespace App\Http\Middleware;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Support\Facades\Log;


class WebSiteClientAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        
        $client = $request->header('web-client', null);
        $request->webClient = ($client === env("X_WEB_CLIENT")?"website":"portal");
        if($request->webClient == 'website')
        {
            return $next($request);
        }
    }
}
