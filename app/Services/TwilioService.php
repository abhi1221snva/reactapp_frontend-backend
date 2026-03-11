<?php

namespace App\Services;

use App\Model\TwilioAccount;
use App\Model\Client\CampaignNumber;
use App\Model\Client\TwilioNumber;
use Twilio\Rest\Client as TwilioClient;
use Twilio\TwiML\VoiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TwilioService
{
    private TwilioClient $client;
    private string $accountSid;

    public function __construct(string $sid, string $authToken)
    {
        $this->accountSid = $sid;
        $this->client     = new TwilioClient($sid, $authToken);
    }

    // ── Factory ────────────────────────────────────────────────────────────

    /**
     * Build a TwilioService for a given client tenant.
     * Uses their own credentials if connected, otherwise uses platform
     * master account (or the client's subaccount token).
     */
    public static function forClient(int $clientId): self
    {
        $account = TwilioAccount::where('client_id', $clientId)->first();

        if ($account) {
            [$sid, $token] = $account->resolveCredentials();
        } else {
            $sid   = env('TWILIO_SID');
            $token = env('TWILIO_AUTH_TOKEN');
        }

        if (!$sid || !$token) {
            throw new \RuntimeException('Twilio credentials not configured for this client.');
        }

        return new self($sid, $token);
    }

    // ── Account ────────────────────────────────────────────────────────────

    /**
     * Verify credentials are valid by fetching the account resource.
     */
    public function verifyCredentials(): array
    {
        $account = $this->client->api->v2010->accounts($this->accountSid)->fetch();
        return [
            'sid'           => $account->sid,
            'friendly_name' => $account->friendlyName,
            'status'        => $account->status,
            'type'          => $account->type,
        ];
    }

    /**
     * Create a Twilio subaccount.
     */
    public function createSubaccount(string $friendlyName): array
    {
        $sub = $this->client->api->v2010->accounts->create([
            'friendlyName' => $friendlyName,
        ]);
        return [
            'sid'           => $sub->sid,
            'auth_token'    => $sub->authToken,
            'friendly_name' => $sub->friendlyName,
            'status'        => $sub->status,
        ];
    }

    /**
     * Suspend a subaccount.
     */
    public function suspendSubaccount(string $sid): bool
    {
        $this->client->api->v2010->accounts($sid)->update(['status' => 'suspended']);
        return true;
    }

    // ── Phone Numbers ──────────────────────────────────────────────────────

    /**
     * Search available phone numbers.
     *
     * @param string      $country      ISO-2 country code (e.g. 'US')
     * @param string|null $areaCode     3-digit area code
     * @param array       $capabilities ['voice'=>true,'sms'=>true,'mms'=>false]
     * @param int         $limit
     */
    public function searchNumbers(
        string $country = 'US',
        ?string $areaCode = null,
        array $capabilities = [],
        int $limit = 20
    ): array {
        $options = [];
        if ($areaCode) {
            $options['areaCode'] = $areaCode;
        }
        if (!empty($capabilities['voice'])) {
            $options['voiceEnabled'] = true;
        }
        if (!empty($capabilities['sms'])) {
            $options['smsEnabled'] = true;
        }
        if (!empty($capabilities['mms'])) {
            $options['mmsEnabled'] = true;
        }

        // $limit is passed as the 2nd arg — putting it in $options sends it as
        // a query string param that Twilio ignores; the SDK uses the 2nd arg.
        $numbers = $this->client->availablePhoneNumbers($country)
            ->local
            ->read($options, $limit);

        return array_map(function ($n) {
            $caps = is_array($n->capabilities) ? $n->capabilities : (array) $n->capabilities;
            return [
                'phone_number'  => $n->phoneNumber,
                'friendly_name' => $n->friendlyName,
                'region'        => $n->region,
                'postal_code'   => $n->postalCode ?? null,
                'iso_country'   => $n->isoCountry,
                'capabilities'  => [
                    'voice' => (bool) ($caps['voice'] ?? false),
                    'sms'   => (bool) ($caps['SMS']   ?? $caps['sms'] ?? false),
                    'mms'   => (bool) ($caps['MMS']   ?? $caps['mms'] ?? false),
                ],
            ];
        }, $numbers);
    }

    /**
     * Purchase a phone number and configure its webhooks.
     */
    public function purchaseNumber(string $phoneNumber, array $urls = []): array
    {
        $appUrl = env('APP_URL', '');

        $params = [
            'phoneNumber' => $phoneNumber,
            'voiceUrl'    => $urls['voice_url'] ?? "{$appUrl}/twilio/webhook/inbound-call",
            'smsUrl'      => $urls['sms_url']   ?? "{$appUrl}/twilio/webhook/inbound-sms",
            'statusCallback' => $urls['status_callback'] ?? "{$appUrl}/twilio/webhook/call-status",
        ];

        $number = $this->client->incomingPhoneNumbers->create($params);

        $caps = is_array($number->capabilities) ? $number->capabilities : (array) $number->capabilities;
        return [
            'sid'           => $number->sid,
            'phone_number'  => $number->phoneNumber,
            'friendly_name' => $number->friendlyName,
            'status'        => 'active',
            'voice_url'     => $number->voiceUrl,
            'sms_url'       => $number->smsUrl,
            'capabilities'  => [
                'voice' => (bool) ($caps['voice'] ?? false),
                'sms'   => (bool) ($caps['SMS']   ?? $caps['sms'] ?? false),
                'mms'   => (bool) ($caps['MMS']   ?? $caps['mms'] ?? false),
            ],
        ];
    }

    /**
     * Release (delete) a phone number from Twilio.
     */
    public function releaseNumber(string $sid): bool
    {
        $this->client->incomingPhoneNumbers($sid)->delete();
        return true;
    }

    /**
     * List owned phone numbers.
     */
    public function listNumbers(int $limit = 100): array
    {
        $numbers = $this->client->incomingPhoneNumbers->read([], $limit);
        return array_map(function ($n) {
            $caps = is_array($n->capabilities) ? $n->capabilities : (array) $n->capabilities;
            return [
                'sid'          => $n->sid,
                'phone_number' => $n->phoneNumber,
                'capabilities' => [
                    'voice' => (bool) ($caps['voice'] ?? false),
                    'sms'   => (bool) ($caps['SMS']   ?? $caps['sms'] ?? false),
                    'mms'   => (bool) ($caps['MMS']   ?? $caps['mms'] ?? false),
                ],
            ];
        }, $numbers);
    }

    // ── Voice Calls ────────────────────────────────────────────────────────

    /**
     * Initiate an outbound call.
     */
    public function makeCall(string $to, string $from, string $twimlUrl, array $opts = []): array
    {
        $params = array_merge([
            'from'           => $from,
            'url'            => $twimlUrl,
            'statusCallback' => env('APP_URL') . '/twilio/webhook/call-status',
            'statusCallbackMethod' => 'POST',
            'statusCallbackEvent'  => ['initiated','ringing','answered','completed'],
            'record'         => true,
            'recordingStatusCallback' => env('APP_URL') . '/twilio/webhook/recording-status',
        ], $opts);

        $call = $this->client->calls->create($to, $from, $params);

        return [
            'call_sid'   => $call->sid,
            'status'     => $call->status,
            'direction'  => $call->direction,
            'from'       => $call->from,
            'to'         => $call->to,
            'started_at' => \Carbon\Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * Fetch a single call by SID.
     */
    public function getCall(string $sid): array
    {
        $call = $this->client->calls($sid)->fetch();
        return [
            'call_sid'  => $call->sid,
            'status'    => $call->status,
            'duration'  => $call->duration,
            'from'      => $call->from,
            'to'        => $call->to,
        ];
    }

    // ── SMS ────────────────────────────────────────────────────────────────

    /**
     * Send an SMS message.
     */
    public function sendSms(string $to, string $from, string $body): array
    {
        $message = $this->client->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ]);

        return [
            'sms_sid'   => $message->sid,
            'status'    => $message->status,
            'from'      => $message->from,
            'to'        => $message->to,
            'body'      => $message->body,
            'price'     => $message->price,
            'sent_at'   => \Carbon\Carbon::now()->toDateTimeString(),
        ];
    }

    // ── SIP Trunks ─────────────────────────────────────────────────────────

    /**
     * Create an Elastic SIP Trunk.
     */
    public function createSipTrunk(string $friendlyName): array
    {
        $trunk = $this->client->trunking->v1->trunks->create([
            'friendlyName' => $friendlyName,
        ]);

        return [
            'sid'           => $trunk->sid,
            'friendly_name' => $trunk->friendlyName,
            'domain_name'   => $trunk->domainName,
            'status'        => 'active',
        ];
    }

    /**
     * Delete a SIP trunk.
     */
    public function deleteSipTrunk(string $sid): bool
    {
        $this->client->trunking->v1->trunks($sid)->delete();
        return true;
    }

    /**
     * List calls from Twilio API (most recent first).
     *
     * @param int $limit Max calls to fetch (default 100)
     */
    public function listCalls(int $limit = 100): array
    {
        $calls = $this->client->calls->read([], $limit);

        return array_map(function ($c) {
            // Normalise direction: 'outbound-api', 'outbound-dial' → 'outbound'
            $direction = str_starts_with((string) $c->direction, 'outbound') ? 'outbound' : 'inbound';

            // Allowed status enum values
            $allowedStatuses = ['queued','ringing','in-progress','completed','busy','no-answer','canceled','failed'];
            $status = in_array($c->status, $allowedStatuses) ? $c->status : 'completed';

            return [
                'call_sid'    => $c->sid,
                'from_number' => $c->from,
                'to_number'   => $c->to,
                'direction'   => $direction,
                'status'      => $status,
                'duration'    => (int) ($c->duration ?? 0),
                'price'       => $c->price,
                'price_unit'  => $c->priceUnit,
                'started_at'  => $c->startTime instanceof \DateTime ? $c->startTime->format('Y-m-d H:i:s') : null,
                'ended_at'    => $c->endTime   instanceof \DateTime ? $c->endTime->format('Y-m-d H:i:s')   : null,
            ];
        }, $calls);
    }

    /**
     * List SIP trunks.
     */
    public function listSipTrunks(): array
    {
        $trunks = $this->client->trunking->v1->trunks->read();
        return array_map(fn($t) => [
            'sid'           => $t->sid,
            'friendly_name' => $t->friendlyName,
            'domain_name'   => $t->domainName,
        ], $trunks);
    }

    /**
     * Update origination URL on a trunk.
     */
    public function updateTrunkOriginationUrl(string $trunkSid, string $url, string $friendlyName = 'Primary'): array
    {
        // Delete existing origination URLs first
        $existing = $this->client->trunking->v1->trunks($trunkSid)
            ->originationUrls->read();
        foreach ($existing as $ou) {
            $this->client->trunking->v1->trunks($trunkSid)
                ->originationUrls($ou->sid)->delete();
        }

        $ou = $this->client->trunking->v1->trunks($trunkSid)
            ->originationUrls->create($url, 1, $friendlyName);

        return ['sid' => $ou->sid, 'sip_url' => $ou->sipUrl];
    }

    // ── Usage ──────────────────────────────────────────────────────────────

    /**
     * Fetch usage records for a date range.
     */
    public function getUsage(?string $startDate = null, ?string $endDate = null): array
    {
        $options = [];
        if ($startDate) {
            $options['startDate'] = new \DateTime($startDate);
        }
        if ($endDate) {
            $options['endDate'] = new \DateTime($endDate);
        }

        $records = $this->client->usage->records->read($options);

        return array_map(fn($r) => [
            'category'    => $r->category,
            'description' => $r->description,
            'count'       => $r->count,
            'count_unit'  => $r->countUnit,
            'usage'       => $r->usage,
            'usage_unit'  => $r->usageUnit,
            'price'       => $r->price,
            'price_unit'  => $r->priceUnit,
            'start_date'  => $r->startDate instanceof \DateTime ? $r->startDate->format('Y-m-d') : (string) ($r->startDate ?? ''),
            'end_date'    => $r->endDate instanceof \DateTime   ? $r->endDate->format('Y-m-d')   : (string) ($r->endDate   ?? ''),
        ], $records);
    }

    // ── Recordings ─────────────────────────────────────────────────────────

    /**
     * List recordings, optionally filtered by call SID.
     */
    public function getRecordings(?string $callSid = null): array
    {
        $options = $callSid ? ['callSid' => $callSid] : [];
        $recs = $this->client->recordings->read($options, 50);

        return array_map(fn($r) => [
            'recording_sid' => $r->sid,
            'call_sid'      => $r->callSid,
            'duration'      => $r->duration,
            'status'        => $r->status,
            'url'           => "https://api.twilio.com{$r->uri}",
        ], $recs);
    }

    // ── TwiML Generators ───────────────────────────────────────────────────

    /**
     * Generate TwiML for inbound call routing.
     * Options: dial_extension, play_message, gather_input, connect_queue, stream_ws_url
     */
    public function generateInboundCallTwiML(array $options): string
    {
        $response = new VoiceResponse();

        if (!empty($options['play_message'])) {
            $response->say($options['play_message'], ['voice' => 'Polly.Joanna']);
        }

        if (!empty($options['stream_ws_url'])) {
            // AI Media Stream — real-time audio for AI voice bots
            $start = $response->start();
            $start->stream(['url' => $options['stream_ws_url'], 'track' => 'both_tracks']);
        }

        if (!empty($options['dial_extension'])) {
            $dial = $response->dial();
            $dial->sip("sip:{$options['dial_extension']}@" . env('SIP_DOMAIN'));
        } elseif (!empty($options['connect_queue'])) {
            $dial = $response->dial();
            $dial->queue($options['connect_queue']);
        } else {
            $response->say('Thank you for calling. Please hold.', ['voice' => 'Polly.Joanna']);
            $response->pause(['length' => 2]);
        }

        return (string) $response;
    }

    /**
     * Generate TwiML for outbound call (simple dial).
     */
    public function generateOutboundTwiML(string $to): string
    {
        $response = new VoiceResponse();
        $dial     = $response->dial(['callerId' => $to]);
        $dial->number($to);
        return (string) $response;
    }

    // ── Number Pool ────────────────────────────────────────────────────────

    /**
     * Return the next available number for a campaign (round-robin rotation).
     */
    public static function nextNumberForCampaign(int $campaignId, int $clientId): ?string
    {
        $conn = "mysql_{$clientId}";

        $row = DB::connection($conn)
            ->table('campaign_numbers as cn')
            ->join('twilio_numbers as tn', 'cn.twilio_number_id', '=', 'tn.id')
            ->where('cn.campaign_id', $campaignId)
            ->where('cn.is_active', 1)
            ->where('tn.status', 'active')
            ->orderByRaw('cn.last_used_at IS NULL DESC')
            ->orderBy('cn.last_used_at', 'asc')
            ->select('cn.id as cn_id', 'tn.phone_number')
            ->lockForUpdate()
            ->first();

        if (!$row) {
            return null;
        }

        DB::connection($conn)
            ->table('campaign_numbers')
            ->where('id', $row->cn_id)
            ->update(['last_used_at' => \Carbon\Carbon::now()]);

        return $row->phone_number;
    }
}
