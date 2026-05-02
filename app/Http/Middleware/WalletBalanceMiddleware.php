<?php

namespace App\Http\Middleware;

use App\Services\WalletGuardService;
use Closure;
use Illuminate\Http\Request;

/**
 * Blocks billable actions when wallet balance is insufficient.
 *
 * Usage in routes:
 *   $router->post('call', ['middleware' => 'wallet.balance:call', ...])
 *   $router->post('sms',  ['middleware' => 'wallet.balance:sms',  ...])
 */
class WalletBalanceMiddleware
{
    /**
     * @param string $action 'call' or 'sms'
     */
    public function handle(Request $request, Closure $next, string $action = 'call')
    {
        $level = $request->auth->level ?? 0;

        // Bypass for system admins
        if ($level >= 9) {
            return $next($request);
        }

        $clientId = (int) $request->auth->parent_id;
        $check    = WalletGuardService::canAfford($clientId, $action);

        if (!$check['allowed']) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient wallet balance',
                'data'    => [
                    'balance'   => $check['balance'],
                    'required'  => $check['required'],
                    'shortfall' => $check['shortfall'],
                    'action'    => 'top_up_wallet',
                ],
            ], 402);
        }

        return $next($request);
    }
}
