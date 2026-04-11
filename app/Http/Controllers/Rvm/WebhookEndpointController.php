<?php

namespace App\Http\Controllers\Rvm;

use App\Http\Controllers\Controller;
use App\Model\Master\Rvm\WebhookDelivery;
use App\Model\Master\Rvm\WebhookEndpoint;
use App\Services\Rvm\RvmWebhookService;
use App\Services\Rvm\Support\WebhookSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * /v1/rvm/webhook-endpoints        POST (create) / GET (list)
 * /v1/rvm/webhook-endpoints/{id}   GET / PATCH / DELETE
 * /v1/rvm/webhook-endpoints/{id}/test   POST — synthetic ping
 * /v1/rvm/webhook-endpoints/{id}/deliveries   GET — delivery log
 * /v1/rvm/webhook-deliveries/{id}/replay      POST — replay a specific delivery
 *
 * Same controller serves both the JWT portal route group and the
 * X-Api-Key external group. Authn sits in middleware; everything after
 * $this->tenantId() is identical.
 *
 * Secret lifecycle:
 *   - Generated once on POST (shown ONCE in response).
 *   - Stored plaintext in rvm_webhook_endpoints.secret (model $hidden).
 *   - NEVER returned by GET/PATCH after creation.
 *   - PATCH with rotate=true generates a new secret and returns it.
 */
class WebhookEndpointController extends Controller
{
    public function __construct(private RvmWebhookService $webhooks) {}

