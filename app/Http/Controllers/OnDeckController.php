<?php

namespace App\Http\Controllers;

use App\Services\OnDeckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OnDeck Partner API Controller
 *
 * REST endpoints for the OnDeck lead → application → documents → offers → funding lifecycle.
 * All routes require jwt.auth middleware. Tenant context from $request->auth->parent_id.
 */
class OnDeckController extends Controller
{
    private OnDeckService $ondeck;

    public function __construct()
    {
        $this->ondeck = new OnDeckService();
    }

    // ── Dashboard / Local State ──────────────────────────────────────────────

    /**
     * GET /crm/lead/{id}/ondeck
     * Return all stored OnDeck data for a lead (application, docs, offers, logs).
     */
    public function getLocalData(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $data     = $this->ondeck->getLocalData($leadId, $clientId);
            return $this->successResponse('OnDeck data retrieved', $data);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    // ── Application ──────────────────────────────────────────────────────────

    /**
     * POST /crm/lead/{id}/ondeck/application
     * Submit a new application. Body: { submission_type: application|prequalification|preapproval|lead }
     */
    public function submitApplication(Request $request, int $leadId): JsonResponse
    {
        $this->validate($request, [
            'submission_type' => 'in:prequalification,preapproval,application,lead',
        ]);

        try {
            $clientId = $request->auth->parent_id;
            $type     = $request->input('submission_type', 'application');
            $app      = $this->ondeck->submitApplication($leadId, $clientId, $type);
            return $this->successResponse('Application submitted to OnDeck', $app->toArray());
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            return $this->failResponse('Submission failed: ' . $e->getMessage(), [], $e, 500);
        }
    }

    /**
     * PUT /crm/lead/{id}/ondeck/application
     * Update (re-submit) an existing application.
     */
    public function updateApplication(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $app      = $this->ondeck->updateApplication($leadId, $clientId);
            return $this->successResponse('Application updated', $app->toArray());
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            return $this->failResponse('Update failed: ' . $e->getMessage(), [], $e, 500);
        }
    }

    /**
     * PUT /crm/lead/{id}/ondeck/contactable
     * Mark merchant as contactable.
     */
    public function markContactable(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $result   = $this->ondeck->markContactable($leadId, $clientId);
            return $this->successResponse('Merchant marked as contactable', $result);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    // ── Documents ────────────────────────────────────────────────────────────

    /**
     * POST /crm/lead/{id}/ondeck/document
     * Upload a single document. Multipart form: file, document_need (optional).
     */
    public function uploadDocument(Request $request, int $leadId): JsonResponse
    {
        $this->validate($request, [
            'file'          => 'required|file|max:20480',
            'document_need' => 'nullable|string|max:100',
        ]);

        try {
            $clientId    = $request->auth->parent_id;
            $file        = $request->file('file');
            $uploadedPath = $file->store("ondeck_docs/{$clientId}/{$leadId}");
            $doc         = $this->ondeck->uploadDocument(
                $leadId,
                $clientId,
                storage_path('app/' . $uploadedPath),
                $file->getClientOriginalName(),
                $request->input('document_need', '')
            );
            return $this->successResponse('Document uploaded to OnDeck', $doc->toArray());
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            return $this->failResponse('Upload failed: ' . $e->getMessage(), [], $e, 500);
        }
    }

    /**
     * GET /crm/lead/{id}/ondeck/required-documents
     * Fetch required document list from OnDeck.
     */
    public function getRequiredDocuments(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $docs     = $this->ondeck->getRequiredDocuments($leadId, $clientId);
            return $this->successResponse('Required documents retrieved', $docs);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    /**
     * GET /crm/lead/{id}/ondeck/local-documents
     * Return locally stored document records (no OnDeck API call).
     */
    public function getLocalDocuments(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";
            $docs     = DB::connection($conn)
                ->table('lender_documents')
                ->where('lead_id', $leadId)
                ->where('lender_name', 'ondeck')
                ->orderByDesc('created_at')
                ->get();
            return $this->successResponse('Documents retrieved', $docs);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, 500);
        }
    }

    // ── Status ───────────────────────────────────────────────────────────────

