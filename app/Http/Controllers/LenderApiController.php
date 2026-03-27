<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchLenderApiJob;
use App\Model\Client\CrmLenderAPis;
use App\Model\Client\CrmLenderApiLog;
use App\Services\ErrorParserService;
use App\Services\FixSuggestionService;
use App\Services\LenderApiService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * LenderApiController
 *
 * REST CRUD for crm_lender_apis configurations and crm_lender_api_logs.
 *
 * Routes (all under jwt.auth middleware):
 *   GET    /crm/lender-api-configs              → index
 *   GET    /crm/lender-api-configs/{id}         → show
 *   POST   /crm/lender-api-configs              → store
 *   PUT    /crm/lender-api-configs/{id}         → update
 *   DELETE /crm/lender-api-configs/{id}         → destroy
 *   POST   /crm/lender-api-configs/{id}/toggle  → toggle active/inactive
 *   POST   /crm/lender-api-configs/{id}/test    → testConfig (dry-run with dummy lead data)
 *   GET    /crm/lender-api-logs                 → logs (filterable)
 *   GET    /crm/lender-api-logs/{id}            → logDetail
 *   POST   /crm/lead/{leadId}/dispatch-lender-api → triggerForLead
 */
class LenderApiController extends Controller
{
    // ── List all API configs ───────────────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $clientId  = $request->auth->parent_id;
            $conn      = "mysql_{$clientId}";
            $hasLogTable = \Illuminate\Support\Facades\Schema::connection($conn)
                ->hasTable('crm_lender_api_logs');

            $selects = ['a.*', 'l.lender_name'];
            if ($hasLogTable) {
                $selects[] = DB::raw('(SELECT COUNT(*) FROM crm_lender_api_logs WHERE crm_lender_api_id = a.id) as log_count');
                $selects[] = DB::raw('(SELECT MAX(created_at) FROM crm_lender_api_logs WHERE crm_lender_api_id = a.id) as last_called_at');
                $selects[] = DB::raw('(SELECT SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) FROM crm_lender_api_logs WHERE crm_lender_api_id = a.id) as success_count');
            }

            $query = DB::connection($conn)->table('crm_lender_apis as a')
                ->leftJoin('crm_lender as l', 'l.id', '=', 'a.crm_lender_id')
                ->select($selects)
                ->orderBy('a.id', 'desc');

            if ($request->has('lender_id')) {
                $query->where('a.crm_lender_id', $request->integer('lender_id'));
            }

            if ($request->has('status')) {
                $query->where('a.status', $request->boolean('status'));
            }

            $configs = $query->get()->map(function ($row) {
                $r = (array) $row;
                // Decode JSON columns so the frontend gets parsed objects
                foreach (['auth_credentials', 'default_headers', 'payload_mapping', 'response_mapping'] as $col) {
                    if (isset($r[$col]) && is_string($r[$col])) {
                        $decoded = json_decode($r[$col], true);
                        $r[$col] = is_array($decoded) ? $decoded : null;
                    }
                }
                // ── Backward-compat: map legacy columns to new schema ──────────
                // Existing records created before the 2026-03-26 migration have
                // NULL in the new columns — fill them from the old ones so the
                // page renders without requiring a manual edit of every record.
                if (empty($r['api_name'])) {
                    $r['api_name'] = !empty($r['type'])
                        ? ucwords(str_replace('_', ' ', $r['type'])) . ' API'
                        : 'Legacy API #' . $r['id'];
                }
                if (empty($r['base_url']) && !empty($r['url'])) {
                    $r['base_url'] = $r['url'];
                }
                if (empty($r['request_method'])) {
                    $r['request_method'] = 'POST';
                }
                // Build auth_credentials from legacy flat columns if not set
                if (empty($r['auth_credentials'])) {
                    $creds = [];
                    if (!empty($r['username']))  $creds['username']  = $r['username'];
                    if (!empty($r['password']))  $creds['password']  = $r['password'];
                    if (!empty($r['api_key']))   $creds['key']       = $r['api_key'];
                    if (!empty($r['auth_url']))  $creds['token_url'] = $r['auth_url'];
                    if (!empty($r['client_id'])) $creds['client_id'] = $r['client_id'];
                    if (!empty($r['partner_api_key'])) $creds['partner_api_key'] = $r['partner_api_key'];
                    $r['auth_credentials'] = $creds ?: null;
                    // Infer auth_type
                    if (empty($r['auth_type']) || $r['auth_type'] === 'none') {
                        if (!empty($r['auth_url'])) {
                            $r['auth_type'] = 'oauth2';
                        } elseif (!empty($r['username']) && !empty($r['password'])) {
                            $r['auth_type'] = 'basic';
                        } elseif (!empty($r['api_key'])) {
                            $r['auth_type'] = 'api_key';
                        }
                    }
                }
                // Mask sensitive credentials
                if (isset($r['auth_credentials']['password'])) {
                    $r['auth_credentials']['password'] = '***';
                }
                if (isset($r['auth_credentials']['client_secret'])) {
                    $r['auth_credentials']['client_secret'] = '***';
                }
                return $r;
            });

