<?php

namespace App\Http\Controllers;

use App\Model\TwilioAccount;
use App\Model\TwilioSubaccount;
use App\Model\Client\TwilioUsageLog;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TwilioAccountController extends Controller
{
    // ── Connect / update account ───────────────────────────────────────────

    public function connect(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;

        $sid   = trim($request->input('account_sid', ''));
        $token = trim($request->input('auth_token', ''));

        if (!$sid || !$token) {
            return $this->failResponse('account_sid and auth_token are required.', [], null, 422);
        }

        try {
            $service = new TwilioService($sid, $token);
            $info    = $service->verifyCredentials();
        } catch (\Exception $e) {
            Log::warning('Twilio connect failed', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Invalid Twilio credentials. Verify your Account SID and Auth Token.', [], $e, 401);
        }

        $account = TwilioAccount::updateOrCreate(
            ['client_id' => $clientId],
            [
                'account_sid'   => $sid,
                'auth_token'    => $token,
                'friendly_name' => $info['friendly_name'],
                'status'        => $info['status'],
            ]
        );

        Log::info('Twilio account connected', ['client' => $clientId, 'sid' => $sid]);

        return $this->successResponse('Twilio account connected successfully.', [
            'account' => $this->_safe($account),
        ]);
    }

    // ── Get account info ───────────────────────────────────────────────────

    public function getAccount(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $account  = TwilioAccount::where('client_id', $clientId)->first();

        if (!$account) {
            return $this->successResponse('No Twilio account configured.', ['account' => null]);
        }

        return $this->successResponse('OK', ['account' => $this->_safe($account)]);
    }

    // ── Disconnect ─────────────────────────────────────────────────────────

    public function disconnect(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        TwilioAccount::where('client_id', $clientId)->delete();
        return $this->successResponse('Twilio account disconnected.');
    }

    // ── Subaccounts ────────────────────────────────────────────────────────

    /**
     * Create a Twilio subaccount under the master platform account.
     * The client's twilio_accounts row is created/updated with the subaccount SID + token.
     */
    public function createSubaccount(Request $request)
    {
        $clientId     = $request->auth->parent_id ?: $request->auth->id;
        $friendlyName = $request->input('friendly_name', "Client {$clientId}");

        try {
            // Use master platform credentials to create the subaccount
            $masterSid   = env('TWILIO_SID');
            $masterToken = env('TWILIO_AUTH_TOKEN');

            if (!$masterSid || !$masterToken) {
                return $this->failResponse('Platform Twilio credentials not configured.', [], null, 500);
            }

            $service = new TwilioService($masterSid, $masterToken);
            $sub     = $service->createSubaccount($friendlyName);

            // Save to twilio_accounts (the client now uses this subaccount)
            $account = TwilioAccount::updateOrCreate(
                ['client_id' => $clientId],
                [
                    'friendly_name'     => $friendlyName,
                    'status'            => 'active',
                    'subaccount_sid'    => $sub['sid'],
                    'subaccount_token'  => $sub['auth_token'],
                ]
            );

            // Also persist in twilio_subaccounts
            TwilioSubaccount::updateOrCreate(
                ['sid' => $sub['sid']],
                [
                    'twilio_account_id' => $account->id,
                    'auth_token'        => $sub['auth_token'],
                    'friendly_name'     => $sub['friendly_name'],
                    'status'            => $sub['status'],
                ]
            );

            return $this->successResponse('Subaccount created.', ['subaccount' => $sub]);

        } catch (\Exception $e) {
            Log::error('Twilio subaccount create failed', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to create subaccount.', [$e->getMessage()], $e, 500);
        }
    }

    public function listSubaccounts(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $account  = TwilioAccount::where('client_id', $clientId)->first();

        if (!$account) {
            return $this->successResponse('OK', ['subaccounts' => []]);
        }

        $subs = TwilioSubaccount::where('twilio_account_id', $account->id)->get()
            ->map(fn($s) => [
                'id'            => $s->id,
                'sid'           => $s->sid,
                'friendly_name' => $s->friendly_name,
                'status'        => $s->status,
                'created_at'    => $s->created_at,
            ]);

        return $this->successResponse('OK', ['subaccounts' => $subs]);
    }

    public function suspendSubaccount(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $sid      = $request->input('sid');

        if (!$sid) {
            return $this->failResponse('sid is required.', [], null, 422);
        }

        try {
            $service = TwilioService::forClient($clientId);
            $service->suspendSubaccount($sid);

            TwilioSubaccount::where('sid', $sid)->update(['status' => 'suspended']);

            return $this->successResponse('Subaccount suspended.');
        } catch (\Exception $e) {
            return $this->failResponse('Failed to suspend subaccount.', [$e->getMessage()], $e, 500);
        }
    }

    // ── Usage ──────────────────────────────────────────────────────────────

    public function usage(Request $request)
    {
        $clientId  = $request->auth->parent_id ?: $request->auth->id;
        $startDate = $request->input('start_date');
        $endDate   = $request->input('end_date');

        try {
            $service = TwilioService::forClient($clientId);
            $records = $service->getUsage($startDate, $endDate);

            // Persist usage records for dashboard charts
            $conn = "mysql_{$clientId}";
            foreach ($records as $r) {
                TwilioUsageLog::on($conn)->updateOrCreate(
                    [
                        'category'   => $r['category'],
                        'start_date' => $r['start_date'],
                        'end_date'   => $r['end_date'],
                    ],
                    [
                        'description' => $r['description'],
                        'count'       => $r['count']       ?? 0,
                        'usage'       => $r['usage']       ?? 0,
                        'usage_unit'  => $r['usage_unit']  ?? null,
                        'price'       => $r['price']       ?? 0,
                        'price_unit'  => $r['price_unit']  ?? 'USD',
                        'synced_at'   => \Carbon\Carbon::now(),
                    ]
                );
            }

            // Summarise key metrics
            $calls = collect($records)->where('category', 'calls')->first();
            $sms   = collect($records)->where('category', 'sms')->first();
            $mins  = collect($records)->where('category', 'calls')->first();

            return $this->successResponse('OK', [
                'records' => $records,
                'summary' => [
                    'total_calls'    => (int)   ($calls['count'] ?? 0),
                    'total_sms'      => (int)   ($sms['count']   ?? 0),
                    'minutes_used'   => (float) ($mins['usage']  ?? 0),
                    'total_spend'    => collect($records)->sum(fn($r) => (float)($r['price'] ?? 0)),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Twilio usage fetch failed', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to fetch usage.', [$e->getMessage()], $e, 500);
        }
    }

    // ── Private ────────────────────────────────────────────────────────────

    private function _safe(TwilioAccount $account): array
    {
        return [
            'id'             => $account->id,
            'client_id'      => $account->client_id,
            'friendly_name'  => $account->friendly_name,
            'status'         => $account->status,
            'has_own_account'=> !empty($account->getRawOriginal('account_sid')),
            'has_subaccount' => !empty($account->subaccount_sid),
            'masked_token'   => $account->maskedToken(),
            'blocked_countries' => $account->blocked_countries ?? [],
            'created_at'     => $account->created_at,
        ];
    }
}
