<?php

namespace App\Services\Rvm\Providers;

use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\DTO\CallbackResult;
use App\Services\Rvm\DTO\DeliveryResult;
use App\Services\Rvm\DTO\HealthStatus;
use App\Services\Rvm\Exceptions\ProviderPermanentError;
use App\Services\Rvm\Exceptions\ProviderTransientError;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Slybroadcast RVM driver.
 *
 * Slybroadcast's HTTP gateway is a single endpoint, vmb.php, that
 * accepts form-encoded POST requests and returns a very simple text
 * protocol:
 *
 *     OK
 *     session_id=1234567
 *
 * …on success, or:
 *
 *     ERROR
 *     MSG=bad credentials
 *
 * …on failure. The session_id is the campaign identifier and is what
 * we persist as provider_message_id.
 *
 * Credentials are platform-wide by default (SLYBROADCAST_UID /
 * SLYBROADCAST_PASSWORD env vars). Per-tenant overrides can be added
 * later via a slybroadcast_accounts table; for now the driver reads
 * overrides from drop.metadata.slybroadcast.uid / .password so admins
 * can pin a specific tenant's credentials by setting them on the drop
 * metadata (mirrors how AsteriskProvider reads asterisk_server_id).
 *
 * Audio delivery:
 *   Slybroadcast needs either a pre-uploaded file (c_audio) or a URL
 *   it can download from (c_url). We always pass c_url pointing at our
 *   own phase-5a.4 audio endpoint which resolves the drop's
 *   voice_template_id to a WAV and returns it. The URL carries an
 *   HMAC signature so third parties cannot fetch arbitrary audio.
 *
 * Status callback:
 *   c_dispo_url is Slybroadcast's "disposition" callback — they POST
 *   per-drop results to this URL when a campaign finishes. We set it
 *   to /rvm/slybroadcast/status/{drop_id}?sig=… and resolve the drop
 *   in handleCallback() via session_id OR the drop_id from the URL.
 */
class SlybroadcastProvider implements RvmProviderInterface
{
    private const DRIVER_NAME = 'slybroadcast';

    /** Default gateway endpoint — overridable via config('rvm.providers.slybroadcast.endpoint'). */
    private const DEFAULT_ENDPOINT = 'https://www.mobile-sphere.com/gateway/vmb.php';

    /** Hard HTTP timeout so we never wedge a worker. */
    private const HTTP_TIMEOUT_SECONDS = 10;

    public function name(): string
    {
        return self::DRIVER_NAME;
    }

    public function supports(Drop $drop): bool
    {
        // Slybroadcast covers US + CA by default. Other regions depend on
        // the tenant's Slybroadcast plan — enforcement happens on their
        // side (returns ERROR with MSG=region not supported). Here we
        // just sanity-check the phone shape.
        return (bool) preg_match('/^\+?\d{10,15}$/', (string) $drop->phone_e164);
    }

    public function estimateCost(Drop $drop): int
    {
        // Slybroadcast RVM is ~$0.09 / drop retail. For platform resellers
        // wholesale is closer to $0.02–$0.04. Conservative reserve: 5¢.
        $override = env('RVM_SLYBROADCAST_COST_CENTS');
        if ($override !== null && $override !== '') {
            return (int) $override;
        }
        return (int) config('rvm.default_cost_cents', 2) + 3;
    }

