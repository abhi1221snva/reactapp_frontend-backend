<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\CrmMerchantPortal;
use App\Models\Client\CrmLeadRecord;
use Illuminate\Support\Str;

class CrmMerchantPortalController extends Controller
{
    /**
     * POST /crm/lead/{id}/merchant-portal/generate
     * Generate (or regenerate) a merchant portal token for a lead.
     */
    public function generate(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;

            $lead = CrmLeadRecord::on("mysql_$clientId")->findOrFail($id);

            $token = Str::random(40);
            $url   = $this->getPortalBaseUrl($clientId) . '/merchant/customer/app/index/' . $clientId . '/' . $id . '/' . $token;

            // Create new portal record
            $portal = new CrmMerchantPortal();
            $portal->setConnection("mysql_$clientId");
            $portal->lead_id   = $id;
            $portal->client_id = $clientId;
            $portal->token     = $token;
            $portal->url       = $url;
            $portal->status    = 1;
            $portal->saveOrFail();

            // Update crm_leads with token AND plain URL so buildLeadData() and resolveLeadToken() both work.
            $lead->lead_token   = $token;
            $lead->unique_token = $token;
            $lead->unique_url   = $url;
            $lead->save();

            return $this->successResponse("Merchant Portal Link Generated", [
                'portal_id'  => $portal->id,
                'token'      => $token,
                'url'        => $url,
                'expires_at' => null,
                'status'     => 1,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to generate merchant portal link", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/lead/{id}/merchant-portal
     * Get current portal record for a lead.
     */
    public function show(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;

            $portal = CrmMerchantPortal::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->where('status', 1)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$portal) {
                return $this->failResponse("No active merchant portal found for this lead", [], null, 404);
            }

            return $this->successResponse("Merchant Portal", $portal->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load merchant portal", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead/{id}/merchant-portal/{pid}/revoke
     */
    public function revoke(Request $request, $id, $pid)
    {
        try {
            $clientId = $request->auth->parent_id;

            $portal = CrmMerchantPortal::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->findOrFail($pid);

            $portal->status = 0;
            $portal->save();

            return $this->successResponse("Merchant Portal Revoked", $portal->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to revoke merchant portal", [$e->getMessage()], $e, 500);
        }
    }
}
