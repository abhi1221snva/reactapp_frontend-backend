<?php

namespace App\Console\Commands;

use App\Jobs\MetricsAggregationJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Dispatch MetricsAggregationJob for all active clients (or a specific one).
 *
 * Usage:
 *   php artisan metrics:aggregate                 # aggregates today for all clients
 *   php artisan metrics:aggregate --date=2026-03-10  # specific date
 *   php artisan metrics:aggregate --client=42     # specific client
 */
class AggregateMetricsCommand extends Command
{
    protected $signature   = 'metrics:aggregate
                                {--date= : Date to aggregate (Y-m-d, defaults to today)}
                                {--client= : Specific client ID (defaults to all active)}';

    protected $description = 'Pre-aggregate CDR metrics into daily_metric_snapshots';

    public function handle(): void
    {
        $date = $this->option('date') ?? Carbon::today()->toDateString();
        $this->info("Aggregating metrics for date: {$date}");

        if ($clientId = $this->option('client')) {
            MetricsAggregationJob::dispatch((int) $clientId, $date);
            $this->info("Dispatched aggregation job for client {$clientId}");
            return;
        }

        // All active clients
        $clients = DB::table('clients')->where('status', 1)->pluck('id');

        if ($clients->isEmpty()) {
            $this->warn('No active clients found.');
            return;
        }

        foreach ($clients as $id) {
            MetricsAggregationJob::dispatch((int) $id, $date);
        }

        $this->info("Dispatched {$clients->count()} aggregation jobs onto the 'metrics' queue.");
        $this->line("Run queue worker: php artisan queue:work --queue=metrics,twilio,default");
    }
}
