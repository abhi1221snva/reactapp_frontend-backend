<?php

namespace App\Http\Controllers;

use App\Model\Master\Client;
use App\Model\Master\SubscriptionPlan;
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

        // Resolve client's plan tier for menu gating
        $clientPlanOrder = 0;
        $clientId = (int) ($request->auth->parent_id ?? 0);
        if ($clientId > 0) {
            $client = Client::find($clientId);
            if ($client && $client->subscription_plan_id) {
                $plan = SubscriptionPlan::find($client->subscription_plan_id);
                $clientPlanOrder = $plan ? (int) $plan->plan_order : 0;
            }
        }

        $service = app(RoutePermissionService::class);

        if ($userLevel >= RoutePermissionService::SYSTEM_ADMIN_LEVEL) {
            $items = $service->getAllMenuItems($engine);
        } else {
            $items = $service->getUserMenuItems($roleId, $userLevel, $engine, $clientPlanOrder);
        }

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }
}
