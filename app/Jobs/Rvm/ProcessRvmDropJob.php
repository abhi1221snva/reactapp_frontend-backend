<?php

namespace App\Jobs\Rvm;

use App\Jobs\Job;
use App\Model\Master\Rvm\Drop;
use App\Model\Master\Rvm\Event;
use App\Services\Rvm\Exceptions\ProviderPermanentError;
use App\Services\Rvm\Exceptions\ProviderTransientError;
use App\Services\Rvm\RvmComplianceService;
use App\Services\Rvm\RvmDispatchService;
use App\Services\Rvm\RvmProviderRouter;
use App\Services\Rvm\RvmWalletService;
use App\Services\Rvm\RvmWebhookService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Unified worker — replaces SendRvmJob and the 5 legacy job classes.
 *
 * Lifecycle:
 *   1. Lock drop row; bail if already terminal
 *   2. Campaign pause check
 *   3. Compliance re-check (defer if window closed)
 *   4. Pick provider via router
 *   5. deliver() — provider may throw transient or permanent error
 *   6. Persist provider result
 *   7. Commit or refund wallet reservation
 *   8. Emit rvm_events + tenant webhook
 *
 * Retry policy is driven by $tries + $backoff. After final failure the
 * failed() hook marks the drop failed + refunds the wallet.
 */
class ProcessRvmDropJob extends Job
{
    public int $tries = 5;
    public int $timeout = 30;
    public int $maxExceptions = 3;
    public array $backoff = [15, 60, 300, 900, 3600];

    public function __construct(public string $dropId) {}

    public function handle(
        RvmProviderRouter $router,
        RvmComplianceService $compliance,
        RvmWalletService $wallet,
        RvmWebhookService $webhook,
        RvmDispatchService $dispatch,
    ): void {
        $drop = DB::connection('master')->transaction(function () {
            return Drop::on('master')
                ->where('id', $this->dropId)
                ->lockForUpdate()
                ->first();
        });

        if (!$drop) {
            Log::warning('ProcessRvmDropJob: drop not found', ['drop_id' => $this->dropId]);
            return;
        }
        if ($drop->isTerminal()) return;  // race guard

        // Compliance re-check — time may have shifted across retries
        if (!$compliance->windowOpen($drop)) {
            $drop->status = 'deferred';
            $drop->deferred_until = $compliance->nextWindow($drop);
            $drop->save();

            Event::create([
                'drop_id' => $drop->id,
                'client_id' => $drop->client_id,
                'type' => 'deferred',
                'payload' => ['until' => $drop->deferred_until?->toIso8601String()],
                'occurred_at' => Carbon::now(),
            ]);
            return;
        }

        // Mark dispatching + atomic try increment
        Drop::on('master')
            ->where('id', $drop->id)
            ->update([
                'status' => 'dispatching',
                'dispatched_at' => Carbon::now(),
            ]);
        Drop::on('master')
            ->where('id', $drop->id)
            ->increment('tries');
        $drop->refresh();

        // Pick provider + deliver
        $provider = $router->pickProvider($drop);

        try {
            $result = $provider->deliver($drop);
        } catch (ProviderTransientError $e) {
            // Mark breaker, allow queue to retry
            $router->markUnhealthy($provider->name());
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
            return;
        } catch (ProviderPermanentError $e) {
            $this->markFailed($drop, $provider->name(), $e->getMessage(), $wallet, $webhook);
            return;
        }

        // Success path
        $updates = [
            'provider' => $provider->name(),
            'provider_message_id' => $result->externalId,
            'provider_cost_cents' => $result->costCents,
        ];

        if ($result->successful()) {
            $updates['status'] = $result->status;  // delivered|dispatching
            if ($result->status === 'delivered') {
                $updates['delivered_at'] = Carbon::now();
            }
            Drop::on('master')->where('id', $drop->id)->update($updates);

            // Commit credits only on terminal success; leave reserved for async "dispatching"
            if ($result->status === 'delivered' && $drop->reservation_id) {
                $wallet->commit($drop->client_id, $drop->reservation_id);
            }

            Event::create([
                'drop_id' => $drop->id,
                'client_id' => $drop->client_id,
                'type' => $result->status,
                'provider' => $provider->name(),
                'payload' => ['external_id' => $result->externalId],
                'occurred_at' => Carbon::now(),
            ]);

            $drop->refresh();
            $webhook->enqueue($drop, 'rvm.drop.' . $result->status);
            return;
        }

        // result->failed() — permanent; handled the same as ProviderPermanentError
        $this->markFailed($drop, $provider->name(), $result->errorMessage ?? 'unknown', $wallet, $webhook);
    }

    /**
     * Terminal failure after all retries exhausted.
     */
    public function failed(Throwable $e): void
    {
        Log::error('ProcessRvmDropJob permanently failed', [
            'drop_id' => $this->dropId,
            'error' => $e->getMessage(),
        ]);

        try {
            $drop = Drop::on('master')->find($this->dropId);
            if (!$drop || $drop->isTerminal()) return;

            Drop::on('master')->where('id', $this->dropId)->update([
                'status' => 'failed',
                'failed_at' => Carbon::now(),
                'last_error' => substr($e->getMessage(), 0, 500),
            ]);

            Event::create([
                'drop_id' => $this->dropId,
                'client_id' => $drop->client_id,
                'type' => 'failed',
                'payload' => ['error' => substr($e->getMessage(), 0, 500)],
                'occurred_at' => Carbon::now(),
            ]);

            if ($drop->reservation_id) {
                app(RvmWalletService::class)->refund($drop->client_id, $drop->reservation_id);
            }

            app(RvmWebhookService::class)->enqueue($drop->refresh(), 'rvm.drop.failed');
        } catch (Throwable $cleanupError) {
            // Never let failed() itself throw
            Log::error('ProcessRvmDropJob::failed cleanup error', ['error' => $cleanupError->getMessage()]);
        }
    }

    private function markFailed(
        Drop $drop,
        string $providerName,
        string $message,
        RvmWalletService $wallet,
        RvmWebhookService $webhook,
    ): void {
        Drop::on('master')->where('id', $drop->id)->update([
            'status' => 'failed',
            'provider' => $providerName,
            'failed_at' => Carbon::now(),
            'last_error' => substr($message, 0, 500),
        ]);

        if ($drop->reservation_id) {
            $wallet->refund($drop->client_id, $drop->reservation_id);
        }

        Event::create([
            'drop_id' => $drop->id,
            'client_id' => $drop->client_id,
            'type' => 'failed',
            'provider' => $providerName,
            'payload' => ['error' => substr($message, 0, 500)],
            'occurred_at' => Carbon::now(),
        ]);

        $drop->refresh();
        $webhook->enqueue($drop, 'rvm.drop.failed');
    }
}