            return $this->successResponse('Lender API configurations', $configs->values()->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to retrieve configurations', [$e->getMessage()], $e);
        }
    }

    // ── Show a single config ───────────────────────────────────────────────────

    public function show(Request $request, int $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $row      = DB::connection("mysql_{$clientId}")->table('crm_lender_apis')->where('id', $id)->first();

            if (!$row) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }

            $data = (array) $row;
            foreach (['auth_credentials', 'default_headers', 'payload_mapping', 'response_mapping'] as $col) {
                if (isset($data[$col]) && is_string($data[$col])) {
                    $data[$col] = json_decode($data[$col], true);
                }
            }

            return $this->successResponse('Lender API configuration', $data);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to retrieve configuration', [$e->getMessage()], $e);
        }
    }

    // ── Create a new config ────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $clientId = $request->auth->parent_id;

        $validator = Validator::make($request->all(), [
            'crm_lender_id'   => 'required|integer',
            'api_name'        => 'required|string|max:255',
            'auth_type'       => 'required|in:bearer,basic,api_key,oauth2,none',
            'base_url'        => 'required|url',
            'endpoint_path'   => 'nullable|string|max:500',
            'request_method'  => 'required|in:GET,POST,PUT,PATCH',
            'auth_credentials'  => 'nullable|array',
            'default_headers'   => 'nullable|array',
            'payload_mapping'   => 'nullable|array',
            'response_mapping'  => 'nullable|array',
            'retry_attempts'    => 'nullable|integer|min:1|max:10',
            'timeout_seconds'   => 'nullable|integer|min:5|max:300',
            'notes'             => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $now = Carbon::now();
            $id  = DB::connection("mysql_{$clientId}")->table('crm_lender_apis')->insertGetId([
                'crm_lender_id'    => $request->integer('crm_lender_id'),
                'api_name'         => $request->input('api_name'),
                'auth_type'        => $request->input('auth_type', 'none'),
                'auth_credentials' => json_encode($request->input('auth_credentials', [])),
                'base_url'         => rtrim($request->input('base_url'), '/'),
                'endpoint_path'    => ltrim($request->input('endpoint_path', ''), '/'),
                'request_method'   => $request->input('request_method', 'POST'),
                'default_headers'  => json_encode($request->input('default_headers', [])),
                'payload_mapping'  => json_encode($request->input('payload_mapping', [])),
                'response_mapping' => json_encode($request->input('response_mapping', [])),
                'retry_attempts'   => $request->input('retry_attempts', 3),
                'timeout_seconds'  => $request->input('timeout_seconds', 30),
                'status'           => true,
                'notes'            => $request->input('notes'),
                // Legacy compat: fill old columns from new structure
                'url'              => rtrim($request->input('base_url'), '/') . '/' . ltrim($request->input('endpoint_path', ''), '/'),
                'type'             => $request->input('type', ''),
                'username'         => $request->input('auth_credentials.username', ''),
                'password'         => $request->input('auth_credentials.password', ''),
                'api_key'          => $request->input('auth_credentials.key', ''),
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $config = DB::connection("mysql_{$clientId}")->table('crm_lender_apis')->where('id', $id)->first();
            return $this->successResponse('Configuration created', (array) $config);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to create configuration', [$e->getMessage()], $e, 500);
        }
    }

    // ── Update an existing config ──────────────────────────────────────────────

    public function update(Request $request, int $id)
    {
        $clientId = $request->auth->parent_id;

        $validator = Validator::make($request->all(), [
            'api_name'         => 'sometimes|string|max:255',
            'auth_type'        => 'sometimes|in:bearer,basic,api_key,oauth2,none',
            'base_url'         => 'sometimes|url',
            'endpoint_path'    => 'nullable|string|max:500',
            'request_method'   => 'sometimes|in:GET,POST,PUT,PATCH',
            'auth_credentials' => 'nullable|array',
            'default_headers'  => 'nullable|array',
            'payload_mapping'  => 'nullable|array',
            'response_mapping' => 'nullable|array',
            'retry_attempts'   => 'nullable|integer|min:1|max:10',
            'timeout_seconds'  => 'nullable|integer|min:5|max:300',
            'status'           => 'sometimes|boolean',
            'notes'            => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $existing = DB::connection("mysql_{$clientId}")->table('crm_lender_apis')->where('id', $id)->first();
            if (!$existing) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }

            $update = ['updated_at' => Carbon::now()];

            $scalars = ['api_name', 'auth_type', 'base_url', 'endpoint_path', 'request_method', 'retry_attempts', 'timeout_seconds', 'status', 'notes', 'type'];
            foreach ($scalars as $field) {
                if ($request->has($field)) {
                    $update[$field] = $request->input($field);
                }
            }

            foreach (['auth_credentials', 'default_headers', 'payload_mapping', 'response_mapping'] as $jsonField) {
                if ($request->has($jsonField)) {
                    $update[$jsonField] = json_encode($request->input($jsonField));
                }
            }

            // Keep legacy columns in sync
            if (isset($update['base_url']) || isset($update['endpoint_path'])) {
                $base = rtrim($update['base_url'] ?? $existing->base_url ?? $existing->url ?? '', '/');
                $path = ltrim($update['endpoint_path'] ?? $existing->endpoint_path ?? '', '/');
                $update['url'] = $path ? "{$base}/{$path}" : $base;
            }

            DB::connection("mysql_{$clientId}")->table('crm_lender_apis')->where('id', $id)->update($update);

            $config = DB::connection("mysql_{$clientId}")->table('crm_lender_apis')->where('id', $id)->first();
            return $this->successResponse('Configuration updated', (array) $config);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to update configuration', [$e->getMessage()], $e, 500);
        }
    }

    // ── Delete a config ────────────────────────────────────────────────────────

    public function destroy(Request $request, int $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $deleted = DB::connection("mysql_{$clientId}")->table('crm_lender_apis')->where('id', $id)->delete();
            if (!$deleted) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }
            return $this->successResponse('Configuration deleted', []);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to delete configuration', [$e->getMessage()], $e, 500);
        }
    }

    // ── Toggle active/inactive ─────────────────────────────────────────────────

    public function toggle(Request $request, int $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $row = DB::connection("mysql_{$clientId}")->table('crm_lender_apis')->where('id', $id)->first();
            if (!$row) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }
            $newStatus = !$row->status;
            DB::connection("mysql_{$clientId}")->table('crm_lender_apis')
                ->where('id', $id)
                ->update(['status' => $newStatus, 'updated_at' => Carbon::now()]);
            return $this->successResponse('Status updated', ['status' => $newStatus]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to toggle', [$e->getMessage()], $e, 500);
        }
    }

    // ── Test a config with sample data (dry-run) ───────────────────────────────

    public function testConfig(Request $request, int $id)
    {
        $clientId = $request->auth->parent_id;

        try {
            $config = CrmLenderAPis::on("mysql_{$clientId}")->find($id);
            if (!$config) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }

            // Use provided sample data or empty array
            $sampleData = $request->input('sample_data', []);

            $svc     = new LenderApiService();
            $payload = $svc->buildPayload($config, $sampleData);

            // Return what would be sent — no actual HTTP call
            return $this->successResponse('Dry-run payload preview', [
                'url'             => $config->fullUrl(),
                'method'          => $config->request_method,
                'auth_type'       => $config->auth_type,
                'computed_payload' => $payload,
                'default_headers'  => $config->default_headers,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Test failed', [$e->getMessage()], $e, 500);
        }
    }

    // ── API Logs ───────────────────────────────────────────────────────────────

    /**
     * GET /crm/lender-api-logs
     * Queryable: lender_id, lead_id, crm_lender_api_id, status, per_page
     */
    public function logs(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";

            if (!\Illuminate\Support\Facades\Schema::connection($conn)->hasTable('crm_lender_api_logs')) {
                return $this->successResponse('Lender API logs', [
                    'data' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'last_page' => 1,
                    '_note' => 'Run migrations to create crm_lender_api_logs table.',
                ]);
            }

            $perPage  = min((int) $request->input('per_page', 20), 100);

            $query = DB::connection($conn)
                ->table('crm_lender_api_logs as lg')
                ->leftJoin('crm_lender as l',        'l.id',  '=', 'lg.lender_id')
                ->leftJoin('crm_lender_apis as cfg',  'cfg.id', '=', 'lg.crm_lender_api_id')
                ->select(
                    'lg.*',
                    'l.lender_name',
                    'cfg.api_name',
                )
                ->orderBy('lg.id', 'desc');

            if ($request->filled('lender_id')) {
                $query->where('lg.lender_id', $request->integer('lender_id'));
            }
            if ($request->filled('lead_id')) {
                $query->where('lg.lead_id', $request->integer('lead_id'));
            }
            if ($request->filled('crm_lender_api_id')) {
                $query->where('lg.crm_lender_api_id', $request->integer('crm_lender_api_id'));
            }
            if ($request->filled('status')) {
                $query->where('lg.status', $request->input('status'));
            }
            if ($request->filled('date_from')) {
                $query->where('lg.created_at', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('lg.created_at', '<=', $request->input('date_to') . ' 23:59:59');
            }

            $total   = (clone $query)->count();
            $page    = max(1, (int) $request->input('page', 1));
            $records = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()
                ->map(fn ($r) => (array) $r)
                ->values();

            return $this->successResponse('Lender API logs', [
                'data'       => $records,
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'last_page'  => max(1, (int) ceil($total / $perPage)),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to retrieve logs', [$e->getMessage()], $e);
        }
    }

    /**
     * GET /crm/lender-api-logs/{id}
     * Full detail of a single log entry including full request/response payloads.
     */
    public function logDetail(Request $request, int $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";

            if (!\Illuminate\Support\Facades\Schema::connection($conn)->hasTable('crm_lender_api_logs')) {
                return $this->failResponse('crm_lender_api_logs table not yet created — run migrations', [], null, 503);
            }

            $row = DB::connection($conn)->table('crm_lender_api_logs')->where('id', $id)->first();

            if (!$row) {
                return $this->failResponse('Log entry not found', [], null, 404);
            }

            return $this->successResponse('Log detail', (array) $row);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to retrieve log', [$e->getMessage()], $e);
        }
    }

    // ── Apply fix to lead field and optionally re-dispatch ─────────────────────

    /**
     * POST /crm/lead/{leadId}/apply-lender-fix
     *
     * Body:
     *   field_key  string   CRM EAV field_key to update (e.g. "home_state")
     *   new_value  string   The corrected value
     *   lender_id  int      Lender to resubmit to (required when resubmit=true)
     *   resubmit   bool     If true, queue a new DispatchLenderApiJob after saving
     *   log_id     int?     Optional — log entry to fetch before responding
     *
     * Saves the field value to crm_lead_values, then optionally redispatches.
     * Returns the updated lead field value and, when resubmit=true, queued info.
     */
    public function applyFix(Request $request, int $leadId)
    {
        $clientId = $request->auth->parent_id;
        $userId   = $request->auth->id ?? 0;

        $validator = Validator::make($request->all(), [
            'field_key' => 'required|string|max:200',
            'new_value' => 'required|string|max:1000',
            'lender_id' => 'nullable|integer',
            'resubmit'  => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $fieldKey = $request->input('field_key');
            $newValue = $request->input('new_value');
            $resubmit = $request->boolean('resubmit', false);
            $lenderId = $request->integer('lender_id', 0);
            $conn     = "mysql_{$clientId}";

            // ── Verify lead exists ────────────────────────────────────────────
            $leadExists = DB::connection($conn)->table('crm_leads')->where('id', $leadId)->exists();
            if (!$leadExists) {
                return $this->failResponse('Lead not found', [], null, 404);
            }

            // ── Save to crm_lead_values (upsert) ─────────────────────────────
            $existing = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->where('field_key', $fieldKey)
                ->first();

            $now = Carbon::now();

            if ($existing) {
                DB::connection($conn)
                    ->table('crm_lead_values')
                    ->where('lead_id', $leadId)
                    ->where('field_key', $fieldKey)
                    ->update(['field_value' => $newValue, 'updated_at' => $now]);
            } else {
                DB::connection($conn)->table('crm_lead_values')->insert([
                    'lead_id'     => $leadId,
                    'field_key'   => $fieldKey,
                    'field_value' => $newValue,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }

            $response = [
                'lead_id'   => $leadId,
                'field_key' => $fieldKey,
                'new_value' => $newValue,
                'saved_at'  => $now->toDateTimeString(),
            ];

            // ── Optionally re-dispatch to lender ──────────────────────────────
            if ($resubmit && $lenderId > 0) {
                $config = DB::connection($conn)
                    ->table('crm_lender_apis')
                    ->where('crm_lender_id', $lenderId)
                    ->where('status', true)
                    ->first();

                if (!$config) {
                    return $this->failResponse('No active API configuration found for this lender', [], null, 404);
                }

                dispatch(new DispatchLenderApiJob($clientId, $leadId, $lenderId, $userId))
                    ->onConnection('redis')
                    ->onQueue('default');

                $response['resubmitted'] = true;
                $response['lender_id']   = $lenderId;
                $response['queued_at']   = $now->toDateTimeString();
            }

            return $this->successResponse('Fix applied', $response);

        } catch (\Throwable $e) {
            return $this->failResponse('Failed to apply fix', [$e->getMessage()], $e, 500);
        }
    }

    // ── Manual trigger: send a lead to a specific lender API ──────────────────

    /**
     * POST /crm/lead/{leadId}/dispatch-lender-api
     * Body: { lender_id: int, config_id?: int }
     *
     * Queues a DispatchLenderApiJob for the given lead + lender.
     * Respects both legacy (SendLeadByLenderApi) and new job based on config.
     */
    public function triggerForLead(Request $request, int $leadId)
    {
        $clientId = $request->auth->parent_id;

        $validator = Validator::make($request->all(), [
            'lender_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $lenderId = $request->integer('lender_id');
            $userId   = $request->auth->id ?? 0;

            // Verify lender exists
            $lender = DB::connection("mysql_{$clientId}")->table('crm_lender')->where('id', $lenderId)->first();
            if (!$lender) {
                return $this->failResponse('Lender not found', [], null, 404);
            }

            // Verify active API config exists
            $config = DB::connection("mysql_{$clientId}")
                ->table('crm_lender_apis')
                ->where('crm_lender_id', $lenderId)
                ->where('status', true)
                ->first();

            if (!$config) {
                return $this->failResponse('No active API configuration found for this lender', [], null, 404);
            }

            dispatch(new DispatchLenderApiJob($clientId, $leadId, $lenderId, $userId))
                ->onConnection('redis')
                ->onQueue('default');

            return $this->successResponse('API call queued', [
                'lead_id'   => $leadId,
                'lender_id' => $lenderId,
                'api_name'  => $config->api_name ?: $config->type,
                'queued_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to queue API call', [$e->getMessage()], $e, 500);
        }
    }
}
