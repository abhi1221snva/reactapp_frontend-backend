<?php

namespace App\Services\Rvm;

use App\Jobs\Rvm\DeliverWebhookJob;
use App\Model\Master\Rvm\Drop;
use App\Model\Master\Rvm\WebhookDelivery;
use App\Model\Master\Rvm\WebhookEndpoint;
use App\Services\Rvm\Support\Ulid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Outbound webhook dispatcher.
 *
 * Called from ProcessRvmDropJob on every drop state transition and
 * directly from controllers for synthetic pings. Writes a delivery row
 * per matching endpoint and dispatches DeliverWebhookJob on the
 * rvm.webhooks Redis queue.
 *
 * The delivery table is the source of truth for retry state — the job
 * itself is stateless and re-entrant.
 */
class RvmWebhookService
{
    /**
     * Emit an event fan-out to every matching endpoint of the tenant.
     */
    public function enqueue(Drop $drop, string $eventType, array $extra = []): void
    {
        $endpoints = WebhookEndpoint::on('master')
            ->where('client_id', $drop->client_id)
            ->where('active', true)
            ->get()
            ->filter(fn($ep) => $ep->subscribesTo($eventType));

        if ($endpoints->isEmpty()) {
            return;
        }

        $eventId = Ulid::generate();
        $payload = [
            'id'         => 'evt_' . $eventId,
            'type'       => $eventType,
            'created_at' => Carbon::now()->toIso8601ZuluString(),
            'data'       => array_merge(['drop' => $this->serializeDrop($drop)], $extra),
        ];

        foreach ($endpoints as $endpoint) {
            $this->queueDelivery($endpoint, $drop->client_id, $drop->id, $eventId, $eventType, $payload);
        }
    }

    /**
     * Send a synthetic ping event to a single endpoint. Used by the
     * "Test webhook" button in the portal + POST /v1/rvm/webhook-endpoints/{id}/test.
     */
    public function sendTestPing(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $eventId = Ulid::generate();
        $payload = [
            'id'         => 'evt_' . $eventId,
            'type'       => 'rvm.endpoint.test',
            'created_at' => Carbon::now()->toIso8601ZuluString(),
            'data'       => [
                'message' => 'This is a test delivery from RocketRVM. If you received this, your endpoint is wired correctly.',
                'endpoint_id' => (int) $endpoint->id,
            ],
        ];

        return $this->queueDelivery(
            $endpoint,
            (int) $endpoint->client_id,
            null,
            $eventId,
            'rvm.endpoint.test',
            $payload,
        );
    }

    /**
     * Manual re-queue of a previously-terminal or stuck delivery.
     */
    public function replay(WebhookDelivery $delivery): void
    {
        $delivery->status = 'pending';
        $delivery->next_retry_at = Carbon::now();
        $delivery->response_code = null;
        $delivery->response_body = null;
        $delivery->save();

        DeliverWebhookJob::dispatch((int) $delivery->id)
            ->onConnection('redis')
            ->onQueue('rvm.webhooks');
    }

    private function queueDelivery(
        WebhookEndpoint $endpoint,
        int $clientId,
        ?string $dropId,
        string $eventId,
        string $eventType,
        array $payload,
    ): WebhookDelivery {
        $delivery = WebhookDelivery::create([
            'endpoint_id'   => $endpoint->id,
            'client_id'     => $clientId,
            'drop_id'       => $dropId,
            'event_id'      => $eventId,
            'event_type'    => $eventType,
            'status'        => 'pending',
            'attempt'       => 0,
            'next_retry_at' => Carbon::now(),
            'payload'       => $payload,
        ]);

        try {
            DeliverWebhookJob::dispatch((int) $delivery->id)
                ->onConnection('redis')
                ->onQueue('rvm.webhooks');
        } catch (\Throwable $e) {
            // Queue driver failure is non-fatal — the delivery row remains
            // pending and a future sweeper / manual replay can retry it.
            Log::error('RvmWebhookService: failed to dispatch DeliverWebhookJob', [
                'delivery_id' => $delivery->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $delivery;
    }

    /**
     * Drop serialization for webhook payloads — keeps the wire format
     * stable and independent of the model's internal column set.
     */
    private function serializeDrop(Drop $drop): array
    {
        return [
            'id'                  => $drop->id,
            'status'              => $drop->status,
            'priority'            => $drop->priority,
            'phone'               => $drop->phone_e164,
            'caller_id'           => $drop->caller_id,
            'voice_template_id'   => (int) $drop->voice_template_id,
            'campaign_id'         => $drop->campaign_id,
            'provider'            => $drop->provider,
            'provider_message_id' => $drop->provider_message_id,
            'cost_cents'          => (int) $drop->cost_cents,
            'tries'               => (int) $drop->tries,
            'last_error'          => $drop->last_error,
            'metadata'            => $drop->metadata,
            'dispatched_at'       => $drop->dispatched_at?->toIso8601ZuluString(),
            'delivered_at'        => $drop->delivered_at?->toIso8601ZuluString(),
            'failed_at'           => $drop->failed_at?->toIso8601ZuluString(),
            'created_at'          => $drop->created_at?->toIso8601ZuluString(),
        ];
    }
}
