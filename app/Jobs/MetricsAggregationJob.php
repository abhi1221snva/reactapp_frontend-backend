<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Metrics Aggregation Job
 *
 * Pre-aggregates CDR data into daily_metric_snapshots for fast dashboard queries.
 * Run every 15 minutes via scheduler, or on-demand via POST /dashboard/trigger-aggregation.
 *
 * Dispatch: MetricsAggregationJob::dispatch($clientId, $date)
 * Queue:    'metrics'
 */
class MetricsAggregationJob extends Job
{
    public $tries   = 3;
    public $timeout = 120;

    private int    $clientId;
    private string $date; // Y-m-d

    public function __construct(int $clientId, string $date = null)
    {
        $this->clientId = $clientId;
        $this->date     = $date ?? Carbon::today()->toDateString();
        $this->onQueue('metrics');
    }

    public function handle(): void
    {
        $start = microtime(true);
        $db    = DB::connection('mysql_' . $this->clientId);

        Log::info("MetricsAggregation: Starting for client {$this->clientId} date {$this->date}");

        try {
            $this->aggregateDaySnapshot($db);
            $this->aggregateCampaignSnapshots($db);
            $this->aggregateAgentSnapshots($db);

            $elapsed = round((microtime(true) - $start) * 1000);
            Log::info("MetricsAggregation: Completed in {$elapsed}ms", [
                'client' => $this->clientId,
                'date'   => $this->date,
            ]);

            // Bust the fast-stats cache so next request gets fresh data
            \Illuminate\Support\Facades\Cache::forget("dashboard:fast:{$this->clientId}:7");
            \Illuminate\Support\Facades\Cache::forget("dashboard:fast:{$this->clientId}:30");
            \Illuminate\Support\Facades\Cache::forget("dashboard:fast:{$this->clientId}:90");

        } catch (\Exception $e) {
            Log::error("MetricsAggregation: Failed for client {$this->clientId}", [
                'error' => $e->getMessage(),
                'date'  => $this->date,
            ]);
            throw $e;
        }
    }

    // ─── Day-level snapshot ──────────────────────────────────────────────────────

    private function aggregateDaySnapshot($db): void
    {
        $row = $db->table('cdr')
            ->whereDate('created_at', $this->date)
            ->selectRaw("
                COUNT(*)                                                  AS total_calls,
                SUM(CASE WHEN status = 'answered'  THEN 1 ELSE 0 END)   AS answered_calls,
                SUM(CASE WHEN status = 'no-answer' THEN 1 ELSE 0 END)   AS missed_calls,
                SUM(CASE WHEN status = 'failed'    THEN 1 ELSE 0 END)   AS failed_calls,
                SUM(CASE WHEN direction = 'inbound'  THEN 1 ELSE 0 END) AS inbound_calls,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) AS outbound_calls,
                COALESCE(SUM(duration), 0)                               AS total_talk_time,
                COALESCE(AVG(NULLIF(duration, 0)), 0)                    AS avg_talk_time,
                COALESCE(MAX(duration), 0)                               AS max_talk_time,
                SUM(CASE WHEN disposition_id IS NOT NULL THEN 1 ELSE 0 END) AS dispositioned_calls
            ")
            ->first();

        if (!$row || (int) $row->total_calls === 0) return;

        $total    = (int) $row->total_calls;
        $answered = (int) $row->answered_calls;

        $this->upsert($db, [
            'snapshot_date'       => $this->date,
            'campaign_id'         => null,
            'agent_id'            => null,
            'granularity'         => 'day',
            'total_calls'         => $total,
            'answered_calls'      => $answered,
            'missed_calls'        => (int) $row->missed_calls,
            'failed_calls'        => (int) $row->failed_calls,
            'inbound_calls'       => (int) $row->inbound_calls,
            'outbound_calls'      => (int) $row->outbound_calls,
            'total_talk_time'     => (int) $row->total_talk_time,
            'avg_talk_time'       => (int) round($row->avg_talk_time),
            'max_talk_time'       => (int) $row->max_talk_time,
            'answer_rate'         => $total > 0 ? round($answered / $total * 100, 2) : 0,
            'dispositioned_calls' => (int) $row->dispositioned_calls,
        ]);
    }

