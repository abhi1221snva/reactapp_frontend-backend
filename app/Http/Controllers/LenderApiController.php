<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchLenderApiJob;
use App\Model\Client\Lender;
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
 * REST CRUD for lender API configurations (stored on crm_lender) and crm_lender_api_logs.
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

            $selects = ['a.*'];
            if ($hasLogTable) {
                $selects[] = DB::raw('(SELECT COUNT(*) FROM crm_lender_api_logs WHERE lender_id = a.id) as log_count');
                $selects[] = DB::raw('(SELECT MAX(created_at) FROM crm_lender_api_logs WHERE lender_id = a.id) as last_called_at');
                $selects[] = DB::raw('(SELECT SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) FROM crm_lender_api_logs WHERE lender_id = a.id) as success_count');
            }

            $query = DB::connection($conn)->table('crm_lender as a')
                ->select($selects)
                ->orderBy('a.id', 'desc');

            if ($request->has('lender_id')) {
                $query->where('a.id', $request->integer('lender_id'));
            }

            if ($request->has('status')) {
                $query->where('a.api_status', $request->input('status'));
            }

            $configs = $query->get()->map(function ($row) {
                $r = (array) $row;
                // Decode JSON columns so the frontend gets parsed objects
                foreach (['auth_credentials', 'default_headers', 'payload_mapping', 'response_mapping', 'required_fields'] as $col) {
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
                if (empty($r['base_url']) && !empty($r['api_url'])) {
                    $r['base_url'] = $r['api_url'];
                }
                if (empty($r['request_method'])) {
                    $r['request_method'] = 'POST';
                }
                // Build auth_credentials from legacy flat columns if not set
                if (empty($r['auth_credentials'])) {
                    $creds = [];
                    if (!empty($r['api_username']))  $creds['username']  = $r['api_username'];
                    if (!empty($r['api_password']))  $creds['password']  = $r['api_password'];
                    if (!empty($r['api_key']))       $creds['key']       = $r['api_key'];
                    if (!empty($r['auth_url']))      $creds['token_url'] = $r['auth_url'];
                    if (!empty($r['api_client_id'])) $creds['client_id'] = $r['api_client_id'];
                    if (!empty($r['partner_api_key'])) $creds['partner_api_key'] = $r['partner_api_key'];
                    $r['auth_credentials'] = $creds ?: null;
                    // Infer auth_type
                    if (empty($r['auth_type']) || $r['auth_type'] === 'none') {
                        if (!empty($r['auth_url'])) {
                            $r['auth_type'] = 'oauth2';
                        } elseif (!empty($r['api_username']) && !empty($r['api_password'])) {
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
            $row      = DB::connection("mysql_{$clientId}")->table('crm_lender')->where('id', $id)->first();

            if (!$row) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }

            $data = (array) $row;
            foreach (['auth_credentials', 'default_headers', 'payload_mapping', 'response_mapping', 'required_fields'] as $col) {
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
            'lender_id'       => 'required|integer',
            'api_name'        => 'required|string|max:255',
            'auth_type'       => 'required|in:bearer,basic,api_key,oauth2,none',
            'base_url'        => 'required|url',
            'endpoint_path'   => 'nullable|string|max:500',
            'request_method'  => 'required|in:GET,POST,PUT,PATCH',
            'auth_credentials'  => 'nullable|array',
            'default_headers'   => 'nullable|array',
            'payload_mapping'   => 'nullable|array',
            'response_mapping'  => 'nullable|array',
            'retry_attempts'             => 'nullable|integer|min:1|max:10',
            'timeout_seconds'            => 'nullable|integer|min:5|max:300',
            'api_notes'                  => 'nullable|string',
            'required_fields'            => 'nullable|array',
            'required_fields.*'          => 'string',
            'resubmit_method'            => 'nullable|in:PUT,PATCH',
            'resubmit_endpoint_path'     => 'nullable|string|max:500',
            'document_upload_enabled'    => 'nullable|boolean',
            'document_upload_endpoint'   => 'nullable|string|max:500',
            'document_upload_method'     => 'nullable|in:POST,PUT',
            'document_upload_field_name' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $now      = Carbon::now();
            $lenderId = $request->integer('lender_id');

            DB::connection("mysql_{$clientId}")->table('crm_lender')
                ->where('id', $lenderId)
                ->update([
                    'api_name'         => $request->input('api_name'),
                    'auth_type'        => $request->input('auth_type', 'none'),
                    'auth_credentials' => json_encode($request->input('auth_credentials', [])),
                    'base_url'         => rtrim($request->input('base_url'), '/'),
                    'endpoint_path'    => ltrim($request->input('endpoint_path', ''), '/'),
                    'request_method'   => $request->input('request_method', 'POST'),
                    'default_headers'  => json_encode($request->input('default_headers', [])),
                    'payload_mapping'  => json_encode($request->input('payload_mapping', [])),
                    'response_mapping' => json_encode($request->input('response_mapping', [])),
                    'retry_attempts'             => $request->input('retry_attempts', 3),
                    'timeout_seconds'            => $request->input('timeout_seconds', 30),
                    'api_status'                 => '1',
                    'api_notes'                  => $request->input('api_notes'),
                    'required_fields'            => json_encode($request->input('required_fields', [])),
                    'resubmit_method'            => $request->input('resubmit_method'),
                    'resubmit_endpoint_path'     => $request->input('resubmit_endpoint_path'),
                    'document_upload_enabled'    => $request->boolean('document_upload_enabled', false),
                    'document_upload_endpoint'   => $request->input('document_upload_endpoint'),
                    'document_upload_method'     => $request->input('document_upload_method', 'POST'),
                    'document_upload_field_name' => $request->input('document_upload_field_name', 'file'),
                    'api_url'          => rtrim($request->input('base_url'), '/') . '/' . ltrim($request->input('endpoint_path', ''), '/'),
                    'lender_api_type'  => $request->input('lender_api_type', ''),
                    'api_username'     => $request->input('auth_credentials.username', ''),
                    'api_password'     => $request->input('auth_credentials.password', ''),
                    'api_key'          => $request->input('auth_credentials.key', ''),
                    'updated_at'       => $now,
                ]);

            $config = DB::connection("mysql_{$clientId}")->table('crm_lender')->where('id', $lenderId)->first();
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
            'retry_attempts'             => 'nullable|integer|min:1|max:10',
            'timeout_seconds'            => 'nullable|integer|min:5|max:300',
            'api_status'                 => 'sometimes|in:0,1',
            'api_notes'                  => 'nullable|string',
            'required_fields'            => 'nullable|array',
            'required_fields.*'          => 'string',
            'resubmit_method'            => 'nullable|in:PUT,PATCH',
            'resubmit_endpoint_path'     => 'nullable|string|max:500',
            'document_upload_enabled'    => 'nullable|boolean',
            'document_upload_endpoint'   => 'nullable|string|max:500',
            'document_upload_method'     => 'nullable|in:POST,PUT',
            'document_upload_field_name' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $existing = DB::connection("mysql_{$clientId}")->table('crm_lender')->where('id', $id)->first();
            if (!$existing) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }

            $update = ['updated_at' => Carbon::now()];

            $scalars = [
                'api_name', 'auth_type', 'base_url', 'endpoint_path', 'request_method',
                'retry_attempts', 'timeout_seconds', 'api_status', 'api_notes', 'lender_api_type',
                'resubmit_method', 'resubmit_endpoint_path',
                'document_upload_enabled', 'document_upload_endpoint',
                'document_upload_method', 'document_upload_field_name',
            ];
            foreach ($scalars as $field) {
                if ($request->has($field)) {
                    $update[$field] = $request->input($field);
                }
            }

            foreach (['auth_credentials', 'default_headers', 'payload_mapping', 'response_mapping', 'required_fields'] as $jsonField) {
                if ($request->has($jsonField)) {
                    $update[$jsonField] = json_encode($request->input($jsonField));
                }
            }

            // Keep legacy columns in sync
            if (isset($update['base_url']) || isset($update['endpoint_path'])) {
                $base = rtrim($update['base_url'] ?? $existing->base_url ?? $existing->api_url ?? '', '/');
                $path = ltrim($update['endpoint_path'] ?? $existing->endpoint_path ?? '', '/');
                $update['api_url'] = $path ? "{$base}/{$path}" : $base;
            }

            DB::connection("mysql_{$clientId}")->table('crm_lender')->where('id', $id)->update($update);

            $config = DB::connection("mysql_{$clientId}")->table('crm_lender')->where('id', $id)->first();
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
            $updated = DB::connection("mysql_{$clientId}")->table('crm_lender')
                ->where('id', $id)
                ->update([
                    'api_status' => '0',
                    'api_name' => null,
                    'auth_type' => 'none',
                    'auth_credentials' => null,
                    'base_url' => null,
                    'endpoint_path' => null,
                    'payload_mapping' => null,
                    'response_mapping' => null,
                    'required_fields' => null,
                    'api_notes' => null,
                ]);
            if (!$updated) {
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
            $row = DB::connection("mysql_{$clientId}")->table('crm_lender')->where('id', $id)->first();
            if (!$row) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }
            $newStatus = $row->api_status === '1' ? '0' : '1';
            DB::connection("mysql_{$clientId}")->table('crm_lender')
                ->where('id', $id)
                ->update(['api_status' => $newStatus, 'updated_at' => Carbon::now()]);
            return $this->successResponse('Status updated', ['api_status' => $newStatus]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to toggle', [$e->getMessage()], $e, 500);
        }
    }

    // ── Test a config with sample data (dry-run) ───────────────────────────────

    public function testConfig(Request $request, int $id)
    {
        $clientId = $request->auth->parent_id;

        try {
            $config = Lender::on("mysql_{$clientId}")->find($id);
            if (!$config) {
                return $this->failResponse('Configuration not found', [], null, 404);
            }

            // Use provided sample data or empty array
            $sampleData = $request->input('sample_data', []);

            $svc     = new LenderApiService();
            $payload = $svc->buildPayload($config, $sampleData);

            // Validate required fields against sample data
            $requiredFields  = $config->required_fields ?? [];
            $missingRequired = [];
            if (!empty($requiredFields)) {
                foreach ($requiredFields as $fieldKey) {
                    $val = $sampleData[$fieldKey] ?? null;
                    if ($val === null || $val === '') {
                        $missingRequired[] = $fieldKey;
                    }
                }
            }

            // Return what would be sent — no actual HTTP call
            return $this->successResponse('Dry-run payload preview', [
                'url'              => $config->fullUrl(),
                'method'           => $config->request_method,
                'auth_type'        => $config->auth_type,
                'computed_payload' => $payload,
                'default_headers'  => $config->default_headers,
                'required_fields_validation' => [
                    'required'   => $requiredFields,
                    'missing'    => $missingRequired,
                    'all_present' => empty($missingRequired),
                ],
                'resubmit_config' => [
                    'method'        => $config->resubmit_method,
                    'endpoint_path' => $config->resubmit_endpoint_path,
                    'configured'    => !empty($config->resubmit_method) && !empty($config->resubmit_endpoint_path),
                ],
                'document_upload_config' => [
                    'enabled'    => (bool) $config->document_upload_enabled,
                    'endpoint'   => $config->document_upload_endpoint,
                    'method'     => $config->document_upload_method ?: 'POST',
                    'field_name' => $config->document_upload_field_name ?: 'file',
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Test failed', [$e->getMessage()], $e, 500);
        }
    }

    // ── API Logs ───────────────────────────────────────────────────────────────

    /**
     * GET /crm/lender-api-logs
     *
     * Queryable params:
     *   lender_id, lead_id, crm_lender_api_id, status, date_from, date_to — exact/range filters
     *   lender      — LIKE match on lender_name
     *   api_name    — LIKE match on api_name
     *   lender_type — exact match on l.lender_api_type (ondeck, lendini, credibly, etc.)
     *   search      — LIKE across lead_id, request_url, request_payload, response_body, status, error_message
     *   page, per_page
     */
    public function logs(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";

            if (!\Illuminate\Support\Facades\Schema::connection($conn)->hasTable('crm_lender_api_logs')) {
                return $this->successResponse('Lender API logs', [
                    'data' => [], 'total' => 0, 'page' => 1, 'per_page' => 15, 'last_page' => 1,
                    '_note' => 'Run migrations to create crm_lender_api_logs table.',
                ]);
            }

            $perPage = min((int) $request->input('per_page', 15), 100);

            $query = DB::connection($conn)
                ->table('crm_lender_api_logs as lg')
                ->leftJoin('crm_lender as l', 'l.id', '=', 'lg.lender_id')
                ->select('lg.*', 'l.lender_name', 'l.api_name')
                ->orderBy('lg.id', 'desc')
                ->when($request->filled('lender_id'), fn ($q) =>
                    $q->where('lg.lender_id', $request->integer('lender_id'))
                )
                ->when($request->filled('lead_id'), fn ($q) =>
                    $q->where('lg.lead_id', $request->integer('lead_id'))
                )
                ->when($request->filled('crm_lender_api_id'), fn ($q) =>
                    $q->where('lg.crm_lender_api_id', $request->integer('crm_lender_api_id'))
                )
                ->when($request->filled('status'), fn ($q) =>
                    $q->where('lg.status', $request->input('status'))
                )
                ->when($request->filled('date_from'), fn ($q) =>
                    $q->where('lg.created_at', '>=', $request->input('date_from'))
                )
                ->when($request->filled('date_to'), fn ($q) =>
                    $q->where('lg.created_at', '<=', $request->input('date_to') . ' 23:59:59')
                )
                ->when($request->filled('lender'), fn ($q) =>
                    $q->where('l.lender_name', 'like', '%' . $request->input('lender') . '%')
                )
                ->when($request->filled('api_name'), fn ($q) =>
                    $q->where('l.api_name', 'like', '%' . $request->input('api_name') . '%')
                )
                ->when($request->filled('lender_type'), fn ($q) =>
                    $q->where('l.lender_api_type', $request->input('lender_type'))
                )
                ->when($request->filled('search'), function ($q) use ($request) {
                    $term = '%' . $request->input('search') . '%';
                    $q->where(function ($inner) use ($term) {
                        $inner->where('lg.lead_id',         'like', $term)
                              ->orWhere('lg.request_url',   'like', $term)
                              ->orWhere('lg.request_payload', 'like', $term)
                              ->orWhere('lg.response_body', 'like', $term)
                              ->orWhere('lg.status',        'like', $term)
                              ->orWhere('lg.error_message', 'like', $term);
                    });
                });

            $paginator = $query->paginate($perPage);

            $records = collect($paginator->items())->map(function ($r) {
                $row = (array) $r;
                foreach (['error_json', 'fix_suggestions'] as $col) {
                    if (isset($row[$col]) && is_string($row[$col])) {
                        $decoded = json_decode($row[$col], true);
                        $row[$col] = is_array($decoded) ? $decoded : null;
                    }
                }
                $row['is_fixable'] = (bool) ($row['is_fixable'] ?? false);
                return $row;
            })->values();

            return $this->successResponse('Lender API logs', [
                'data'      => $records,
                'total'     => $paginator->total(),
                'page'      => $paginator->currentPage(),
                'per_page'  => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
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

            $data = (array) $row;
            foreach (['error_json', 'fix_suggestions'] as $col) {
                if (isset($data[$col]) && is_string($data[$col])) {
                    $decoded = json_decode($data[$col], true);
                    $data[$col] = is_array($decoded) ? $decoded : null;
                }
            }
            $data['is_fixable'] = (bool) ($data['is_fixable'] ?? false);
            return $this->successResponse('Log detail', $data);
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
            'field_key'    => 'required|string|max:200',
            'new_value'    => 'required|string|max:1000',
            'lender_field' => 'nullable|string|max:500',
            'lender_id'    => 'nullable|integer',
            'resubmit'     => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $fieldKey    = $request->input('field_key');
            $newValue    = $request->input('new_value');
            $lenderField = $request->input('lender_field'); // original lender dot-path e.g. "owners.0.homeAddress.state"
            $resubmit    = $request->boolean('resubmit', false);
            $lenderId    = $request->integer('lender_id', 0);
            $conn        = "mysql_{$clientId}";

            // ── Resolve actual CRM key from payload_mapping ───────────────────
            // The heuristic crm_key from FixSuggestionService might not match
            // the key used in the lender API's payload_mapping.  Reverse-look up
            // the mapping to find the CRM key that feeds into $lenderField.
            if ($lenderField && $lenderId > 0) {
                $apiConfig = DB::connection($conn)
                    ->table('crm_lender')
                    ->where('id', $lenderId)
                    ->where('api_status', '1')
                    ->first();

                if ($apiConfig && !empty($apiConfig->payload_mapping)) {
                    $mapping = is_string($apiConfig->payload_mapping)
                        ? json_decode($apiConfig->payload_mapping, true)
                        : (array) $apiConfig->payload_mapping;

                    if (is_array($mapping)) {
                        foreach ($mapping as $crmKey => $lenderPath) {
                            $paths = is_array($lenderPath) ? $lenderPath : [$lenderPath];
                            if (in_array($lenderField, $paths, true)) {
                                $fieldKey = $crmKey; // override with the correct CRM key
                                break;
                            }
                        }
                    }
                }
            }

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
                    ->table('crm_lender')
                    ->where('id', $lenderId)
                    ->where('api_status', '1')
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
            $config = Lender::on("mysql_{$clientId}")
                ->where('id', $lenderId)
                ->where('api_status', '1')
                ->first();

            if (!$config) {
                return $this->failResponse('No active API configuration found for this lender', [], null, 404);
            }

            // ── Pre-dispatch validation ───────────────────────────────────────
            // Load lead data and check required fields are present before queuing.
            // This gives the user an immediate error instead of a silent timeout.
            $svc      = new LenderApiService();
            $leadData = $svc->resolveLeadData((string) $clientId, $leadId);

            if (empty($leadData)) {
                return $this->failResponse(
                    'Lead has no data. Fill in the lead fields before submitting.',
                    [], null, 422
                );
            }

            $mapping = $config->payload_mapping;
            if (!empty($mapping) && is_array($mapping)) {
                $missingFields = [];
                $totalMapped   = 0;

                foreach ($mapping as $crmKey => $lenderPath) {
                    if (str_starts_with((string) $crmKey, '=')) continue; // static literal value
                    $totalMapped++;
                    $value = $leadData[$crmKey] ?? null;
                    if ($value === null || $value === '') {
                        $paths           = is_array($lenderPath) ? $lenderPath : [$lenderPath];
                        $missingFields[] = $paths[0];
                    }
                }

                // Block if ALL mapped fields are empty — nothing useful will be sent
                if ($totalMapped > 0 && count($missingFields) === $totalMapped) {
                    return response()->json([
                        'success'        => false,
                        'message'        => 'Lead is missing all required fields for this lender. Fill in the lead data first.',
                        'missing_fields' => array_values(array_slice($missingFields, 0, 10)),
                    ], 422);
                }
            }

            dispatch(new DispatchLenderApiJob($clientId, $leadId, $lenderId, $userId))
                ->onConnection('redis')
                ->onQueue('default');

            return $this->successResponse('Lead submitted to lender API', [
                'lead_id'   => $leadId,
                'lender_id' => $lenderId,
                'api_name'  => $config->api_name ?: $config->lender_api_type,
                'queued_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to queue API call', [$e->getMessage()], $e, 500);
        }
    }
}
