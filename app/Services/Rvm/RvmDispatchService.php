<?php

namespace App\Services\Rvm;

use App\Jobs\Rvm\ProcessRvmDropJob;
use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\DTO\Priority;
use Illuminate\Support\Carbon;

/**
 * Queue orchestration — the ONLY place in the codebase that knows which
 * Redis queue a drop should go on. RvmDropService calls enqueue() after
 * the drop row has been persisted.
 */
class RvmDispatchService
{
    public function enqueue(Drop $drop, int $delaySeconds = 0): void
    {
        $queueName = Priority::queue($drop->priority);

        $job = (new ProcessRvmDropJob($drop->id))
            ->onConnection('redis')
            ->onQueue($queueName);

        if ($delaySeconds > 0) {
            $job = $job->delay(Carbon::now()->addSeconds($delaySeconds));
        }

        dispatch($job);
    }

    /**
     * Re-enqueue a deferred drop once its window opens.
     * Called by the scheduler sweeping rvm_drops WHERE status='deferred'.
     */
    public function requeueDeferred(Drop $drop): void
    {
        $drop->status = 'queued';
        $drop->deferred_until = null;
        $drop->save();

        $this->enqueue($drop);
    }
}
