<?php

namespace App\Jobs\Rvm;

use App\Jobs\Job;
use App\Model\Master\Rvm\WebhookDelivery;
use App\Model\Master\Rvm\WebhookEndpoint;
use App\Services\Rvm\Support\WebhookSigner;
use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DeliverWebhookJob — dispatches a single WebhookDelivery row to the
 * tenant endpoint over HTTPS with HMAC signing.
 *
 * Lifecycle:
 *   1. Load delivery + endpoint (skip if already terminal or endpoint inactive).
 *   2. Sign payload with endpoint.secret.
 *   3. POST with short timeout (config rvm.webhooks.timeout_seconds).
 *   4. 2xx → mark delivered, zero endpoint failure_count.
 *   5. Non-2xx / connect error → increment attempt. If attempt < max, set
 *      next_retry_at per backoff array + re-enqueue with delay. If attempt
 *      >= max, mark giving_up and bump endpoint.failure_count.
 *   6. If endpoint.failure_count >= auto_disable threshold, disable it.
 *
 * This job MUST be idempotent on the delivery row — a duplicate run
 * (e.g. after a worker crash) is safe as long as the row status check
 * runs first.
 *
 * Runs on Redis queue 'rvm.webhooks' — slow tenant endpoints cannot
 * back-pressure drop dispatch because that queue is independent.
 */
class DeliverWebhookJob extends Job
{
    /**
     * Laravel's own retry machinery is disabled because we manage retries
     * ourselves via the delivery row + next_retry_at. We rely on a single
     * attempt per job fire so we have full control of backoff + state.
     */
    public int $tries = 1;
    public int $timeout = 20;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookSigner $signer): void
    {
        $delivery = WebhookDelivery::on('master')->find($this->deliveryId);
        if (!$delivery) {
            Log::warning('DeliverWebhookJob: delivery not found', ['id' => $this->deliveryId]);
            return;
        }
        if (in_array($delivery->status, ['delivered', 'giving_up'], true)) {
            return; // already terminal
        }

        $endpoint = WebhookEndpoint::on('master')->find($delivery->endpoint_id);
        if (!$endpoint || !$endpoint->active) {
            $delivery->status = 'failed';
            $delivery->response_body = 'endpoint_inactive';
            $delivery->save();
            return;
        }

        // Lumen models mark hidden fields; fetch secret directly.
        $secret = WebhookEndpoint::on('master')
            ->where('id', $endpoint->id)
            ->value('secret');

        $rawBody = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $sig = $signer->sign($rawBody, $secret);

        $timeout = (int) config('rvm.webhooks.timeout_seconds', 5);
        $client = new GuzzleClient([
            'timeout'         => $timeout,
            'connect_timeout' => $timeout,
            'http_errors'     => false,
            'allow_redirects' => false,
            'verify'          => true,
        ]);

        $attemptNumber = ((int) $delivery->attempt) + 1;

        try {
            $resp = $client->post($endpoint->url, [
                'headers' => [
                    'Content-Type'              => 'application/json',
                    'User-Agent'                => 'RocketRVM-Webhook/2.0',
                    WebhookSigner::HEADER_NAME  => $sig['header'],
                    'X-Rvm-Event-Id'            => $delivery->event_id,
                    'X-Rvm-Event-Type'          => $delivery->event_type,
                    'X-Rvm-Delivery-Id'         => (string) $delivery->id,
                    'X-Rvm-Attempt'             => (string) $attemptNumber,
                ],
                'body' => $rawBody,
            ]);

            $code = $resp->getStatusCode();
            $bodySnippet = substr((string) $resp->getBody(), 0, 2000);

            if ($code >= 200 && $code < 300) {
                $this->markDelivered($delivery, $endpoint, $code, $bodySnippet, $attemptNumber);
                return;
            }

            $this->handleFailure($delivery, $endpoint, $attemptNumber, $code, $bodySnippet);
        } catch (ConnectException $e) {
            $this->handleFailure($delivery, $endpoint, $attemptNumber, 0, 'connect_error: ' . $e->getMessage());
        } catch (RequestException $e) {
            $this->handleFailure($delivery, $endpoint, $attemptNumber, 0, 'request_error: ' . $e->getMessage());
        } catch (Throwable $e) {
            // Unknown failure — treat as retryable
            Log::error('DeliverWebhookJob unexpected error', [
                'delivery_id' => $this->deliveryId,
                'error'       => $e->getMessage(),
            ]);
            $this->handleFailure($delivery, $endpoint, $attemptNumber, 0, 'internal: ' . $e->getMessage());
        }
    }

    private function markDelivered(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        int $code,
        string $body,
        int $attemptNumber,
    ): void {
        $delivery->status = 'delivered';
        $delivery->attempt = $attemptNumber;
        $delivery->response_code = $code;
        $delivery->response_body = $body;
        $delivery->delivered_at = Carbon::now();
        $delivery->next_retry_at = null;
        $delivery->save();

        // Reset endpoint health on any success
        if ($endpoint->failure_count > 0) {
            WebhookEndpoint::on('master')
                ->where('id', $endpoint->id)
                ->update(['failure_count' => 0]);
        }
    }

    private function handleFailure(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        int $attemptNumber,
        int $code,
        string $body,
    ): void {
        $maxAttempts = (int) config('rvm.webhooks.max_attempts', 6);
        $backoffs = config('rvm.webhooks.backoff_seconds', [30, 120, 600, 3600, 21600, 86400]);

        $delivery->attempt = $attemptNumber;
        $delivery->response_code = $code ?: null;
        $delivery->response_body = $body;

        if ($attemptNumber >= $maxAttempts) {
            // Terminal
            $delivery->status = 'giving_up';
            $delivery->next_retry_at = null;
            $delivery->save();

            $newFailureCount = (int) $endpoint->failure_count + 1;
            $disableAt = (int) config('rvm.webhooks.auto_disable_after_consecutive_failures', 10);

            $updates = ['failure_count' => $newFailureCount];
            if ($newFailureCount >= $disableAt) {
                $updates['active'] = false;
                $updates['disabled_at'] = Carbon::now();
                $updates['disabled_reason'] = "auto: {$newFailureCount} consecutive failed deliveries";
            }
            WebhookEndpoint::on('master')->where('id', $endpoint->id)->update($updates);
            return;
        }

        // Schedule next attempt
        $idx = min($attemptNumber - 1, count($backoffs) - 1);
        $delay = (int) $backoffs[$idx];

        $delivery->status = 'pending';
        $delivery->next_retry_at = Carbon::now()->addSeconds($delay);
        $delivery->save();

        self::dispatch($delivery->id)
            ->onConnection('redis')
            ->onQueue('rvm.webhooks')
            ->delay($delay);
    }
}
