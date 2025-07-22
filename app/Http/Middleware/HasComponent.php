<?php

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Model\Role;
use Closure;
use App\Model\User;

class HasComponent
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next, $component)
    {
        //if user is superadmin then allowed
        if ($request->auth->level == role::USER_LEVEL_SUPER_ADMIN) {
            return $next($request);
        }

        if ($component == "match-uri")
            $component = str_replace('/', '_', $request->getRequestUri());

        $user = User::findOrFail($request->auth->id);

        //check for users who have package, components assigned
        // check for requested URI is present in module_components.url
        $arrAssignedUserComponents = $user->getAssignedUserComponents();
        $arrStrAssignedUserComponents = array_column($arrAssignedUserComponents, 'key');

        if (!in_array($component, $arrStrAssignedUserComponents)) {
            throw new ForbiddenException();
        }

        return $next($request);
    }
}