    public function deliver(Drop $drop): DeliveryResult
    {
        if (!$drop->client_id) {
            throw new ProviderPermanentError('SlybroadcastProvider: drop has no client_id');
        }
        if (!$drop->phone_e164) {
            throw new ProviderPermanentError('SlybroadcastProvider: drop has no phone_e164');
        }

        [$uid, $password] = $this->resolveCredentials($drop);
        if (!$uid || !$password) {
            throw new ProviderPermanentError(
                "SlybroadcastProvider: no credentials configured for client {$drop->client_id}"
            );
        }

        // Slybroadcast wants digits-only numbers for c_phone / c_callerID.
        $phone    = ltrim($drop->phone_e164, '+');
        $callerId = $drop->caller_id ? ltrim($drop->caller_id, '+') : '';

        $audioUrl  = $this->buildSignedUrl('rvm/slybroadcast/audio/'  . $drop->id);
        $dispoUrl  = $this->buildSignedUrl('rvm/slybroadcast/status/' . $drop->id);

        $form = [
            'c_uid'       => $uid,
            'c_password'  => $password,
            'c_phone'     => $phone,
            'c_url'       => $audioUrl,
            'c_date'      => 'now',
            'c_dispo_url' => $dispoUrl,
        ];
        if ($callerId !== '') {
            $form['c_callerID'] = $callerId;
        }

        $endpoint = (string) (config('rvm.providers.slybroadcast.endpoint') ?: self::DEFAULT_ENDPOINT);

        try {
            $http = new HttpClient([
                'timeout'         => self::HTTP_TIMEOUT_SECONDS,
                'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);

            $response = $http->post($endpoint, [
                'form_params' => $form,
                'headers'     => [
                    'Accept'     => 'text/plain',
                    'User-Agent' => 'RocketDialer-RVM/1.0',
                ],
                // Don't throw on 4xx/5xx — we want to inspect the body.
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ProviderTransientError('SlybroadcastProvider: connect failed: ' . $e->getMessage());
        } catch (RequestException $e) {
            throw new ProviderTransientError('SlybroadcastProvider: request failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new ProviderTransientError('SlybroadcastProvider: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body   = trim((string) $response->getBody());

        if ($status >= 500) {
            throw new ProviderTransientError(
                "SlybroadcastProvider: HTTP {$status}: " . substr($body, 0, 200)
            );
        }
        if ($status >= 400) {
            throw new ProviderPermanentError(
                "SlybroadcastProvider: HTTP {$status}: " . substr($body, 0, 200)
            );
        }

        $parsed = $this->parseResponseBody($body);

        if ($parsed['status'] === 'ERROR') {
            // Slybroadcast ERROR responses indicate caller-fault issues:
            // bad credentials, bad phone number, exhausted credits, etc.
            // Treat as permanent — no retry will help.
            throw new ProviderPermanentError(
                'SlybroadcastProvider: ' . ($parsed['message'] ?? 'unknown error') .
                ' (raw: ' . substr($body, 0, 200) . ')'
            );
        }

        if (empty($parsed['session_id'])) {
            throw new ProviderTransientError(
                'SlybroadcastProvider: OK response with no session_id (raw: ' . substr($body, 0, 200) . ')'
            );
        }

        return DeliveryResult::dispatching(
            externalId: (string) $parsed['session_id'],
            costCents: $this->estimateCost($drop),
            raw: [
                'session_id' => $parsed['session_id'],
                'response'   => $body,
            ],
        );
    }

    public function handleCallback(array $payload, array $headers): CallbackResult
    {
        // Slybroadcast disposition callbacks are form-encoded POSTs with
        // fields like:
        //   session_id        — the campaign id we stored as provider_message_id
        //   phone             — the dropped number
        //   status            — DELIVERED / FAILED / INVALID / etc.
        //   completion_time   — unix timestamp
        //
        // The phase-5a.4 route handler will have extracted drop_id from
        // the signed URL path, but we still match by session_id here so
        // this method is usable if the handler chooses to pass through
        // only the raw form body.
        $sessionId  = $payload['session_id']       ?? null;
        $statusRaw  = $payload['status']            ?? $payload['call_status'] ?? null;
        $phone      = $payload['phone']             ?? null;
        $reason     = $payload['failure_reason']    ?? $payload['reason'] ?? null;

        if (!$sessionId || !$statusRaw) {
            return CallbackResult::ignored();
        }

        $drop = Drop::on('master')
            ->where('provider', self::DRIVER_NAME)
            ->where('provider_message_id', (string) $sessionId)
            ->first();

        if (!$drop) {
            Log::warning('SlybroadcastProvider: callback for unknown session', [
                'session_id' => $sessionId,
                'status'     => $statusRaw,
                'phone'      => $phone,
            ]);
            return CallbackResult::ignored();
        }

        // Map Slybroadcast status strings → drop statuses.
        $normalized = strtoupper((string) $statusRaw);

        $newStatus = match (true) {
            in_array($normalized, ['DELIVERED', 'COMPLETED', 'SUCCESS', 'OK'], true) => 'delivered',
            in_array($normalized, ['FAILED', 'INVALID', 'NO_ANSWER', 'BUSY', 'REJECTED', 'ERROR'], true) => 'failed',
            default => null,
        };

        if ($newStatus === null) {
            // Intermediate state (QUEUED, PROCESSING) — ignore.
            return CallbackResult::ignored();
        }

        $errorCode = null;
        $errorMsg  = null;
        if ($newStatus === 'failed') {
            $errorCode = $normalized;
            $errorMsg  = 'Slybroadcast status=' . $statusRaw . ($reason ? ", reason={$reason}" : '');
        }

        return new CallbackResult(
            dropId:            (string) $drop->id,
            newStatus:         $newStatus,
            providerCostCents: null, // Slybroadcast does not expose per-drop cost in the dispo payload
            errorCode:         $errorCode,
            errorMessage:      $errorMsg,
            raw:               $payload,
        );
    }

    public function healthCheck(): HealthStatus
    {
        $uid      = env('SLYBROADCAST_UID');
        $password = env('SLYBROADCAST_PASSWORD');

        if (!$uid || !$password) {
            return HealthStatus::down('Slybroadcast platform credentials not configured');
        }

        $endpoint = (string) (config('rvm.providers.slybroadcast.endpoint') ?: self::DEFAULT_ENDPOINT);

        try {
            $start = microtime(true);
            $http  = new HttpClient([
                'timeout'         => 5,
                'connect_timeout' => 5,
            ]);

            // A lightweight account credit check — vmb.aflist.php lists
            // uploaded audio files. Passing c_method=check_account returns
            // account status. If the specific endpoint variant isn't
            // available, any HTTP 2xx/3xx reply to a GET on the gateway
            // host is enough to call the provider reachable — we're not
            // trying to validate credentials here, only to prove we can
            // reach the endpoint at all.
            $parsed = parse_url($endpoint);
            $base   = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.mobile-sphere.com');

            $response = $http->get($base . '/', ['http_errors' => false]);
            $status   = $response->getStatusCode();

            if ($status >= 500) {
                return HealthStatus::down("Slybroadcast gateway HTTP {$status}");
            }

            return HealthStatus::up((int) ((microtime(true) - $start) * 1000));
        } catch (\Throwable $e) {
            return HealthStatus::down('Slybroadcast gateway unreachable: ' . $e->getMessage());
        }
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /**
     * Resolve Slybroadcast credentials for a drop.
     *
     * Precedence:
     *   1. drop.metadata.slybroadcast.uid / .password  (per-drop override)
     *   2. SLYBROADCAST_UID / SLYBROADCAST_PASSWORD     (platform default)
     *
     * Per-tenant credentials are not yet supported in a dedicated table;
     * tenants that need their own account can be routed via metadata.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCredentials(Drop $drop): array
    {
        $meta = is_array($drop->metadata) ? ($drop->metadata['slybroadcast'] ?? null) : null;
        if (is_array($meta) && !empty($meta['uid']) && !empty($meta['password'])) {
            return [(string) $meta['uid'], (string) $meta['password']];
        }

        return [
            env('SLYBROADCAST_UID'),
            env('SLYBROADCAST_PASSWORD'),
        ];
    }

    /**
     * Parse Slybroadcast's OK/ERROR text protocol.
     *
     * Success:
     *   OK
     *   session_id=1234567
     *
     * Failure:
     *   ERROR
     *   MSG=reason
     *
     * Returns:
     *   ['status' => 'OK'|'ERROR', 'session_id' => ?string, 'message' => ?string]
     */
    private function parseResponseBody(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if (empty($lines)) {
            return ['status' => 'ERROR', 'message' => 'empty response'];
        }

        $first = strtoupper(trim($lines[0]));
        $out   = [
            'status'     => str_starts_with($first, 'OK') ? 'OK' : 'ERROR',
            'session_id' => null,
            'message'    => null,
        ];

        // Some Slybroadcast responses put session_id on the first line
        // ("OK|session_id=..."), others on subsequent lines. Handle both.
        foreach ($lines as $line) {
            if (preg_match('/session_id\s*=\s*([A-Za-z0-9_-]+)/i', $line, $m)) {
                $out['session_id'] = $m[1];
            }
            if (preg_match('/(?:MSG|ERROR|reason)\s*[=:]\s*(.+)/i', $line, $m)) {
                $out['message'] = trim($m[1]);
            }
        }

        return $out;
    }

    /**
     * Build an APP_URL-rooted endpoint with an HMAC signature. Third
     * parties cannot fetch the audio or mutate drop state by guessing
     * ids — the phase-5a.4 route handler rejects mismatched signatures.
     */
    private function buildSignedUrl(string $path): string
    {
        $base = rtrim((string) (env('RVM_CALLBACK_BASE_URL') ?: env('APP_URL')), '/');
        if ($base === '') {
            throw new ProviderPermanentError(
                'SlybroadcastProvider: RVM_CALLBACK_BASE_URL / APP_URL is not configured'
            );
        }

        $secret = (string) (env('RVM_CALLBACK_SECRET') ?: env('APP_KEY'));
        if ($secret === '') {
            throw new ProviderPermanentError(
                'SlybroadcastProvider: no signing secret (RVM_CALLBACK_SECRET / APP_KEY)'
            );
        }

        $sig = hash_hmac('sha256', $path, $secret);
        return "{$base}/{$path}?sig={$sig}";
    }
}
