<?php

namespace App\Http\Controllers;

use App\Model\Client\TwilioNumber;
use App\Model\Client\CampaignNumber;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TwilioNumberController extends Controller
{
    // ── Search available numbers ───────────────────────────────────────────

    public function search(Request $request)
    {
        $clientId     = $request->auth->parent_id ?: $request->auth->id;
        $country      = strtoupper($request->input('country', 'US'));
        $areaCode     = $request->input('area_code');
        $capabilities = [
            'voice' => (bool) $request->input('voice', true),
            'sms'   => (bool) $request->input('sms', false),
            'mms'   => (bool) $request->input('mms', false),
        ];
        $limit = min((int) $request->input('limit', 20), 50);

        try {
            $service = TwilioService::forClient($clientId);
            $numbers = $service->searchNumbers($country, $areaCode, $capabilities, $limit);
            return $this->successResponse('OK', ['numbers' => $numbers, 'total' => count($numbers)]);
        } catch (\Exception $e) {
            Log::error('Twilio search numbers', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to search numbers.', [$e->getMessage()], $e, 500);
        }
    }

    // ── Purchase a number ──────────────────────────────────────────────────

    public function purchase(Request $request)
    {
        $clientId    = $request->auth->parent_id ?: $request->auth->id;
        $phoneNumber = $request->input('phone_number');

        if (!$phoneNumber) {
            return $this->failResponse('phone_number is required.', [], null, 422);
        }

        $conn = "mysql_{$clientId}";

        try {
            $service = TwilioService::forClient($clientId);
            $data    = $service->purchaseNumber($phoneNumber);

            $number = TwilioNumber::on($conn)->updateOrCreate(
                ['sid' => $data['sid']],
                [
                    'phone_number'  => $data['phone_number'],
                    'friendly_name' => $data['friendly_name'],
                    'country_code'  => $request->input('country', 'US'),
                    'capabilities'  => $data['capabilities'],
                    'status'        => 'active',
                    'voice_url'     => $data['voice_url'],
                    'sms_url'       => $data['sms_url'],
                ]
            );

            return $this->successResponse('Number purchased successfully.', ['number' => $number]);

        } catch (\Exception $e) {
            Log::error('Twilio purchase number', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to purchase number.', [$e->getMessage()], $e, 500);
        }
    }

    // ── Release a number ───────────────────────────────────────────────────

    public function release(Request $request, string $sid)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        $number = TwilioNumber::on($conn)->where('sid', $sid)->first();
        if (!$number) {
            return $this->failResponse('Number not found.', [], null, 404);
        }

        try {
            $service = TwilioService::forClient($clientId);
            $service->releaseNumber($sid);

            // Remove campaign assignments
            CampaignNumber::on($conn)->where('twilio_number_id', $number->id)->delete();

            $number->status = 'released';
            $number->save();

            return $this->successResponse('Number released.');

        } catch (\Exception $e) {
            Log::error('Twilio release number', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to release number.', [$e->getMessage()], $e, 500);
        }
    }

    // ── List owned numbers ─────────────────────────────────────────────────

    public function list(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        $perPage = (int) $request->input('limit', 20);
        $page    = max(1, (int) $request->input('page', 1));
        $search  = $request->input('search', '');

        // Sync live numbers from Twilio into local DB so the count stays accurate
        // even for numbers purchased directly in the Twilio console.
        try {
            $service     = TwilioService::forClient($clientId);
            $liveNumbers = $service->listNumbers(200);
            $liveSids    = [];

            foreach ($liveNumbers as $n) {
                TwilioNumber::on($conn)->updateOrCreate(
                    ['sid' => $n['sid']],
                    [
                        'phone_number'  => $n['phone_number'],
                        'friendly_name' => $n['phone_number'],
                        'capabilities'  => $n['capabilities'],
                        'status'        => 'active',
                    ]
                );
                $liveSids[] = $n['sid'];
            }

            // Mark numbers no longer in Twilio as released
            if (!empty($liveSids)) {
                TwilioNumber::on($conn)
                    ->where('status', 'active')
                    ->whereNotIn('sid', $liveSids)
                    ->update(['status' => 'released']);
            }
        } catch (\Exception $e) {
            Log::warning('Twilio number sync failed', ['client' => $clientId, 'err' => $e->getMessage()]);
        }

        $query = TwilioNumber::on($conn)->where('status', 'active');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                  ->orWhere('friendly_name', 'like', "%{$search}%");
            });
        }

        $total   = $query->count();
        $numbers = $query->orderByDesc('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return $this->successResponse('OK', [
            'numbers'      => $numbers,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
        ]);
    }

    // ── Campaign assignment ────────────────────────────────────────────────

    public function assignToCampaign(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $conn       = "mysql_{$clientId}";
        $campaignId = (int) $request->input('campaign_id');
        $numberIds  = (array) $request->input('number_ids', []);

        if (!$campaignId || empty($numberIds)) {
            return $this->failResponse('campaign_id and number_ids are required.', [], null, 422);
        }

        $assigned = 0;
        foreach ($numberIds as $numberId) {
            CampaignNumber::on($conn)->updateOrCreate(
                ['campaign_id' => $campaignId, 'twilio_number_id' => $numberId],
                ['is_active' => 1]
            );
            // Also set campaign_id on the number itself
            TwilioNumber::on($conn)->where('id', $numberId)
                ->update(['campaign_id' => $campaignId]);
            $assigned++;
        }

        return $this->successResponse("{$assigned} number(s) assigned to campaign.");
    }

    public function getByCampaign(Request $request, int $campaignId)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        $numbers = CampaignNumber::on($conn)
            ->with('twilioNumber')
            ->where('campaign_id', $campaignId)
            ->where('is_active', 1)
            ->get()
            ->map(fn($cn) => array_merge(
                $cn->twilioNumber?->toArray() ?? [],
                ['cn_id' => $cn->id, 'last_used_at' => $cn->last_used_at]
            ));

        return $this->successResponse('OK', ['numbers' => $numbers]);
    }

    public function unassignFromCampaign(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $conn       = "mysql_{$clientId}";
        $campaignId = (int) $request->input('campaign_id');
        $numberIds  = (array) $request->input('number_ids', []);

        if (!$campaignId || empty($numberIds)) {
            return $this->failResponse('campaign_id and number_ids are required.', [], null, 422);
        }

        CampaignNumber::on($conn)
            ->where('campaign_id', $campaignId)
            ->whereIn('twilio_number_id', $numberIds)
            ->delete();

        TwilioNumber::on($conn)
            ->whereIn('id', $numberIds)
            ->where('campaign_id', $campaignId)
            ->update(['campaign_id' => null]);

        return $this->successResponse('Numbers unassigned from campaign.');
    }
}
