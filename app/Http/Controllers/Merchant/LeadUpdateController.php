<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Services\MerchantLeadUpdateService;
use App\Services\CrmLeadDuplicateCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant Lead Update Controller
 *
 * Endpoint: POST /api/merchant/lead/update/{token}
 *
 * All routes in this controller are TOKEN-protected (no JWT required).
 * The token in the URL uniquely identifies the lead and tenant.
 */
class LeadUpdateController extends Controller
{
    public function __construct(
        private readonly MerchantLeadUpdateService $svc
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/merchant/lead/{token}
    // Returns current lead data visible to the merchant.
    // ─────────────────────────────────────────────────────────────────────────

    public function show(Request $request, string $token): JsonResponse
    {
        try {
            [$lead, $clientId] = $this->svc->resolveByToken($token);

            return response()->json([
                'success'        => true,
                'data'           => [
                    'lead'           => $this->svc->getLeadData($lead, $clientId),
                    'allowed_fields' => $this->svc->getAllowedFields(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(
                ['success' => false, 'message' => $e->getMessage()],
                $e->getCode() ?: 400
            );
        } catch (\Throwable $e) {
            return response()->json(
                ['success' => false, 'message' => 'Unable to load lead data.'],
                500
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/merchant/lead/update/{token}
    // Apply field-level updates with full audit trail.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update a lead via merchant token.
     *
     * Request body (JSON):
     * {
     *   "merchant_id": 42,          // optional — merchant DB ID for attribution
     *   "first_name":  "John",
     *   "last_name":   "Doe",
     *   "email":       "john@example.com",
     *   "phone":       "9999999999",
     *   "business_name": "Acme LLC",
     *   "annual_revenue": "250000",
     *   ...any field from the allowed list...
     * }
     *
     * Response 200:
     * {
     *   "success": true,
     *   "message": "Lead updated successfully.",
     *   "data": {
     *     "changed_fields": ["first_name", "phone"],
     *     "skipped_fields": ["email"]   // unchanged or not allowed
     *   }
     * }
     */
    public function update(Request $request, string $token): JsonResponse
    {
        // ── Basic payload validation ──────────────────────────────────────────
        $this->validate($request, [
            'merchant_id' => 'nullable|integer|min:1',
        ]);

        if ($request->isJson() === false && empty($request->all())) {
            return response()->json(
                ['success' => false, 'message' => 'Request body must not be empty.'],
                400
            );
        }

        try {
            // ── Resolve lead ──────────────────────────────────────────────────
            [$lead, $clientId] = $this->svc->resolveByToken($token);

            $merchantId = $request->input('merchant_id');
            $ip         = $request->ip() ?? '';
            $payload    = $request->except(['merchant_id', '_token', '_method']);

            // ── Duplicate lead check — only on changed identity fields ────────
            $dupErrors = CrmLeadDuplicateCheckService::forClient((int) $clientId)
                ->checkChanged($lead->id, $payload);
            if (!empty($dupErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate lead detected.',
                    'errors'  => $dupErrors,
                ], 422);
            }

            // ── Apply update ──────────────────────────────────────────────────
            $result = $this->svc->applyUpdate($lead, $clientId, $payload, $merchantId, $ip);

            if (empty($result['changed_fields'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'No changes detected — all submitted values match existing data.',
                    'data'    => $result,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Lead updated successfully.',
                'data'    => $result,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(
                ['success' => false, 'message' => $e->getMessage()],
                $e->getCode() ?: 400
            );
        } catch (\Throwable $e) {
            return response()->json(
                ['success' => false, 'message' => 'Failed to update lead. Please try again.'],
                500
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/merchant/lead/{token}/logs
    // Returns audit log entries for the merchant's lead.
    // ─────────────────────────────────────────────────────────────────────────

    public function logs(Request $request, string $token): JsonResponse
    {
        try {
            [$lead, $clientId] = $this->svc->resolveByToken($token);

            $logs = \Illuminate\Support\Facades\DB::connection("mysql_{$clientId}")
                ->table('crm_lead_logs')
                ->where('lead_id', $lead->id)
                ->orderBy('id', 'desc')
                ->limit(200)
                ->get();

            return response()->json(['success' => true, 'data' => $logs]);
        } catch (\RuntimeException $e) {
            return response()->json(
                ['success' => false, 'message' => $e->getMessage()],
                $e->getCode() ?: 400
            );
        } catch (\Throwable $e) {
            return response()->json(
                ['success' => false, 'message' => 'Unable to load audit logs.'],
                500
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/merchant/lead/{token}/notes
    // Returns auto-generated notes for the merchant's lead.
    // ─────────────────────────────────────────────────────────────────────────

    public function notes(Request $request, string $token): JsonResponse
    {
        try {
            [$lead, $clientId] = $this->svc->resolveByToken($token);

            $notes = \Illuminate\Support\Facades\DB::connection("mysql_{$clientId}")
                ->table('crm_lead_notes')
                ->where('lead_id', $lead->id)
                ->orderBy('id', 'desc')
                ->limit(200)
                ->get();

            return response()->json(['success' => true, 'data' => $notes]);
        } catch (\RuntimeException $e) {
            return response()->json(
                ['success' => false, 'message' => $e->getMessage()],
                $e->getCode() ?: 400
            );
        } catch (\Throwable $e) {
            return response()->json(
                ['success' => false, 'message' => 'Unable to load notes.'],
                500
            );
        }
    }
}
