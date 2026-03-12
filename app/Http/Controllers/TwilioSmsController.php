<?php

namespace App\Http\Controllers;

use App\Model\Client\TwilioSms;
use App\Services\TwilioService;
use App\Jobs\TwilioBulkSmsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Post(
 *   path="/twilio/sms/send",
 *   summary="Send a single SMS via Twilio",
 *   operationId="twilioSendSms",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"to","from","body"},
 *     @OA\Property(property="to", type="string", example="+15551234567"),
 *     @OA\Property(property="from", type="string", example="+15559876543"),
 *     @OA\Property(property="body", type="string"),
 *     @OA\Property(property="lead_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="SMS sent"),
 *   @OA\Response(response=422, description="Validation error"),
 *   @OA\Response(response=500, description="Twilio error")
 * )
 *
 * @OA\Post(
 *   path="/twilio/sms/bulk",
 *   summary="Send bulk SMS via Twilio",
 *   operationId="twilioBulkSms",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"messages"},
 *     @OA\Property(property="messages", type="array", @OA\Items(
 *       @OA\Property(property="to", type="string"),
 *       @OA\Property(property="body", type="string")
 *     )),
 *     @OA\Property(property="from", type="string")
 *   )),
 *   @OA\Response(response=200, description="Bulk SMS job dispatched"),
 *   @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Get(
 *   path="/twilio/sms",
 *   summary="List Twilio SMS logs",
 *   operationId="twilioListSms",
 *   tags={"Twilio"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="date_till", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
 *   @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=25)),
 *   @OA\Response(response=200, description="SMS list")
 * )
 */
class TwilioSmsController extends Controller
{
    // ── Send single SMS ────────────────────────────────────────────────────

    public function send(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $agentId    = $request->auth->id;
        $conn       = "mysql_{$clientId}";
        $to         = $request->input('to');
        $from       = $request->input('from');
        $body       = $request->input('body', '');
        $campaignId = $request->input('campaign_id');

        if (!$to || !$from || !$body) {
            return $this->failResponse('to, from, and body are required.', [], null, 422);
        }

        try {
            $service = TwilioService::forClient($clientId);
            $data    = $service->sendSms($to, $from, $body);

            TwilioSms::on($conn)->create([
                'sms_sid'     => $data['sms_sid'],
                'from_number' => $from,
                'to_number'   => $to,
                'body'        => $body,
                'direction'   => 'outbound',
                'status'      => $data['status'],
                'campaign_id' => $campaignId,
                'agent_id'    => $agentId,
                'price'       => $data['price']  ?? null,
                'price_unit'  => 'USD',
                'sent_at'     => \Carbon\Carbon::now(),
            ]);

            return $this->successResponse('SMS sent.', ['sms' => $data]);

        } catch (\Exception $e) {
            Log::error('Twilio send SMS', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to send SMS.', [$e->getMessage()], $e, 500);
        }
    }

    // ── Bulk SMS (dispatches queue job) ────────────────────────────────────

    public function bulkSend(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $agentId    = $request->auth->id;
        $recipients = (array) $request->input('to', []);
        $from       = $request->input('from');
        $body       = $request->input('body', '');
        $campaignId = $request->input('campaign_id');

        if (empty($recipients) || !$from || !$body) {
            return $this->failResponse('to (array), from, and body are required.', [], null, 422);
        }

        if (count($recipients) > 1000) {
            return $this->failResponse('Maximum 1000 recipients per bulk request.', [], null, 422);
        }

        dispatch(new TwilioBulkSmsJob($clientId, $agentId, $recipients, $from, $body, $campaignId));

        return $this->successResponse(
            count($recipients) . ' SMS messages queued for sending.',
            ['queued' => count($recipients)]
        );
    }

    // ── SMS log ────────────────────────────────────────────────────────────

    public function list(Request $request)
    {
        $clientId  = $request->auth->parent_id ?: $request->auth->id;
        $conn      = "mysql_{$clientId}";

        $perPage    = (int) $request->input('limit', 25);
        $page       = max(1, (int) $request->input('page', 1));
        $direction  = $request->input('direction');
        $campaignId = $request->input('campaign_id');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');
        $search     = $request->input('search', '');

        $query = TwilioSms::on($conn)->orderByDesc('id');

        if ($direction)  $query->where('direction',   $direction);
        if ($campaignId) $query->where('campaign_id', $campaignId);
        if ($dateFrom)   $query->whereDate('sent_at', '>=', $dateFrom);
        if ($dateTo)     $query->whereDate('sent_at', '<=', $dateTo);
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('to_number',   'like', "%{$search}%")
                  ->orWhere('from_number', 'like', "%{$search}%")
                  ->orWhere('body',        'like', "%{$search}%");
            });
        }

        $total = $query->count();
        $sms   = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->successResponse('OK', [
            'sms'          => $sms,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
        ]);
    }
}