    public function index(Request $request): JsonResponse
    {
        $endpoints = WebhookEndpoint::on('master')
            ->where('client_id', $this->tenantId($request))
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $endpoints->map(fn ($e) => $this->formatEndpoint($e))->all(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $endpoint = $this->findOrFail($request, $id);
        return response()->json($this->formatEndpoint($endpoint));
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'url'    => 'required|url|max:512',
            'events' => 'sometimes|array',
            'events.*' => 'string|max:64',
            'active' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        // Only HTTPS in production — leak protection.
        $url = $request->input('url');
        if (app()->environment('production') && !str_starts_with($url, 'https://')) {
            return $this->validationError(['url' => ['Production webhooks must use HTTPS.']]);
        }

        $secret = WebhookSigner::generateSecret();

        $endpoint = new WebhookEndpoint();
        $endpoint->client_id = $this->tenantId($request);
        $endpoint->url = $url;
        $endpoint->secret = $secret;
        $endpoint->events = $request->input('events', ['*']);
        $endpoint->active = (bool) $request->input('active', true);
        $endpoint->failure_count = 0;
        $endpoint->save();

        $body = $this->formatEndpoint($endpoint);
        // One-time secret disclosure — only here, never again.
        $body['secret'] = $secret;
        $body['secret_warning'] = 'Store this secret securely. It will not be shown again.';

        return response()->json($body, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $endpoint = $this->findOrFail($request, $id);

        $validator = Validator::make($request->all(), [
            'url'    => 'sometimes|url|max:512',
            'events' => 'sometimes|array',
            'events.*' => 'string|max:64',
            'active' => 'sometimes|boolean',
            'rotate_secret' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        if ($request->filled('url')) {
            $url = $request->input('url');
            if (app()->environment('production') && !str_starts_with($url, 'https://')) {
                return $this->validationError(['url' => ['Production webhooks must use HTTPS.']]);
            }
            $endpoint->url = $url;
        }
        if ($request->has('events')) {
            $endpoint->events = $request->input('events');
        }
        if ($request->has('active')) {
            $endpoint->active = (bool) $request->input('active');
            if ($endpoint->active) {
                // Manual re-enable clears auto-disable flags.
                $endpoint->disabled_at = null;
                $endpoint->disabled_reason = null;
                $endpoint->failure_count = 0;
            }
        }

        $newSecret = null;
        if ($request->boolean('rotate_secret')) {
            $newSecret = WebhookSigner::generateSecret();
            $endpoint->secret = $newSecret;
        }

        $endpoint->save();

        $body = $this->formatEndpoint($endpoint);
        if ($newSecret) {
            $body['secret'] = $newSecret;
            $body['secret_warning'] = 'Store this secret securely. It will not be shown again.';
        }

        return response()->json($body);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $endpoint = $this->findOrFail($request, $id);
        $endpoint->delete();
        return response()->json(['deleted' => true]);
    }

    /**
     * Send a synthetic ping to verify the endpoint is wired correctly.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $endpoint = $this->findOrFail($request, $id);

        try {
            $delivery = $this->webhooks->sendTestPing($endpoint);
        } catch (\Throwable $e) {
            Log::error('RVM webhook test ping failed', [
                'endpoint_id' => $id,
                'error'       => $e->getMessage(),
            ]);
            return response()->json([
                'error' => [
                    'type'    => 'rvm.webhook_test_failed',
                    'message' => 'Failed to enqueue test ping',
                    'status'  => 500,
                ],
            ], 500);
        }

        return response()->json([
            'queued'      => true,
            'delivery_id' => (int) $delivery->id,
            'event_type'  => 'rvm.endpoint.test',
            'message'     => 'Test ping queued. Inspect /v1/rvm/webhook-endpoints/{id}/deliveries for result.',
        ], 202);
    }

    /**
     * Delivery log for a specific endpoint, newest first, paginated.
     */
    public function deliveries(Request $request, int $id): JsonResponse
    {
        $endpoint = $this->findOrFail($request, $id);

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $q = WebhookDelivery::on('master')
            ->where('endpoint_id', $endpoint->id)
            ->where('client_id', $this->tenantId($request));

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        $page = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn ($d) => $this->formatDelivery($d))->all(),
            'meta' => [
                'total'     => $page->total(),
                'per_page'  => $page->perPage(),
                'current'   => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Replay a specific delivery (e.g. after fixing the tenant endpoint).
     */
    public function replay(Request $request, int $deliveryId): JsonResponse
    {
        $delivery = WebhookDelivery::on('master')
            ->where('id', $deliveryId)
            ->where('client_id', $this->tenantId($request))
            ->first();

        if (!$delivery) {
            return response()->json([
                'error' => [
                    'type'    => 'rvm.delivery_not_found',
                    'message' => 'Webhook delivery not found for this tenant',
                    'status'  => 404,
                ],
            ], 404);
        }

        $this->webhooks->replay($delivery);

        return response()->json([
            'replayed'    => true,
            'delivery_id' => (int) $delivery->id,
        ], 202);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function findOrFail(Request $request, int $id): WebhookEndpoint
    {
        $endpoint = WebhookEndpoint::on('master')
            ->where('id', $id)
            ->where('client_id', $this->tenantId($request))
            ->first();

        if (!$endpoint) {
            abort(response()->json([
                'error' => [
                    'type'    => 'rvm.endpoint_not_found',
                    'message' => 'Webhook endpoint not found for this tenant',
                    'status'  => 404,
                ],
            ], 404));
        }

        return $endpoint;
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->auth->parent_id ?? 0);
        if ($id <= 0) {
            abort(403, 'Missing tenant context');
        }
        return $id;
    }

    private function formatEndpoint(WebhookEndpoint $e): array
    {
        return [
            'id'              => (int) $e->id,
            'url'             => $e->url,
            'events'          => $e->events ?? ['*'],
            'active'          => (bool) $e->active,
            'failure_count'   => (int) $e->failure_count,
            'disabled_at'     => $e->disabled_at?->toIso8601ZuluString(),
            'disabled_reason' => $e->disabled_reason,
            'created_at'      => $e->created_at?->toIso8601ZuluString(),
            'updated_at'      => $e->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function formatDelivery(WebhookDelivery $d): array
    {
        return [
            'id'            => (int) $d->id,
            'endpoint_id'   => (int) $d->endpoint_id,
            'drop_id'       => $d->drop_id,
            'event_id'      => $d->event_id,
            'event_type'    => $d->event_type,
            'status'        => $d->status,
            'attempt'       => (int) $d->attempt,
            'response_code' => $d->response_code !== null ? (int) $d->response_code : null,
            'response_body' => $d->response_body,
            'next_retry_at' => $d->next_retry_at?->toIso8601ZuluString(),
            'delivered_at'  => $d->delivered_at?->toIso8601ZuluString(),
            'created_at'    => $d->created_at?->toIso8601ZuluString(),
        ];
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'error' => [
                'type'    => 'rvm.validation_failed',
                'message' => 'Request validation failed',
                'status'  => 422,
                'details' => $errors,
            ],
        ], 422);
    }
}
