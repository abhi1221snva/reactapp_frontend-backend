<?php

namespace App\Http\Controllers;

use App\Models\Client\EmailLenderConversation;
use App\Services\LenderEmailIntelligenceService;
use Illuminate\Http\Request;

class LenderEmailController extends Controller
{
    /**
     * POST /lender-email/scan
     * Trigger a manual scan of Gmail for lender emails.
     */
    public function scan(Request $request)
    {
        $userId   = (int) $request->auth->id;
        $clientId = $this->tenantId($request);
        $query    = $request->input('query');
        $max      = (int) ($request->input('max_results', 50));

        try {
            $service = new LenderEmailIntelligenceService();
            $result  = $service->scanLenderEmails($userId, $clientId, $query, min($max, 100));

            return $this->successResponse('Lender email scan complete.', $result);
        } catch (\Throwable $e) {
            return $this->failResponse('Scan failed.', [], $e);
        }
    }

    /**
     * GET /lender-email/conversations
     * Paginated list of lender conversations with optional filters.
     */
    public function conversations(Request $request)
    {
        $conn      = $this->tenantDb($request);
        $clientId  = $this->tenantId($request);
        $leadId    = $request->input('lead_id') ? (int) $request->input('lead_id') : null;
        $lenderId  = $request->input('lender_id') ? (int) $request->input('lender_id') : null;
        $search    = $request->input('search');
        $perPage   = (int) ($request->input('per_page', 20));

        $offerDetected = null;
        if ($request->has('offer_detected')) {
            $offerDetected = filter_var($request->input('offer_detected'), FILTER_VALIDATE_BOOLEAN);
        }

        try {
            $service = new LenderEmailIntelligenceService();
            $result  = $service->getConversations($conn, $leadId, $lenderId, $offerDetected, $search, min($perPage, 100));

            return $this->successResponse('Conversations retrieved.', [
                'conversations' => $result->items(),
                'total'         => $result->total(),
                'per_page'      => $result->perPage(),
                'current_page'  => $result->currentPage(),
                'last_page'     => $result->lastPage(),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to fetch conversations.', [], $e);
        }
    }

    /**
     * GET /lender-email/conversations/{id}
     */
    public function showConversation(Request $request, $id)
    {
        $conn = $this->tenantDb($request);

        $conversation = EmailLenderConversation::on($conn)->find((int) $id);

        if (!$conversation) {
            return $this->failResponse('Conversation not found.', [], null, 404);
        }

        return $this->successResponse('Conversation retrieved.', $conversation->toArray());
    }

    /**
     * GET /lender-email/stats
     * Summary statistics for current user.
     */
    public function stats(Request $request)
    {
        $conn   = $this->tenantDb($request);
        $userId = (int) $request->auth->id;

        try {
            $service = new LenderEmailIntelligenceService();
            $stats   = $service->getStats($conn, $userId);

            return $this->successResponse('Stats retrieved.', $stats);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to fetch stats.', [], $e);
        }
    }

    /**
     * GET /lender-email/lead/{leadId}
     * All conversations for a specific lead.
     */
    public function leadConversations(Request $request, $leadId)
    {
        $conn = $this->tenantDb($request);

        try {
            $service       = new LenderEmailIntelligenceService();
            $conversations = $service->getConversationsForLead($conn, (int) $leadId);

            return $this->successResponse('Lead conversations retrieved.', [
                'conversations' => $conversations->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to fetch lead conversations.', [], $e);
        }
    }
}
