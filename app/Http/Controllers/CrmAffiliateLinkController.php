<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\CrmAffiliateLink;
use App\Model\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmAffiliateLinkController extends Controller
{
    /**
     * GET /crm/affiliate-links
     */
    public function list(Request $request)
    {
        try {
            $clientId  = $request->auth->parent_id;
            $userLevel = $request->auth->user_level ?? 0;

            $query = CrmAffiliateLink::on("mysql_$clientId")->where('client_id', $clientId);

            // Agents only see their own links
            if ($userLevel <= 1) {
                $query->where('user_id', $request->auth->id);
            }

            $page    = max(1, (int)$request->input('page', 1));
            $perPage = min((int)$request->input('per_page', 25), 100);
            $status  = $request->input('status');

            if ($status !== null) {
                $query->where('status', $status);
            }

            $total = $query->count();
            $links = $query->orderBy('created_at', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return $this->successResponse("Affiliate Links", [
                'data'         => $links->toArray(),
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int)ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load affiliate links", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * PUT /crm/affiliate-links
     * Generate a new affiliate tracking link.
     */
    public function create(Request $request)
    {
        $this->validate($request, [
            'extension_id' => 'required|string',
        ]);

        try {
            $clientId    = $request->auth->parent_id;
            $extensionId = $request->input('extension_id');
            $token       = Str::random(32);
            $fullPath    = "/{$clientId}/{$extensionId}/{$token}";

            $link = new CrmAffiliateLink();
            $link->setConnection("mysql_$clientId");
            $link->user_id      = $request->auth->id;
            $link->client_id    = $clientId;
            $link->extension_id = $extensionId;
            $link->token        = $token;
            $link->full_path    = $fullPath;
            $link->label        = $request->input('label');
            $link->utm_source   = $request->input('utm_source');
            $link->utm_medium   = $request->input('utm_medium');
            $link->utm_campaign = $request->input('utm_campaign');
            $link->redirect_url = $request->input('redirect_url');
            $link->list_id      = $request->input('list_id');
            $link->expires_at   = $request->input('expires_at');
            $link->status       = 1;
            $link->saveOrFail();

            // Also update master.users.affiliate_link for backward compat
            try {
                User::where('id', $request->auth->id)->update(['affiliate_link' => $fullPath]);
            } catch (\Throwable $e) {}

            return $this->successResponse("Affiliate Link Created", array_merge($link->toArray(), [
                'full_url' => $fullPath,
            ]));
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to create affiliate link", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/affiliate-links/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $link     = CrmAffiliateLink::on("mysql_$clientId")->findOrFail($id);

            foreach (['label', 'utm_source', 'utm_medium', 'utm_campaign', 'redirect_url', 'list_id', 'expires_at'] as $field) {
                if ($request->has($field)) $link->$field = $request->input($field);
            }
            $link->save();

            return $this->successResponse("Affiliate Link Updated", $link->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to update affiliate link", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * DELETE /crm/affiliate-links/{id}
     */
    public function deactivate(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $link     = CrmAffiliateLink::on("mysql_$clientId")->findOrFail($id);
            $link->status = 0;
            $link->save();
            return $this->successResponse("Affiliate Link Deactivated", $link->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to deactivate link", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/affiliate-links/{id}/stats
     */
    public function stats(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $link     = CrmAffiliateLink::on("mysql_$clientId")->findOrFail($id);

            // Count leads created via this link (matched by token in lead_source or unique_token)
            $leadCount = DB::connection("mysql_$clientId")
                ->table('crm_lead_data')
                ->where('is_deleted', 0)
                ->where(function ($q) use ($link) {
                    $q->where('unique_token', $link->token);
                })
                ->count();

            return $this->successResponse("Affiliate Link Stats", [
                'link'          => $link->toArray(),
                'total_clicks'  => $link->total_clicks,
                'total_leads'   => $leadCount,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load stats", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/affiliate/{token}/check  — PUBLIC (no JWT)
     * Backward-compatible check used by AffiliateController flow.
     */
    public function checkByToken(Request $request, $token)
    {
        try {
            // Search across all client databases — match by token
            $databases = DB::select("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'client_%'");
            foreach ($databases as $db) {
                $dbName = $db->SCHEMA_NAME;
                $link   = DB::connection($dbName)->table('crm_affiliate_links')
                    ->where('token', $token)
                    ->where('status', 1)
                    ->first();
                if ($link) {
                    // Increment click count
                    DB::connection($dbName)->table('crm_affiliate_links')
                        ->where('id', $link->id)
                        ->increment('total_clicks');

                    return $this->successResponse("Link Valid", (array)$link);
                }
            }
            return $this->failResponse("Invalid or expired affiliate link", [], null, 404);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to check link", [$e->getMessage()], $e, 500);
        }
    }
}
