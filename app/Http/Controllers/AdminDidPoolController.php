<?php

namespace App\Http\Controllers;

use App\Model\Master\DidPool;
use App\Services\DidPoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * System-admin DID pool management.
 *
 * All routes sit behind ['jwt.auth', 'auth.superadmin', 'audit.log'] middleware.
 */
class AdminDidPoolController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    //  List
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /admin/did-pool
     */
    public function index(Request $request)
    {
        $perPage  = min(100, max(1, (int) $request->input('per_page', 25)));
        $page     = max(1, (int) $request->input('page', 1));
        $status   = $request->input('status', '');
        $search   = $request->input('search', '');
        $provider = $request->input('provider', '');
        $clientId = $request->input('client_id', '');

        $query = DB::connection('master')->table('did_pool')
            ->orderByDesc('id');

        if ($status) {
            $query->where('status', $status);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone_number', 'like', '%' . $search . '%')
                  ->orWhere('area_code', 'like', '%' . $search . '%');
            });
        }
        if ($provider) {
            $query->where('provider', $provider);
        }
        if ($clientId) {
            $query->where('assigned_client_id', (int) $clientId);
        }

        $total = (clone $query)->count();
        $dids  = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        // Attach client company names for assigned DIDs
        $clientIds = $dids->pluck('assigned_client_id')->filter()->unique()->toArray();
        $clients   = [];
        if (!empty($clientIds)) {
            $clients = DB::connection('master')->table('clients')
                ->whereIn('id', $clientIds)
                ->pluck('company_name', 'id')
                ->toArray();
        }

        $result = $dids->map(function ($did) use ($clients) {
            $did->client_name = $clients[$did->assigned_client_id] ?? null;
            return $did;
        });

        return $this->successResponse('OK', [
            'dids'         => $result->toArray(),
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Stats
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /admin/did-pool/stats
     */
    public function stats()
    {
        $svc = new DidPoolService();
        return $this->successResponse('OK', $svc->getStats());
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Add single DID
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /admin/did-pool
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:20',
            'provider'     => 'sometimes|string|max:30',
            'provider_sid' => 'sometimes|string|max:80',
            'country_code' => 'sometimes|string|max:5',
            'number_type'  => 'sometimes|string|in:local,toll_free,mobile',
            'area_code'    => 'sometimes|string|max:10',
            'notes'        => 'sometimes|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Duplicate check
        $normalized = preg_replace('/[^0-9]/', '', $request->input('phone_number'));
        $exists = DB::connection('master')->table('did_pool')
            ->where('phone_number', 'like', '%' . $normalized . '%')
            ->exists();

        if ($exists) {
            return $this->failResponse('This phone number already exists in the pool.', [], null, 422);
        }

        try {
            $svc = new DidPoolService();
            $did = $svc->addDid(array_merge(
                $request->only(['phone_number', 'provider', 'provider_sid', 'country_code', 'number_type', 'area_code', 'notes']),
                ['admin_user_id' => $request->auth->id]
            ));

            return $this->successResponse('DID added to pool.', (array) $did);
        } catch (\InvalidArgumentException $e) {
            return $this->failResponse($e->getMessage(), [], null, 422);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Bulk Import
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /admin/did-pool/bulk-import
     */
    public function bulkImport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'numbers'      => 'required|array|min:1|max:1000',
            'numbers.*'    => 'required|string|max:20',
            'provider'     => 'sometimes|string|max:30',
            'country_code' => 'sometimes|string|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $svc    = new DidPoolService();
        $result = $svc->bulkImport(
            $request->input('numbers'),
            $request->only(['provider', 'country_code']),
            $request->auth->id
        );

        Log::info('Admin bulk imported DIDs', [
            'admin_id' => $request->auth->id,
            'imported' => $result['imported'],
            'skipped'  => $result['skipped'],
        ]);

        return $this->successResponse("Imported {$result['imported']} DID(s).", $result);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Assign / Release / Block / Unblock
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /admin/did-pool/{id}/assign
     */
    public function assign(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Verify client exists
        $client = DB::connection('master')->table('clients')
            ->where('id', $request->input('client_id'))
            ->first();

        if (!$client) {
            return $this->failResponse('Client not found.', [], null, 404);
        }

        try {
            $svc = new DidPoolService();
            $svc->adminAssign($id, (int) $request->input('client_id'), $request->auth->id);
            return $this->successResponse('DID assigned successfully.');
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], null, 422);
        }
    }

    /**
     * POST /admin/did-pool/{id}/release
     */
    public function release(Request $request, int $id)
    {
        try {
            $svc = new DidPoolService();
            $svc->adminRelease($id, $request->auth->id);
            return $this->successResponse('DID released. 24-hour cooldown started.');
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], null, 422);
        }
    }

    /**
     * POST /admin/did-pool/{id}/block
     */
    public function block(Request $request, int $id)
    {
        try {
            $svc = new DidPoolService();
            $svc->blockDid($id, $request->auth->id, $request->input('reason', ''));
            return $this->successResponse('DID blocked.');
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], null, 422);
        }
    }

    /**
     * POST /admin/did-pool/{id}/unblock
     */
    public function unblock(Request $request, int $id)
    {
        try {
            $svc = new DidPoolService();
            $svc->unblockDid($id, $request->auth->id);
            return $this->successResponse('DID unblocked.');
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], null, 422);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Audit trail
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /admin/did-pool/{id}/audit
     */
    public function audit(int $id)
    {
        $entries = DB::connection('master')->table('did_pool_audit')
            ->where('did_pool_id', $id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->successResponse('OK', ['audit' => $entries->toArray()]);
    }
}
