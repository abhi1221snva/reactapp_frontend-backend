<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\Drop;
use App\Model\Master\Rvm\Event;
use App\Services\Rvm\DTO\DropRequest;
use App\Services\Rvm\DTO\Priority;
use App\Services\Rvm\Exceptions\RvmException;
use App\Services\Rvm\Support\PhoneNormalizer;
use App\Services\Rvm\Support\Ulid;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * THE canonical entry point for every RVM drop — portal or API.
 *
 * createDrop() order of operations is intentionally fixed:
 *
 *   1. Idempotency lookup          (Redis, O(1))
 *   2. Phone normalization         (E.164)
 *   3. Rate limiting                (4 dimensions)
 *   4. Compliance gate              (DNC + quiet hours)
 *   5. Cost estimate
 *   6. Wallet reserve               (atomic row update)
 *   7. Persist rvm_drops row        (transaction)
 *   8. Append rvm_events "queued"   (same transaction)
 *   9. Idempotency remember
 *  10. Dispatch service enqueues    (Redis)
 *
 * If ANY step throws, earlier side-effects are rolled back (DB transaction)
 * or explicitly refunded (wallet). The caller gets a structured RvmException.
 */
class RvmDropService
{
    public function __construct(
        private RvmIdempotencyStore $idem,
        private RvmRateLimiter $rateLimiter,
        private RvmComplianceService $compliance,
        private RvmWalletService $wallet,
        private RvmDispatchService $dispatch,
        private RvmProviderRouter $providers,
    ) {}

    /**
     * Create (or replay) a single drop.
     */
    public function createDrop(
        int $clientId,
        DropRequest $req,
        ?string $idempotencyKey,
        ?int $userId = null,
        ?int $apiKeyId = null,
    ): Drop {
        // 1. Idempotency replay
        $fingerprint = RvmIdempotencyStore::fingerprint([
            'phone' => $req->phone,
            'caller_id' => $req->callerId,
            'voice_template_id' => $req->voiceTemplateId,
            'priority' => $req->priority,
            'metadata' => $req->metadata,
        ]);

        if ($replay = $this->idem->lookup($clientId, $idempotencyKey, $fingerprint)) {
            return $replay;
        }

        // 2. Normalize phone — throws InvalidPhoneException on garbage
        $phoneE164 = PhoneNormalizer::toE164($req->phone);

        // 3. Validate priority
        if (!Priority::isValid($req->priority)) {
            throw new \InvalidArgumentException("Invalid priority '{$req->priority}'");
        }

        // 4. Rate limit — throws RateLimitedException on violation
        $providerHint = $req->providerHint ?? 'auto';
        $this->rateLimiter->check(
            clientId: $clientId,
            apiKeyId: $apiKeyId,
            phoneE164: $phoneE164,
            provider: $providerHint,
        );

        // 5. Compliance — DNC + quiet hours (throws on block)
        $this->compliance->assertCompliant(
            clientId: $clientId,
            phoneE164: $phoneE164,
            respectDnc: true,
            respectQuietHours: $req->respectQuietHours,
        );

        // 6. Cost estimate
        $costCents = $this->estimateCost($req);

        // 7. Wallet reserve (atomic; throws InsufficientCreditsException)
        $reservationId = $this->wallet->reserve($clientId, $costCents);

        // 8–9. Persist drop + initial event in one transaction
        try {
            $drop = DB::connection('master')->transaction(function () use (
                $clientId, $userId, $apiKeyId, $req, $phoneE164, $idempotencyKey,
                $costCents, $reservationId
            ) {
                $drop = new Drop();
                $drop->id = Ulid::generate();
                $drop->client_id = $clientId;
                $drop->user_id = $userId;
                $drop->api_key_id = $apiKeyId;
                $drop->campaign_id = $req->campaignId;
                $drop->idempotency_key = $idempotencyKey;
                $drop->phone_e164 = $phoneE164;
                $drop->caller_id = $req->callerId;
                $drop->voice_template_id = $req->voiceTemplateId;
                $drop->priority = $req->priority;
                $drop->status = 'queued';
                $drop->provider = $req->providerHint;  // null = auto-pick at dispatch
                $drop->reservation_id = $reservationId;
                $drop->cost_cents = $costCents;
                $drop->callback_url = $req->callbackUrl;
                $drop->metadata = $req->metadata ?: null;
                $drop->scheduled_at = $req->scheduledAt ? Carbon::parse($req->scheduledAt) : null;
                $drop->save();

                Event::create([
                    'drop_id' => $drop->id,
                    'client_id' => $clientId,
                    'type' => 'queued',
                    'payload' => ['priority' => $req->priority],
                    'occurred_at' => Carbon::now(),
                ]);

                return $drop;
            });
        } catch (QueryException $e) {
            // Unique-index violation = lost idempotency race with another worker.
            if ($this->isDuplicateKey($e) && $idempotencyKey) {
                $this->wallet->refund($clientId, $reservationId);
                $existing = Drop::on('master')
                    ->where('client_id', $clientId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) return $existing;
            }
            $this->wallet->refund($clientId, $reservationId);
            throw $e;
        } catch (\Throwable $e) {
            $this->wallet->refund($clientId, $reservationId);
            throw $e;
        }

        // 10. Remember for future replays
        $this->idem->remember($clientId, $idempotencyKey, $fingerprint, $drop);

        // 11. Enqueue (Redis) — after commit so workers can find the row
        $delay = 0;
        if ($drop->scheduled_at && $drop->scheduled_at->isFuture()) {
            $delay = max(0, $drop->scheduled_at->diffInSeconds(Carbon::now(), false) * -1);
        }
        $this->dispatch->enqueue($drop, $delay);

        return $drop;
    }

    public function getDrop(int $clientId, string $dropId): ?Drop
    {
        return Drop::on('master')
            ->where('client_id', $clientId)
            ->where('id', $dropId)
            ->first();
    }

    /**
     * Cancel a drop only if it has not yet left the queue.
     * Refunds the reservation on success.
     */
    public function cancelDrop(int $clientId, string $dropId): ?Drop
    {
        return DB::connection('master')->transaction(function () use ($clientId, $dropId) {
            $drop = Drop::on('master')
                ->where('client_id', $clientId)
                ->where('id', $dropId)
                ->lockForUpdate()
                ->first();

            if (!$drop) return null;
            if ($drop->isTerminal()) return $drop;

            // Only pre-dispatch states are cancellable.
            if (!in_array($drop->status, ['queued', 'deferred'], true)) {
                return $drop;
            }

            $drop->status = 'cancelled';
            $drop->save();

            Event::create([
                'drop_id' => $drop->id,
                'client_id' => $clientId,
                'type' => 'cancelled',
                'occurred_at' => Carbon::now(),
            ]);

            if ($drop->reservation_id) {
                $this->wallet->refund($clientId, $drop->reservation_id);
            }

            return $drop;
        });
    }

    /**
     * Heuristic cost estimate. Real cost comes back on the provider callback.
     * Reads from config/rvm.php; defaults to 2 cents.
     */
    private function estimateCost(DropRequest $req): int
    {
        $base = (int) config('rvm.default_cost_cents', 2);
        if ($req->priority === Priority::INSTANT) {
            $base = (int) config('rvm.instant_cost_cents', $base + 1);
        }
        return $base;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