    // ─── Campaign-level snapshots ────────────────────────────────────────────────

    private function aggregateCampaignSnapshots($db): void
    {
        $rows = $db->table('cdr')
            ->whereDate('created_at', $this->date)
            ->whereNotNull('campaign_id')
            ->groupBy('campaign_id')
            ->selectRaw("
                campaign_id,
                COUNT(*)                                                  AS total_calls,
                SUM(CASE WHEN status = 'answered'  THEN 1 ELSE 0 END)   AS answered_calls,
                SUM(CASE WHEN status = 'no-answer' THEN 1 ELSE 0 END)   AS missed_calls,
                SUM(CASE WHEN status = 'failed'    THEN 1 ELSE 0 END)   AS failed_calls,
                COALESCE(SUM(duration), 0)                               AS total_talk_time,
                COALESCE(AVG(NULLIF(duration, 0)), 0)                    AS avg_talk_time,
                SUM(CASE WHEN disposition_id IS NOT NULL THEN 1 ELSE 0 END) AS dispositioned_calls
            ")
            ->get();

        foreach ($rows as $row) {
            $total    = (int) $row->total_calls;
            $answered = (int) $row->answered_calls;
            $this->upsert($db, [
                'snapshot_date'       => $this->date,
                'campaign_id'         => $row->campaign_id,
                'agent_id'            => null,
                'granularity'         => 'campaign',
                'total_calls'         => $total,
                'answered_calls'      => $answered,
                'missed_calls'        => (int) $row->missed_calls,
                'failed_calls'        => (int) $row->failed_calls,
                'total_talk_time'     => (int) $row->total_talk_time,
                'avg_talk_time'       => (int) round($row->avg_talk_time),
                'answer_rate'         => $total > 0 ? round($answered / $total * 100, 2) : 0,
                'dispositioned_calls' => (int) $row->dispositioned_calls,
            ]);
        }
    }

    // ─── Agent-level snapshots ───────────────────────────────────────────────────

    private function aggregateAgentSnapshots($db): void
    {
        $rows = $db->table('cdr')
            ->whereDate('created_at', $this->date)
            ->whereNotNull('agent_id')
            ->groupBy('agent_id')
            ->selectRaw("
                agent_id,
                COUNT(*)                                                  AS total_calls,
                SUM(CASE WHEN status = 'answered'  THEN 1 ELSE 0 END)   AS answered_calls,
                SUM(CASE WHEN status = 'no-answer' THEN 1 ELSE 0 END)   AS missed_calls,
                COALESCE(SUM(duration), 0)                               AS total_talk_time,
                COALESCE(AVG(NULLIF(duration, 0)), 0)                    AS avg_talk_time,
                COUNT(DISTINCT lead_id)                                  AS leads_contacted
            ")
            ->get();

        foreach ($rows as $row) {
            $total    = (int) $row->total_calls;
            $answered = (int) $row->answered_calls;
            $this->upsert($db, [
                'snapshot_date'   => $this->date,
                'campaign_id'     => null,
                'agent_id'        => $row->agent_id,
                'granularity'     => 'agent',
                'total_calls'     => $total,
                'answered_calls'  => $answered,
                'missed_calls'    => (int) $row->missed_calls,
                'total_talk_time' => (int) $row->total_talk_time,
                'avg_talk_time'   => (int) round($row->avg_talk_time),
                'answer_rate'     => $total > 0 ? round($answered / $total * 100, 2) : 0,
                'leads_contacted' => (int) $row->leads_contacted,
            ]);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function upsert($db, array $data): void
    {
        $keys = [
            'snapshot_date' => $data['snapshot_date'],
            'campaign_id'   => $data['campaign_id'],
            'agent_id'      => $data['agent_id'],
            'granularity'   => $data['granularity'],
        ];

        $update = array_merge(
            array_diff_key($data, $keys),
            ['updated_at' => now()]
        );

        $existing = $db->table('daily_metric_snapshots')->where($keys)->first();

        if ($existing) {
            $db->table('daily_metric_snapshots')->where($keys)->update($update);
        } else {
            $db->table('daily_metric_snapshots')->insert(array_merge(
                $data,
                ['created_at' => now(), 'updated_at' => now()]
            ));
        }
    }
}
