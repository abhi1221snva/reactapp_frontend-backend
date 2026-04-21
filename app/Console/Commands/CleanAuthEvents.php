<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CleanAuthEvents extends Command
{
    protected $signature = 'auth:clean-events {--days=90 : Delete events older than N days}';
    protected $description = 'Delete auth_events records older than the specified retention period';

    public function handle()
    {
        $days   = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);

        $deleted = DB::connection('master')
            ->table('auth_events')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} auth events older than {$days} days.");

        return 0;
    }
}
