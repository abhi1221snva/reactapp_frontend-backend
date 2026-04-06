<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CallRecordingReportController extends Controller
{
    /**
     * Main recording report — paginated, filtered, sorted.
     * POST /reports/call-recordings
     */
    public function index(Request $request)
    {
        $this->validate($request, [
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
            'number'      => 'nullable|string',
            'extension'   => 'nullable|string',
            'route'       => 'nullable|string|in:IN,OUT',
            'type'        => 'nullable|string',
            'campaign_id' => 'nullable|integer',
            'status'      => 'nullable|string',
            'duration_min' => 'nullable|integer|min:0',
            'duration_max' => 'nullable|integer|min:0',
            'search'      => 'nullable|string|max:200',
            'sort_by'     => 'nullable|string',
            'sort_dir'    => 'nullable|string|in:asc,desc',
            'page'        => 'nullable|integer|min:1',
            'per_page'    => 'nullable|integer|min:1|max:100',
        ]);

        $conn    = $this->tenantDb($request);
        $perPage = (int) ($request->input('per_page', 25));
        $page    = (int) ($request->input('page', 1));
        $offset  = ($page - 1) * $perPage;

        $userTz    = $request->auth->timezone ?? 'America/New_York';
        $startDate = $request->input('start_date', Carbon::now($userTz)->format('Y-m-d'));
        $endDate   = $request->input('end_date', Carbon::now($userTz)->format('Y-m-d'));
        $start     = $startDate . ' 00:00:00';
        $end       = $endDate . ' 23:59:59';

        $sortBy  = $request->input('sort_by', 'start_time');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowedSorts = ['start_time', 'duration', 'number', 'extension', 'route', 'type', 'disposition'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'start_time';
        }

        $params = [];
        $where  = ['c.start_time BETWEEN ? AND ?'];
        $params[] = $start;
        $params[] = $end;

        // Agent level restriction
        if (($request->auth->level ?? 0) < 5) {
            $where[]  = 'c.extension = ?';
            $params[] = $request->auth->extension;
        }

        if ($request->filled('number')) {
            $where[]  = "c.number LIKE CONCAT(?, '%')";
            $params[] = $request->input('number');
        }

        if ($request->filled('extension')) {
            $where[]  = 'c.extension = ?';
            $params[] = $request->input('extension');
        }

        if ($request->filled('route')) {
            $where[]  = 'c.route = ?';
            $params[] = $request->input('route');
        }

        if ($request->filled('type')) {
            $where[]  = 'c.type = ?';
            $params[] = $request->input('type');
        }

        if ($request->filled('campaign_id')) {
            $where[]  = 'c.campaign_id = ?';
            $params[] = (int) $request->input('campaign_id');
        }

        if ($request->filled('status')) {
            $status = strtolower($request->input('status'));
            if ($status === 'answered') {
                $where[] = 'c.duration > 0';
            } elseif ($status === 'missed' || $status === 'no_answer') {
                $where[] = '(c.duration = 0 OR c.duration IS NULL)';
            }
        }

        if ($request->filled('duration_min')) {
            $where[]  = 'c.duration >= ?';
            $params[] = (int) $request->input('duration_min');
        }

        if ($request->filled('duration_max')) {
            $where[]  = 'c.duration <= ?';
            $params[] = (int) $request->input('duration_max');
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $where[] = "(c.number LIKE CONCAT('%', ?, '%') OR c.extension LIKE CONCAT('%', ?, '%') OR c.call_recording LIKE CONCAT('%', ?, '%') OR d.title LIKE CONCAT('%', ?, '%'))";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $filter = 'WHERE ' . implode(' AND ', $where);

        // Main query
        $sql = "SELECT c.id, c.extension, c.number, c.start_time, c.end_time,
                       c.duration, c.route, c.call_recording, c.campaign_id,
                       c.lead_id, c.type, c.disposition_id, c.dnis,
                       c.call_matrix_reference_id,
                       d.title AS disposition,
                       camp.title AS campaign_name
                FROM cdr AS c
                LEFT JOIN disposition AS d ON c.disposition_id = d.id
                LEFT JOIN campaign AS camp ON c.campaign_id = camp.id
                {$filter}
                ORDER BY c.{$sortBy} {$sortDir}
                LIMIT ? OFFSET ?";

        $dataParams = array_merge($params, [$perPage, $offset]);

        // Count query
        $countSql = "SELECT COUNT(*) AS total FROM cdr AS c
                     LEFT JOIN disposition AS d ON c.disposition_id = d.id
                     {$filter}";

        try {
            $rows  = DB::connection($conn)->select($sql, $dataParams);
            $count = DB::connection($conn)->selectOne($countSql, $params);
            $total = $count->total ?? 0;

            // Hydrate agent names from master users table
            $extensions = collect($rows)->pluck('extension')->unique()->filter()->values()->toArray();
            $agentMap   = [];
            if (!empty($extensions)) {
                $placeholders = implode(',', array_fill(0, count($extensions), '?'));
                $agents = DB::connection('master')->select(
                    "SELECT extension, CONCAT(first_name, ' ', last_name) AS agent_name
                     FROM users WHERE parent_id = ? AND extension IN ({$placeholders}) AND is_deleted = 0",
                    array_merge([$this->tenantId($request)], $extensions)
                );
                foreach ($agents as $a) {
                    $agentMap[$a->extension] = $a->agent_name;
                }
            }

            // Attach agent name to each row
            $data = array_map(function ($row) use ($agentMap) {
                $row->agent_name = $agentMap[$row->extension] ?? null;
                return $row;
            }, $rows);

            return response()->json([
                'success'      => true,
                'data'         => $data,
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            Log::error('CallRecordingReportController@index: ' . $e->getMessage());
            return $this->failResponse('Failed to load report data', [], $e);
        }
    }

    /**
     * Quick stats — totals for summary cards.
     * POST /reports/call-recordings/stats
     */
    public function stats(Request $request)
    {
        $this->validate($request, [
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $conn   = $this->tenantDb($request);
        $userTz = $request->auth->timezone ?? 'America/New_York';
        $start  = ($request->input('start_date', Carbon::now($userTz)->format('Y-m-d'))) . ' 00:00:00';
        $end    = ($request->input('end_date', Carbon::now($userTz)->format('Y-m-d'))) . ' 23:59:59';

        $params = [$start, $end];
        $extFilter = '';
        if (($request->auth->level ?? 0) < 5) {
            $extFilter = ' AND extension = ?';
            $params[]  = $request->auth->extension;
        }

        try {
            $row = DB::connection($conn)->selectOne(
                "SELECT
                    COUNT(*)                                          AS total_calls,
                    SUM(CASE WHEN duration > 0 THEN 1 ELSE 0 END)    AS answered,
                    SUM(CASE WHEN duration = 0 OR duration IS NULL THEN 1 ELSE 0 END) AS missed,
                    SUM(CASE WHEN route = 'IN' THEN 1 ELSE 0 END)    AS inbound,
                    SUM(CASE WHEN route = 'OUT' OR route IS NULL THEN 1 ELSE 0 END) AS outbound,
                    COALESCE(AVG(NULLIF(duration, 0)), 0)             AS avg_duration,
                    COALESCE(SUM(duration), 0)                        AS total_duration,
                    SUM(CASE WHEN call_recording IS NOT NULL AND call_recording != '' THEN 1 ELSE 0 END) AS with_recording
                 FROM cdr
                 WHERE start_time BETWEEN ? AND ? {$extFilter}",
                $params
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'total_calls'    => (int) ($row->total_calls ?? 0),
                    'answered'       => (int) ($row->answered ?? 0),
                    'missed'         => (int) ($row->missed ?? 0),
                    'inbound'        => (int) ($row->inbound ?? 0),
                    'outbound'       => (int) ($row->outbound ?? 0),
                    'avg_duration'   => round((float) ($row->avg_duration ?? 0)),
                    'total_duration' => (int) ($row->total_duration ?? 0),
                    'with_recording' => (int) ($row->with_recording ?? 0),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('CallRecordingReportController@stats: ' . $e->getMessage());
            return $this->failResponse('Failed to load stats', [], $e);
        }
    }

    /**
     * Single call detail — includes analysis data if available.
     * GET /reports/call-recordings/{id}
     */
    public function show(Request $request, int $id)
    {
        $conn = $this->tenantDb($request);

        try {
            $call = DB::connection($conn)->selectOne(
                "SELECT c.*, d.title AS disposition, camp.title AS campaign_name
                 FROM cdr AS c
                 LEFT JOIN disposition AS d ON c.disposition_id = d.id
                 LEFT JOIN campaign AS camp ON c.campaign_id = camp.id
                 WHERE c.id = ?",
                [$id]
            );

            if (!$call) {
                return $this->failResponse('Call record not found', [], null, 404);
            }

            // Hydrate agent name
            if ($call->extension) {
                $agent = DB::connection('master')->selectOne(
                    "SELECT id, first_name, last_name, email, extension
                     FROM users WHERE parent_id = ? AND extension = ? AND is_deleted = 0 LIMIT 1",
                    [$this->tenantId($request), $call->extension]
                );
                $call->agent = $agent;
            }

            // Load AI analysis if reference_id exists
            $analysis = null;
            $summary  = null;
            $metrics  = [];
            if (!empty($call->call_matrix_reference_id)) {
                $analysis = DB::connection($conn)->selectOne(
                    "SELECT * FROM call_analysis_logs WHERE reference_id = ? LIMIT 1",
                    [$call->call_matrix_reference_id]
                );

                $summary = DB::connection($conn)->selectOne(
                    "SELECT * FROM call_analysis_summaries WHERE reference_id = ? LIMIT 1",
                    [$call->call_matrix_reference_id]
                );

                if ($summary) {
                    $metrics = DB::connection($conn)->select(
                        "SELECT * FROM agent_performance_metrics WHERE analysis_id = ? ORDER BY id",
                        [$summary->id]
                    );
                }
            }

            return response()->json([
                'success'  => true,
                'data'     => [
                    'call'     => $call,
                    'analysis' => $analysis,
                    'summary'  => $summary,
                    'metrics'  => $metrics,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('CallRecordingReportController@show: ' . $e->getMessage());
            return $this->failResponse('Failed to load call details', [], $e);
        }
    }

    /**
     * Export filtered data as CSV.
     * POST /reports/call-recordings/export
     */
    public function export(Request $request)
    {
        $this->validate($request, [
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $conn   = $this->tenantDb($request);
        $userTz = $request->auth->timezone ?? 'America/New_York';
        $start  = ($request->input('start_date', Carbon::now($userTz)->format('Y-m-d'))) . ' 00:00:00';
        $end    = ($request->input('end_date', Carbon::now($userTz)->format('Y-m-d'))) . ' 23:59:59';

        $params = [$start, $end];
        $where  = ['c.start_time BETWEEN ? AND ?'];

        if (($request->auth->level ?? 0) < 5) {
            $where[]  = 'c.extension = ?';
            $params[] = $request->auth->extension;
        }

        if ($request->filled('number')) {
            $where[]  = "c.number LIKE CONCAT(?, '%')";
            $params[] = $request->input('number');
        }
        if ($request->filled('extension')) {
            $where[]  = 'c.extension = ?';
            $params[] = $request->input('extension');
        }
        if ($request->filled('route')) {
            $where[]  = 'c.route = ?';
            $params[] = $request->input('route');
        }
        if ($request->filled('type')) {
            $where[]  = 'c.type = ?';
            $params[] = $request->input('type');
        }
        if ($request->filled('campaign_id')) {
            $where[]  = 'c.campaign_id = ?';
            $params[] = (int) $request->input('campaign_id');
        }
        if ($request->filled('status')) {
            $status = strtolower($request->input('status'));
            if ($status === 'answered') {
                $where[] = 'c.duration > 0';
            } elseif ($status === 'missed' || $status === 'no_answer') {
                $where[] = '(c.duration = 0 OR c.duration IS NULL)';
            }
        }
        if ($request->filled('search')) {
            $term    = $request->input('search');
            $where[] = "(c.number LIKE CONCAT('%', ?, '%') OR c.extension LIKE CONCAT('%', ?, '%'))";
            $params[] = $term;
            $params[] = $term;
        }

        $filter = 'WHERE ' . implode(' AND ', $where);

        try {
            $rows = DB::connection($conn)->select(
                "SELECT c.start_time, c.end_time, c.number, c.extension, c.route, c.type,
                        c.duration, d.title AS disposition, camp.title AS campaign_name,
                        c.call_recording, c.lead_id
                 FROM cdr AS c
                 LEFT JOIN disposition AS d ON c.disposition_id = d.id
                 LEFT JOIN campaign AS camp ON c.campaign_id = camp.id
                 {$filter}
                 ORDER BY c.start_time DESC
                 LIMIT 50000",
                $params
            );

            $filename = 'call_recordings_' . date('Ymd_His') . '.csv';
            $headers  = [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ];

            $callback = function () use ($rows) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Date/Time', 'End Time', 'Phone Number', 'Extension', 'Direction', 'Type', 'Duration (s)', 'Disposition', 'Campaign', 'Recording URL', 'Lead ID']);
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->start_time, $row->end_time, $row->number,
                        $row->extension, $row->route, $row->type,
                        $row->duration, $row->disposition, $row->campaign_name,
                        $row->call_recording, $row->lead_id,
                    ]);
                }
                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);
        } catch (\Throwable $e) {
            Log::error('CallRecordingReportController@export: ' . $e->getMessage());
            return $this->failResponse('Export failed', [], $e);
        }
    }
}
