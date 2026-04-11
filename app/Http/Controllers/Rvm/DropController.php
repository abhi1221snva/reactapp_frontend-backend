<?php

namespace App\Http\Controllers\Rvm;

use App\Http\Controllers\Controller;
use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\DTO\DropRequest;
use App\Services\Rvm\DTO\Priority;
use App\Services\Rvm\Exceptions\RvmException;
use App\Services\Rvm\RvmDropService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * POST   /v1/rvm/drops
 * GET    /v1/rvm/drops/{id}
 * GET    /v1/rvm/drops
 * POST   /v1/rvm/drops/{id}/cancel
 *
 * This is the ONE controller both the React portal (JWT) and external
 * API (X-Api-Key) hit. They differ only in the middleware that resolved
 * $request->auth. Everything after that is identical.
 */
class DropController extends Controller
{
    public function __construct(private RvmDropService $service) {}

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone'             => 'required|string|max:32',
            'caller_id'         => 'required|string|max:32',
            'voice_template_id' => 'required|integer',
            'priority'          => 'sometimes|in:instant,normal,bulk',
            'provider'          => 'sometimes|nullable|string|max:32',
            'campaign_id'       => 'sometimes|nullable|string|size:26',
            'scheduled_at'      => 'sometimes|nullable|date',
            'respect_quiet_hours' => 'sometimes|boolean',
            'callback_url'      => 'sometimes|nullable|url|max:512',
            'metadata'          => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            $drop = $this->service->createDrop(
                clientId: $this->tenantId($request),
                req: DropRequest::fromRequest($request),
                idempotencyKey: $request->header('Idempotency-Key'),
                userId: $request->auth->id ?? null,
                apiKeyId: $request->auth->api_key_id ?? null,
            );

            return response()->json($this->formatDrop($drop), 202);
        } catch (RvmException $e) {
            return $this->rvmError($e, $request);
        } catch (\Throwable $e) {
            Log::error('RVM drop create failed', [
                'error' => $e->getMessage(),
                'client_id' => $this->tenantId($request),
            ]);
            return response()->json([
                'error' => [
                    'type'    => 'rvm.internal_error',
                    'message' => 'Internal error creating drop',
                    'status'  => 500,
                ],
            ], 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $drop = $this->service->getDrop($this->tenantId($request), $id);
        if (!$drop) {
            return $this->notFound();
        }
        return response()->json($this->formatDrop($drop));
    }

    public function index(Request $request): JsonResponse
    {
        $q = Drop::on('master')->where('client_id', $this->tenantId($request));

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('campaign_id')) {
            $q->where('campaign_id', $request->query('campaign_id'));
        }
        if ($request->filled('phone')) {
            $q->where('phone_e164', $request->query('phone'));
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->query('to'));
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $drops = $q->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => collect($drops->items())->map(fn ($d) => $this->formatDrop($d))->all(),
            'meta' => [
                'total'     => $drops->total(),
                'per_page'  => $drops->perPage(),
                'current'   => $drops->currentPage(),
                'last_page' => $drops->lastPage(),
            ],
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        try {
            $drop = $this->service->cancelDrop($this->tenantId($request), $id);
            if (!$drop) {
                return $this->notFound();
            }
            return response()->json($this->formatDrop($drop));
        } catch (RvmException $e) {
            return $this->rvmError($e, $request);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->auth->parent_id ?? 0);
        if ($id <= 0) {
            abort(403, 'Missing tenant context');
        }
        return $id;
    }

    private function formatDrop(Drop $drop): array
    {
        return [
            'id'                 => $drop->id,
            'status'             => $drop->status,
            'priority'           => $drop->priority,
            'phone'              => $drop->phone_e164,
            'caller_id'          => $drop->caller_id,
            'voice_template_id'  => (int) $drop->voice_template_id,
            'campaign_id'        => $drop->campaign_id,
            'provider'           => $drop->provider,
            'provider_message_id'=> $drop->provider_message_id,
            'cost_cents'         => (int) $drop->cost_cents,
            'tries'              => (int) $drop->tries,
            'last_error'         => $drop->last_error,
            'metadata'           => $drop->metadata,
            'scheduled_at'       => $drop->scheduled_at?->toIso8601ZuluString(),
            'deferred_until'     => $drop->deferred_until?->toIso8601ZuluString(),
            'dispatched_at'      => $drop->dispatched_at?->toIso8601ZuluString(),
            'delivered_at'       => $drop->delivered_at?->toIso8601ZuluString(),
            'failed_at'          => $drop->failed_at?->toIso8601ZuluString(),
            'created_at'         => $drop->created_at?->toIso8601ZuluString(),
            '_links'             => [
                'self' => "/v1/rvm/drops/{$drop->id}",
            ],
        ];
    }

    private function rvmError(RvmException $e, Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'type'       => $e->errorCode(),
                'message'    => $e->getMessage(),
                'status'     => $e->httpStatus(),
                'request_id' => $request->header('X-Request-Id'),
                'details'    => $e->details(),
            ],
        ], $e->httpStatus());
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

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'type'    => 'rvm.drop_not_found',
                'message' => 'Drop not found for this tenant',
                'status'  => 404,
            ],
        ], 404);
    }
}