    /**
     * GET /crm/lead/{id}/ondeck/status
     * Fetch live status from OnDeck + sync to DB.
     */
    public function getStatus(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $status   = $this->ondeck->getStatus($leadId, $clientId);
            return $this->successResponse('Status retrieved', $status);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    // ── Offers ───────────────────────────────────────────────────────────────

    /**
     * GET /crm/lead/{id}/ondeck/offers
     * Fetch active offers from OnDeck + sync to DB.
     */
    public function getOffers(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $offers   = $this->ondeck->getOffers($leadId, $clientId);
            return $this->successResponse('Offers retrieved', $offers);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    /**
     * GET /crm/lead/{id}/ondeck/local-offers
     * Return locally stored offer records (no OnDeck API call).
     */
    public function getLocalOffers(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";
            $offers   = DB::connection($conn)
                ->table('lender_offers')
                ->where('lead_id', $leadId)
                ->where('lender_name', 'ondeck')
                ->orderBy('loan_amount', 'desc')
                ->get();
            return $this->successResponse('Offers retrieved', $offers);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, 500);
        }
    }

    /**
     * POST /crm/lead/{id}/ondeck/pricing
     * Get pricing breakdown for a specific offer. Body: { offerId, loanAmount?, paymentFrequency?, commissionPoints? }
     */
    public function getPricing(Request $request, int $leadId): JsonResponse
    {
        $this->validate($request, [
            'offerId'          => 'required|string',
            'loanAmount'       => 'nullable|numeric|min:1',
            'paymentFrequency' => 'nullable|in:Daily,Weekly',
            'commissionPoints' => 'nullable|numeric|min:0',
        ]);

        try {
            $clientId = $request->auth->parent_id;
            $params   = $request->only(['offerId', 'loanAmount', 'paymentFrequency', 'commissionPoints']);
            $pricing  = $this->ondeck->getPricing($leadId, $clientId, array_filter($params, fn($v) => $v !== null));
            return $this->successResponse('Pricing retrieved', $pricing);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    /**
     * POST /crm/lead/{id}/ondeck/confirm-offer
     * Accept an offer. Body: { offerId, loanAmount?, paymentFrequency?, commissionPoints? }
     */
    public function confirmOffer(Request $request, int $leadId): JsonResponse
    {
        $this->validate($request, [
            'offerId'          => 'required|string',
            'loanAmount'       => 'nullable|numeric|min:1',
            'paymentFrequency' => 'nullable|in:Daily,Weekly',
            'commissionPoints' => 'nullable|numeric|min:0',
        ]);

        try {
            $clientId = $request->auth->parent_id;
            $params   = $request->only(['offerId', 'loanAmount', 'paymentFrequency', 'commissionPoints']);
            $result   = $this->ondeck->confirmOffer($leadId, $clientId, array_filter($params, fn($v) => $v !== null));
            return $this->successResponse('Offer confirmed! Application is now in closing.', $result);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    // ── Renewals ─────────────────────────────────────────────────────────────

    /**
     * GET /crm/lead/{id}/ondeck/renewal-eligibility
     * Check if the funded loan is eligible for renewal.
     */
    public function getRenewalEligibility(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId   = $request->auth->parent_id;
            $eligibility = $this->ondeck->getRenewalEligibility($leadId, $clientId);
            return $this->successResponse('Renewal eligibility retrieved', $eligibility);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    /**
     * POST /crm/lead/{id}/ondeck/renewal
     * Submit a renewal request for an eligible funded loan.
     */
    public function submitRenewal(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $app      = $this->ondeck->submitRenewal($leadId, $clientId);
            return $this->successResponse('Renewal submitted', $app->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, $e->getCode() ?: 500);
        }
    }

    // ── Logs ─────────────────────────────────────────────────────────────────

    /**
     * GET /crm/lead/{id}/ondeck/logs
     * Return OnDeck API call logs for this lead.
     */
    public function getLogs(Request $request, int $leadId): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";
            $logs     = DB::connection($conn)
                ->table('crm_lender_api_logs')
                ->where('lead_id', $leadId)
                ->where('request_url', 'like', '%ondeck%')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
            return $this->successResponse('Logs retrieved', $logs);
        } catch (\Throwable $e) {
            return $this->failResponse($e->getMessage(), [], $e, 500);
        }
    }
}
