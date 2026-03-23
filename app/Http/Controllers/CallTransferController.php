<?php

namespace App\Http\Controllers;

use App\Model\Dialer;
use App\Services\CallTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CallTransferController
 *
 * Exposes a single API endpoint that orchestrates the full warm-transfer
 * pipeline in one atomic round-trip:
 *
 *   POST /call-transfer/initiate
 *
 * Internally it runs three sequential steps:
 *   1. validateCall()      — confirm agent has an active call (replaces check-line-details)
 *   2. validateTarget()    — extension online / phone valid   (replaces check-extension-live-for-transfer)
 *   3. initiateTransfer()  — fires the Asterisk AMI command   (replaces warm-call-transfer-c2c-crm)
 *
 * The old three-endpoint frontend flow becomes a single POST, eliminating
 * race conditions and reducing round-trips from 3 to 1.
 */
class CallTransferController extends Controller
{
    /** @var Request */
    private $request;

    /** @var CallTransferService */
    private $transferService;

    public function __construct(Request $request, Dialer $dialer)
    {
        $this->request         = $request;
        $this->transferService = new CallTransferService($dialer);
    }

    // =========================================================================
    // POST /call-transfer/initiate
    // =========================================================================

    /**
     * Initiate a call transfer in a single atomic API call.
     *
     * Request body (extension transfer):
     * {
     *   "lead_id":               123,
     *   "customer_phone_number": "9876543210",
     *   "transfer_type":         "extension",
     *   "target":                "1002",
     *   "campaign_id":           45,
     *   "domain":                "crm"
     * }
     *
     * Request body (external / DID transfer):
     * {
     *   "lead_id":               123,
     *   "customer_phone_number": "9876543210",
     *   "transfer_type":         "external",
     *   "target":                "+919876543210",
     *   "campaign_id":           45,
     *   "domain":                "dialer"
     * }
     *
     * Success response (HTTP 200):
     * {
     *   "status":               "success",
     *   "transfer_session_id":  "a1b2c3d4e5f6a7b8",
     *   "state":                "ringing"
     * }
     *
     * Error response (HTTP 422):
     * {
     *   "status":  "error",
     *   "message": "Extension 1002 is busy."
     * }
     */
    public function initiate(Request $request): JsonResponse
    {
        // -----------------------------------------------------------------
        // Input validation
        // -----------------------------------------------------------------
        $this->validate($this->request, [
            'lead_id'               => 'required|numeric',
            'customer_phone_number' => 'required|string',
            'transfer_type'         => 'required|in:extension,external',
            'target'                => 'required|string',
            'campaign_id'           => 'numeric',
            'domain'                => 'string',
        ]);

        $parentId = (int) $request->auth->parent_id;

        // -----------------------------------------------------------------
        // Pipeline: validate → validate-target → initiate
        // -----------------------------------------------------------------
        try {
            // Step 1: confirm the agent has an active call for this lead
            $this->transferService->validateCall($request, $parentId);

            // Step 2: ensure the transfer destination is reachable
            $this->transferService->validateTarget($request, $parentId);

            // Step 3: fire the AMI command; returns a unique session ID
            $sessionId = $this->transferService->initiateTransfer($request, $parentId);

        } catch (\RuntimeException $e) {
            Log::warning('CallTransferController.initiate.failed', [
                'lead_id'       => $request->lead_id,
                'transfer_type' => $request->transfer_type,
                'target'        => $request->target,
                'reason'        => $e->getMessage(),
                'user_id'       => $request->auth->id ?? null,
                'parent_id'     => $parentId,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        // -----------------------------------------------------------------
        // Success
        // -----------------------------------------------------------------
        Log::info('CallTransferController.initiate.success', [
            'lead_id'             => $request->lead_id,
            'transfer_type'       => $request->transfer_type,
            'target'              => $request->target,
            'transfer_session_id' => $sessionId,
            'user_id'             => $request->auth->id ?? null,
            'parent_id'           => $parentId,
        ]);

        return response()->json([
            'status'              => 'success',
            'transfer_session_id' => $sessionId,
            'state'               => 'ringing',
        ]);
    }
}
