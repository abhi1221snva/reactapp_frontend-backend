<?php

namespace App\Http\Controllers;

use App\Services\RoutePermissionService;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * GET /user/menu?engine=dialer|crm
     *
     * Returns the sidebar menu items filtered by the authenticated
     * user's role permissions. System administrators (level 11)
     * receive all items with no filtering.
     */
    public function getUserMenu(Request $request)
    {
        $userLevel = (int) ($request->auth->level ?? 1);
        $roleId    = (int) ($request->auth->role ?? 2);
        $engine    = $request->get('engine', 'dialer');

        if (!in_array($engine, ['dialer', 'crm'], true)) {
            $engine = 'dialer';
        }

        $service = app(RoutePermissionService::class);

        if ($userLevel >= RoutePermissionService::SYSTEM_ADMIN_LEVEL) {
            $items = $service->getAllMenuItems($engine);
        } else {
            $items = $service->getUserMenuItems($roleId, $userLevel, $engine);
        }

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }
}
