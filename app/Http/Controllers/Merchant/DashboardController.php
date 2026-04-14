<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Authenticated merchant dashboard endpoints.
 *
 * All routes in this controller require the 'merchant.jwt' middleware.
 */
class DashboardController extends Controller
{
    /**
     * GET /merchant/my-applications
     *
     * Returns every application (crm_lead) associated with the authenticated
     * merchant's email address in their client database.
     */
    public function listApplications(Request $request): JsonResponse
    {
        /** @var \App\Model\Merchant\Merchants $merchant */
        $merchant = $request->attributes->get('merchant');
        $email    = $merchant->email;
        $clientId = $merchant->client_id;
        $conn     = "mysql_{$clientId}";

        try {
            // Find all lead IDs where the EAV email field matches the merchant's email.
            $leadIds = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('field_key', 'email')
                ->where('field_value', $email)
                ->pluck('lead_id')
                ->unique();

            // Always include the directly-linked lead even if EAV email is missing.
            if ($merchant->lead_id) {
                $leadIds = $leadIds->push($merchant->lead_id)->unique();
            }

            if ($leadIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => []]);
            }

            // Fetch the lead base rows.
            $leads = DB::connection($conn)
                ->table('crm_leads')
                ->whereIn('id', $leadIds)
                ->orderBy('created_at', 'desc')
                ->get(['id', 'lead_status', 'lead_token', 'lead_type', 'created_at', 'updated_at']);

            // Batch-fetch EAV values we want to display (business_name, first_name, last_name).
            $eavRows = DB::connection($conn)
                ->table('crm_lead_values')
                ->whereIn('lead_id', $leadIds)
                ->whereIn('field_key', ['business_name', 'first_name', 'last_name'])
                ->get(['lead_id', 'field_key', 'field_value']);

            // Index as [lead_id][field_key] => value.
            $eav = [];
            foreach ($eavRows as $row) {
                $eav[$row->lead_id][$row->field_key] = $row->field_value;
            }

            $applications = $leads->map(function ($lead) use ($eav) {
                $fields = $eav[$lead->id] ?? [];
                return [
                    'id'            => $lead->id,
                    'lead_token'    => $lead->lead_token,
                    'lead_status'   => $lead->lead_status,
                    'lead_type'     => $lead->lead_type,
                    'business_name' => $fields['business_name'] ?? null,
                    'applicant'     => trim(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? '')),
                    'created_at'    => $lead->created_at,
                    'updated_at'    => $lead->updated_at,
                ];
            });

            return response()->json(['success' => true, 'data' => $applications]);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DashboardController::listApplications', [
                'merchant_id' => $merchant->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load applications.',
            ], 500);
        }
    }
}
