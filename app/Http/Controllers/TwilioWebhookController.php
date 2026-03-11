<?php

namespace App\Http\Controllers;

use App\Model\Client\TwilioCall;
use App\Model\Client\TwilioSms;
use App\Model\Client\TwilioRecording;
use App\Model\Client\TwilioNumber;
use App\Services\TwilioService;
use App\Jobs\TwilioIngestCallJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Twilio\TwiML\VoiceResponse;
use Twilio\TwiML\MessagingResponse;

class TwilioWebhookController extends Controller
{
    /**
     * Resolve the client_id from the "To" phone number in the webhook payload.
     * Searches all client DBs for the number.
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
                $row = \DB::connection($conn)
                    ->table('twilio_numbers')
                    ->where('phone_number', $toNumber)
                    ->where('status', 'active')
                    ->first();

                if ($row) {
                    return (int) str_replace('mysql_', '', $conn);
                }
            } catch (\Exception $e) {
                // skip unreachable connection
            }
        }

        return null;
    }

    // -- Inbound call -------------------------------------------------------

    public function inboundCall(Request $request)
    {
        $callSid = $request->input('CallSid');
        $from    = $request->input('From');
        $to      = $request->input('To');

        Log::info('Twilio inbound call', ['sid' => $callSid, 'from' => $from, 'to' => $to]);

        $clientId = $this->resolveClientId($to);

        if ($clientId) {
            $conn = 'mysql_' . $clientId;
            TwilioCall::on($conn)->updateOrCreate(
                ['call_sid' => $callSid],
                [
                    'from_number' => $from,
                    'to_number'   => $to,
                    'direction'   => 'inbound',
                    'status'      => 'ringing',
                    'started_at'  => \Carbon\Carbon::now(),
                ]
            );
        }

        // Return TwiML -- route to agent queue or IVR
        $twiml = new VoiceResponse();

        if ($clientId) {
            $twiml->say('Thank you for calling. Please hold while we connect you.', [
                'voice' => 'Polly.Joanna',
            ]);
            $dial = $twiml->dial([
                'timeout'  => 30,
                'record'   => 'record-from-answer',
                'recordingStatusCallback' => env('APP_URL') . '/twilio/webhook/recording-status',
            ]);
            $dial->queue('agent-queue');
        } else {
            $twiml->say('We are unable to route your call at this time. Goodbye.', [
                'voice' => 'Polly.Joanna',
            ]);
            $twiml->hangup();
        }

        return response((string) $twiml, 200)
            ->header('Content-Type', 'text/xml');
    }

    // -- Inbound SMS --------------------------------------------------------

    public function inboundSms(Request $request)
    {
        $smsSid = $request->input('MessageSid');
        $from   = $request->input('From');
        $to     = $request->input('To');
        $body   = $request->input('Body', '');

        Log::info('Twilio inbound SMS', ['sid' => $smsSid, 'from' => $from, 'to' => $to]);

        $clientId = $this->resolveClientId($to);

        if ($clientId) {
            $conn = 'mysql_' . $clientId;
            TwilioSms::on($conn)->updateOrCreate(
                ['sms_sid' => $smsSid],
                [
                    'from_number' => $from,
                    'to_number'   => $to,
                    'body'        => $body,
                    'direction'   => 'inbound',
                    'status'      => 'received',
                    'sent_at'     => \Carbon\Carbon::now(),
                ]
            );
        }

        $response = new MessagingResponse();
        return response((string) $response, 200)
            ->header('Content-Type', 'text/xml');
    }

    // -- Call status callback -----------------------------------------------
    //
    // WEBHOOK-DRIVEN: Instead of doing a synchronous inline DB write, we
    // dispatch TwilioIngestCallJob onto the 'twilio' queue. This decouples
    // the HTTP response time from DB work and allows retries on failure.

    public function callStatus(Request $request)
    {
        $callSid    = $request->input('CallSid');
        $callStatus = $request->input('CallStatus');
        $from       = $request->input('From');
        $to         = $request->input('To');

        Log::info('Twilio call status webhook received', [
            'sid'    => $callSid,
            'status' => $callStatus,
        ]);

        // Resolve client from the dialed number (To = our number for inbound;
        // From = our number for outbound). Try To first, then From.
        $clientId = $this->resolveClientId($to);
        if (!$clientId) {
            $clientId = $this->resolveClientId($from);
        }

        if ($clientId) {
            // Dispatch async ingestion job — handles upsert with full
            // payload normalisation and retry logic.
            TwilioIngestCallJob::dispatch($clientId, $request->all())
                ->onQueue('twilio');
        } else {
            Log::warning('Twilio callStatus: could not resolve client_id', [
                'from' => $from,
                'to'   => $to,
                'sid'  => $callSid,
            ]);
        }

        // Twilio expects a 2xx response quickly; return 204 immediately.
        return response('', 204);
    }

    // -- Recording status callback ------------------------------------------

    public function recordingStatus(Request $request)
    {
        $recSid    = $request->input('RecordingSid');
        $callSid   = $request->input('CallSid');
        $recUrl    = $request->input('RecordingUrl');
        $duration  = (int) $request->input('RecordingDuration', 0);
        $recStatus = $request->input('RecordingStatus');
        $to        = $request->input('To', '');
        $from      = $request->input('From', '');

        Log::info('Twilio recording status', ['rec' => $recSid, 'call' => $callSid, 'status' => $recStatus]);

        $clientId = $this->resolveClientId($to) ?? $this->resolveClientId($from);

        if ($clientId && $recStatus === 'completed') {
            $conn = 'mysql_' . $clientId;

            TwilioRecording::on($conn)->updateOrCreate(
                ['recording_sid' => $recSid],
                [
                    'call_sid'    => $callSid,
                    'duration'    => $duration,
                    'url'         => $recUrl . '.mp3',
                    'status'      => 'completed',
                    'recorded_at' => \Carbon\Carbon::now(),
                ]
            );

            // Link back to the call record
            TwilioCall::on($conn)->where('call_sid', $callSid)->update([
                'recording_sid' => $recSid,
                'recording_url' => $recUrl . '.mp3',
            ]);
        }

        return response('', 204);
    }
}
