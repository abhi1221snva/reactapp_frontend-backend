<?php

namespace App\Console\Commands;

use App\Services\DidPoolService;
use Illuminate\Console\Command;

class ClearDidCooldowns extends Command
{
    protected $signature   = 'did-pool:clear-cooldowns';
    protected $description = 'Move DIDs from cooldown to free status when the 24h cooldown has expired';

    public function handle()
    {
        $svc   = new DidPoolService();
        $count = $svc->clearExpiredCooldowns();

        $this->info("Cleared {$count} DID(s) from cooldown.");

        return 0;
    }
}
