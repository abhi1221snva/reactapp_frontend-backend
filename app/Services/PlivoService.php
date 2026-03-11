<?php

namespace App\Services;

use App\Model\PlivoAccount;
use App\Model\Client\PlivoNumber;
use Plivo\RestClient as PlivoClient;
use Plivo\XML\Response as PlivoResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlivoService
{
    private PlivoClient $client;
    private string $authId;
    private string $authToken;

    public function __construct(string $authId, string $authToken)
    {
        $this->authId    = $authId;
        $this->authToken = $authToken;
        $this->client    = new PlivoClient($authId, $authToken);
    }

    // ── Factory ────────────────────────────────────────────────────────────

    /**
     * Build a PlivoService for a given client tenant.
     */
    public static function forClient(int $clientId): self
    {
        $account = PlivoAccount::where('client_id', $clientId)->first();

        if ($account) {
            [$authId, $token] = $account->resolveCredentials();
        } else {
            $authId = env('PLIVO_AUTH_ID');
            $token  = env('PLIVO_AUTH_TOKEN');
        }

        if (!$authId || !$token) {
            throw new \RuntimeException('Plivo credentials not configured for this client.');
        }

        return new self($authId, $token);
    }

    // ── Account ────────────────────────────────────────────────────────────

    /**
     * Verify credentials are valid by fetching the account resource.
     */
    public function verifyCredentials(): array
    {
        $response = $this->client->accounts->get($this->authId);
        return [
            'auth_id'      => $response->authId,
            'name'         => $response->name,
            'account_type' => $response->accountType,
            'auto_recharge' => $response->autoRecharge ?? false,
            'cash_credits' => $response->cashCredits ?? '0.00',
        ];
    }

    /**
     * Create a Plivo subaccount.
     */
    public function createSubaccount(string $name, bool $enabled = true): array
    {
        $response = $this->client->subAccounts->create($name, $enabled);
        return [
            'auth_id'    => $response->authId,
            'auth_token' => $response->authToken,
            'name'       => $name,
            'enabled'    => $enabled,
        ];
    }

    /**
     * Suspend (disable) a subaccount.
     */
    public function suspendSubaccount(string $subAuthId): bool
    {
        $this->client->subAccounts->update($subAuthId, null, false);
        return true;
    }

    /**
     * Delete a subaccount.
     */
    public function deleteSubaccount(string $subAuthId): bool
    {
        $this->client->subAccounts->delete($subAuthId);
        return true;
    }

    // ── Phone Numbers ──────────────────────────────────────────────────────

    /**
     * Search available phone numbers.
     *
     * @param string      $country      ISO-2 country code (e.g. 'US')
     * @param string|null $areaCode     3-digit area code
     * @param array       $capabilities ['voice'=>true,'sms'=>true]
     * @param int         $limit
     */
    public function searchNumbers(
        string $country = 'US',
        ?string $areaCode = null,
        array $capabilities = [],
        int $limit = 20
    ): array {
        $options = [
            'limit'        => $limit,
            'country_iso2' => $country,
        ];

        if ($areaCode) {
            $options['pattern'] = $areaCode;
        }

        $services = [];
        if (!empty($capabilities['voice'])) $services[] = 'voice';
        if (!empty($capabilities['sms']))   $services[] = 'sms';
        if (!empty($services)) {
            $options['services'] = implode(',', $services);
        }

        $response = $this->client->phoneNumbers->search($country, $options);

        $numbers = [];
        foreach ($response->objects as $n) {
            $numbers[] = [
                'number'       => $n->number,
                'prefix'       => $n->prefix ?? null,
                'country'      => $n->country,
                'region'       => $n->region ?? null,
                'type'         => $n->type ?? 'local',
                'sub_type'     => is_array($n->subType ?? null) ? $n->subType : null,
                'monthly_rental_rate' => $n->monthlyRentalRate ?? null,
                'setup_rate'   => $n->setupRate ?? null,
                'capabilities' => [
                    'voice' => stripos(json_encode($n->subType ?? []), 'voice') !== false,
                    'sms'   => stripos(json_encode($n->subType ?? []), 'sms')   !== false,
                ],
            ];
        }

        return $numbers;
    }

    /**
     * Purchase a phone number and configure its webhooks.
     */
    public function purchaseNumber(string $phoneNumber, ?string $appId = null): array
    {
        $appUrl = env('APP_URL', '');
        $params = [];

        if ($appId) {
            $params['app_id'] = $appId;
        }

        $response = $this->client->numbers->buy($phoneNumber, $appId ?? '');

        // Update number with webhook URLs if no app set
        if (!$appId) {
            $this->client->numbers->update($phoneNumber, [
                'answer_url'        => "{$appUrl}/api/plivo/inbound-call",
                'answer_method'     => 'POST',
                'sms_url'           => "{$appUrl}/api/plivo/inbound-sms",
                'sms_method'        => 'POST',
            ]);
        }

        return [
            'number'      => $phoneNumber,
            'status'      => 'active',
            'voice_url'   => "{$appUrl}/api/plivo/inbound-call",
            'sms_url'     => "{$appUrl}/api/plivo/inbound-sms",
            'app_id'      => $appId,
            'message'     => $response->message ?? 'Number added to your account',
        ];
    }

    /**
     * Release (unrent) a phone number from Plivo.
     */
    public function releaseNumber(string $phoneNumber): bool
    {
        $this->client->numbers->unrent($phoneNumber);
        return true;
    }

    /**
     * Update number webhooks.
     */
    public function updateNumber(string $phoneNumber, array $params): array
    {
        $response = $this->client->numbers->update($phoneNumber, $params);
        return ['message' => $response->message ?? 'Updated'];
    }

    /**
     * List owned phone numbers.
     */
    public function listNumbers(int $limit = 100): array
    {
        $response = $this->client->numbers->list(['limit' => $limit]);

        $numbers = [];
        foreach ($response->objects as $n) {
            $numbers[] = [
                'number'     => $n->number,
                'alias'      => $n->alias ?? null,
                'country_iso' => $n->country ?? 'US',
                'sub_type'   => is_array($n->subType ?? null) ? $n->subType : [],
            ];
        }

        return $numbers;
    }

    // ── Voice Calls ────────────────────────────────────────────────────────

    /**
     * Initiate an outbound call.
     */
    public function makeCall(string $to, string $from, array $opts = []): array
    {
        $appUrl = env('APP_URL');

        $response = $this->client->calls->create(
            $from,
            [$to],
            $opts['answer_url'] ?? "{$appUrl}/api/plivo/inbound-call",
            'POST',
            array_merge([
                'hangup_url'       => "{$appUrl}/api/plivo/call-status",
                'status_call_url'  => "{$appUrl}/api/plivo/call-status",
                'status_call_back_method' => 'POST',
                'record'           => false,
                'record_call_id'   => true,
            ], $opts)
        );

        $requestUuid = is_array($response->requestUuid)
            ? ($response->requestUuid[0] ?? '')
            : $response->requestUuid;

        return [
            'call_uuid'  => $requestUuid,
            'status'     => 'queued',
            'direction'  => 'outbound',
            'from'       => $from,
            'to'         => $to,
            'started_at' => \Carbon\Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * Fetch a single call by UUID.
     */
    public function getCall(string $callUuid): array
    {
        $call = $this->client->calls->get($callUuid);
        return [
            'call_uuid'    => $call->callUuid,
            'call_status'  => $call->callStatus,
            'duration'     => $call->duration ?? 0,
            'bill_duration'=> $call->billDuration ?? 0,
            'total_amount' => $call->totalAmount ?? null,
            'from_number'  => $call->fromNumber,
            'to_number'    => $call->toNumber,
        ];
    }

    /**
     * Hangup a live call.
     */
    public function hangupCall(string $callUuid): bool
    {
        $this->client->calls->hangup($callUuid);
        return true;
    }

    // ── SMS ────────────────────────────────────────────────────────────────

    /**
     * Send an SMS message.
     */
    public function sendSms(string $to, string $from, string $body, array $opts = []): array
    {
        $appUrl  = env('APP_URL');
        $options = array_merge([
            'url'    => "{$appUrl}/api/plivo/sms-status",
            'method' => 'POST',
        ], $opts);

        $response = $this->client->messages->create($from, [$to], $body, $options);

        $uuids = is_array($response->messageUuid) ? $response->messageUuid : [$response->messageUuid];

        return [
            'message_uuid' => $uuids[0] ?? uniqid('plivo_'),
            'message'      => $response->message ?? 'SMS queued',
            'status'       => 'queued',
            'from'         => $from,
            'to'           => $to,
            'sent_at'      => \Carbon\Carbon::now()->toDateTimeString(),
        ];
    }

    // ── Applications (SIP Trunks) ──────────────────────────────────────────

    /**
     * Create a Plivo Application (serves as SIP trunk routing layer).
     */
    public function createApplication(string $name, array $urls = []): array
    {
        $appUrl = env('APP_URL', '');

        $params = array_merge([
            'answer_url'    => "{$appUrl}/api/plivo/inbound-call",
            'answer_method' => 'POST',
            'hangup_url'    => "{$appUrl}/api/plivo/call-status",
            'hangup_method' => 'POST',
        ], $urls);

        $response = $this->client->applications->create($name, $params);

        return [
            'app_id'        => $response->appId,
            'app_name'      => $name,
            'answer_url'    => $params['answer_url'],
            'hangup_url'    => $params['hangup_url'],
            'status'        => 'active',
        ];
    }

    /**
     * Update a Plivo Application.
     */
    public function updateApplication(string $appId, array $params): array
    {
        $response = $this->client->applications->update($appId, $params);
        return ['message' => $response->message ?? 'Updated'];
    }

    /**
     * Delete a Plivo Application.
     */
    public function deleteApplication(string $appId): bool
    {
        $this->client->applications->delete($appId);
        return true;
    }

    /**
     * List all Plivo Applications.
     */
    public function listApplications(): array
    {
        $response = $this->client->applications->list();
        $apps = [];
        foreach ($response->objects as $a) {
            $apps[] = [
                'app_id'     => $a->appId,
                'app_name'   => $a->appName,
                'answer_url' => $a->answerUrl,
                'hangup_url' => $a->hangupUrl ?? null,
            ];
        }
        return $apps;
    }

    // ── Recordings ─────────────────────────────────────────────────────────

    /**
     * List recordings, optionally filtered by call UUID.
     */
    public function getRecordings(?string $callUuid = null): array
    {
        $options = ['limit' => 50];
        if ($callUuid) {
            $options['call_uuid'] = $callUuid;
        }

        $response = $this->client->recordings->list($options);

        $recs = [];
        foreach ($response->objects as $r) {
            $recs[] = [
                'recording_id'  => $r->recordingId,
                'call_uuid'     => $r->callUuid ?? null,
                'duration'      => $r->recordingDurationMs ? (int)($r->recordingDurationMs / 1000) : 0,
                'recording_url' => $r->recordingUrl ?? null,
                'add_time'      => $r->addTime ?? null,
            ];
        }

        return $recs;
    }

    /**
     * Delete a recording.
     */
    public function deleteRecording(string $recordingId): bool
    {
        $this->client->recordings->delete($recordingId);
        return true;
    }

    // ── Usage / Billing ────────────────────────────────────────────────────

    /**
     * Fetch CDR (call detail records) for usage monitoring.
     */
    public function getCallCdr(string $billStartDate, string $billEndDate, int $limit = 100): array
    {
        $response = $this->client->calls->list([
            'bill_start_date' => $billStartDate,
            'bill_end_date'   => $billEndDate,
            'limit'           => $limit,
        ]);

        $records = [];
        foreach ($response->objects as $call) {
            $records[] = [
                'call_uuid'    => $call->callUuid,
                'call_status'  => $call->callStatus,
                'duration'     => $call->duration ?? 0,
                'total_amount' => $call->totalAmount ?? '0',
                'bill_date'    => $call->billDate ?? null,
            ];
        }

        return $records;
    }

    /**
     * Fetch message records for usage monitoring.
     */
    public function getMessageRecords(string $dateFrom, string $dateTill, int $limit = 100): array
    {
        $response = $this->client->messages->list([
            'message_time__gte' => $dateFrom,
            'message_time__lte' => $dateTill,
            'limit'             => $limit,
        ]);

        $records = [];
        foreach ($response->objects as $msg) {
            $records[] = [
                'message_uuid' => $msg->messageUuid,
                'message_state' => $msg->messageState,
                'total_amount'  => $msg->totalAmount ?? '0',
                'total_rate'    => $msg->totalRate ?? '0',
                'units'         => $msg->units ?? 1,
                'message_time'  => $msg->messageTime ?? null,
            ];
        }

        return $records;
    }

    // ── XML Generators ─────────────────────────────────────────────────────

    /**
     * Generate Plivo XML for inbound call routing.
     */
    public function generateInboundXML(array $options): string
    {
        $response = new PlivoResponse();

        if (!empty($options['speak_message'])) {
            $params = ['voice' => 'WOMAN', 'language' => 'en-US'];
            $response->addSpeak($options['speak_message'], $params);
        }

        if (!empty($options['stream_ws_url'])) {
            // AI Media Stream — real-time audio for AI voice bots
            $stream = $response->addRecord([
                'action' => $options['stream_ws_url'],
                'method' => 'POST',
                'startOnDialAnswer' => 'true',
            ]);
        }

        if (!empty($options['dial_number'])) {
            $dial = $response->addDial();
            $dial->addNumber($options['dial_number']);
        } elseif (!empty($options['dial_sip'])) {
            $dial = $response->addDial();
            $dial->addUser('sip:' . $options['dial_sip']);
        } else {
            $response->addSpeak('Thank you for calling. Please hold while we connect you.', ['voice' => 'WOMAN']);
            $response->addWait(['length' => '2']);
        }

        return $response->toXML();
    }

    /**
     * Generate Plivo XML for outbound call answer.
     */
    public function generateOutboundXML(string $to): string
    {
        $response = new PlivoResponse();
        $dial     = $response->addDial();
        $dial->addNumber($to);
        return $response->toXML();
    }

    // ── Number Pool ────────────────────────────────────────────────────────

    /**
     * Return the next available Plivo number for a campaign (round-robin rotation).
     */
    public static function nextNumberForCampaign(int $campaignId, int $clientId): ?string
    {
        $conn = "mysql_{$clientId}";

        $row = DB::connection($conn)
            ->table('plivo_campaign_numbers as pcn')
            ->join('plivo_numbers as pn', 'pcn.plivo_number_id', '=', 'pn.id')
            ->where('pcn.campaign_id', $campaignId)
            ->where('pcn.is_active', 1)
            ->where('pn.status', 'active')
            ->orderByRaw('pcn.last_used_at IS NULL DESC')
            ->orderBy('pcn.last_used_at', 'asc')
            ->select('pcn.id as pcn_id', 'pn.number')
            ->lockForUpdate()
            ->first();

        if (!$row) {
            return null;
        }

        DB::connection($conn)
            ->table('plivo_campaign_numbers')
            ->where('id', $row->pcn_id)
            ->update(['last_used_at' => \Carbon\Carbon::now()]);

        return $row->number;
    }

    // ── Webhook Signature Validation ───────────────────────────────────────

    /**
     * Validate a Plivo webhook signature.
     * Plivo signs webhooks using HMAC-SHA1 of sorted params.
     *
     * @param string $signature  X-Plivo-Signature header value
     * @param array  $params     POST parameters from webhook
     * @param string $authToken  Account auth token
     */
    public static function validateSignature(string $signature, array $params, string $authToken): bool
    {
        // Sort params alphabetically and concatenate key+value
        ksort($params);
        $hashStr = '';
        foreach ($params as $key => $value) {
            $hashStr .= $key . $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $hashStr, $authToken, true));

        return hash_equals($expected, $signature);
    }
}
