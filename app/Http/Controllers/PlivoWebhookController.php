<?php

namespace App\Http\Controllers;

use App\Model\Client\PlivoCall;
use App\Model\Client\PlivoSms;
use App\Model\Client\PlivoRecording;
use App\Model\Client\PlivoNumber;
use App\Services\PlivoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plivo\XML\Response as PlivoResponse;

class PlivoWebhookController extends Controller
{
    /**
     * Resolve the client_id from the destination phone number in the webhook payload.
     * Searches all client DBs for the number in plivo_numbers.
     */
    private function resolveClientId(string $toNumber): ?int
    {
        $clientsFile = config_path('database_clients.php');
        if (!file_exists($clientsFile)) {
            return null;
        }

        $connections = array_keys(require $clientsFile);

        foreach ($connections as $conn) {
            try {
                $row = DB::connection($conn)
                    ->table('plivo_numbers')
                    ->where('number', $toNumber)
                    ->where('status', 'active')
                    ->first();

                if ($row) {
                    return (int) str_replace('mysql_', '', $conn);
                }
            } catch (\Exception $e) {
                // Skip unreachable DB connections
            }
        }

        return null;
    }

    // -- Inbound call ------------------------------------------------------------

    public function inboundCall(Request $request)
    {
        $callUuid = $request->input('CallUUID');
        $from     = $request->input('From');
        $to       = $request->input('To');

        Log::info('Plivo inbound call', ['uuid' => $callUuid, 'from' => $from, 'to' => $to]);

        $clientId = $this->resolveClientId($to);

        if ($clientId) {
            $conn = "mysql_{$clientId}";
            PlivoCall::on($conn)->updateOrCreate(
                ['call_uuid' => $callUuid],
                [
                    'from_number' => $from,
                    'to_number'   => $to,
                    'direction'   => 'inbound',
                    'call_status' => 'ringing',
                    'started_at'  => \Carbon\Carbon::now(),
                ]
            );
        }

        // Build Plivo XML response — route call to agent queue or announce hold
        $response = new PlivoResponse();

        if ($clientId) {
            $response->addSpeak(
                'Thank you for calling. Please hold while we connect you.',
                ['voice' => 'WOMAN', 'language' => 'en-US']
            );
            $dial = $response->addDial(['timeout' => '30', 'record' => 'true']);
            $dial->addUser('sip:agent-queue@domain.sip.plivo.com');
        } else {
            $response->addSpeak(
                'We are unable to route your call at this time. Goodbye.',
                ['voice' => 'WOMAN', 'language' => 'en-US']
            );
            $response->addHangup();
        }

        return response($response->toXML(), 200)
            ->header('Content-Type', 'text/xml');
    }

    // -- Inbound SMS -------------------------------------------------------------

    public function inboundSms(Request $request)
    {
        $messageUuid = $request->input('MessageUUID');
        $from        = $request->input('From');
        $to          = $request->input('To');
        $body        = $request->input('Text', '');

        Log::info('Plivo inbound SMS', ['uuid' => $messageUuid, 'from' => $from, 'to' => $to]);

        $clientId = $this->resolveClientId($to);

        if ($clientId) {
            $conn = "mysql_{$clientId}";

            // Store in raw Plivo SMS log
            PlivoSms::on($conn)->updateOrCreate(
                ['message_uuid' => $messageUuid],
                [
                    'from_number' => $from,
                    'to_number'   => $to,
                    'body'        => $body,
                    'direction'   => 'inbound',
                    'status'      => 'received',
                    'sent_at'     => \Carbon\Carbon::now(),
                ]
            );

            // Feed into CRM SMS inbox (conversations + messages)
            try {
                $smsSvc = app(\App\Services\SmsInboxService::class);
                $smsSvc->receiveMessage($clientId, $from, $to, $body, $messageUuid);
            } catch (\Throwable $e) {
                Log::warning('Plivo inbound SMS: CRM inbox insert failed', [
                    'uuid' => $messageUuid, 'error' => $e->getMessage(),
                ]);
            }
        }

        // Return empty 200 — no auto-reply
        return response('', 200)->header('Content-Type', 'text/xml');
    }

    // -- Call status callback ----------------------------------------------------

    public function callStatus(Request $request)
    {
        $callUuid   = $request->input('CallUUID');
        $callStatus = $request->input('CallStatus');
        $duration   = (int) $request->input('Duration', 0);
        $from       = $request->input('From', '');
        $to         = $request->input('To', '');

        Log::info('Plivo call status', ['uuid' => $callUuid, 'status' => $callStatus, 'duration' => $duration]);

        $clientId = $this->resolveClientId($to);
        if (!$clientId && $from) {
            $clientId = $this->resolveClientId($from);
        }

        if ($clientId) {
            $conn    = "mysql_{$clientId}";
            $updates = ['call_status' => $callStatus];

            if ($callStatus === 'completed') {
                $updates['duration'] = $duration;
                $updates['ended_at'] = \Carbon\Carbon::now();
            }

            PlivoCall::on($conn)->where('call_uuid', $callUuid)->update($updates);
        }

        return response('', 204);
    }

    // -- SMS status callback -----------------------------------------------------

    public function smsStatus(Request $request)
    {
        $messageUuid  = $request->input('MessageUUID');
        $status       = $request->input('Status');
        $to           = $request->input('To', '');
        $from         = $request->input('From', '');

        Log::info('Plivo SMS status', ['uuid' => $messageUuid, 'status' => $status]);

        $clientId = $this->resolveClientId($to);
        if (!$clientId && $from) {
            $clientId = $this->resolveClientId($from);
        }

        if ($clientId) {
            $conn = "mysql_{$clientId}";
            PlivoSms::on($conn)->where('message_uuid', $messageUuid)->update([
                'status' => $status,
            ]);
        }

        return response('', 204);
    }
}