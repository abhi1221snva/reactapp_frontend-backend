<?php

namespace App\Http\Controllers;

use App\Services\PublicApplicationService;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CompanyDetailController
 *
 * Reads/writes company settings from crm_system_setting (client DB).
 * Also manages affiliate link generation for CRM users.
 * ALL routes are JWT-protected.
 */
class CompanyDetailController extends Controller
{
    private PublicApplicationService $svc;

    public function __construct(PublicApplicationService $svc)
    {
        $this->svc = $svc;
    }

    // ── Helper: load crm_system_setting row for client ────────────────────────
    private function getSystemSetting(int $clientId): ?object
    {
        return DB::connection("mysql_{$clientId}")
            ->table('crm_system_setting')
            ->orderBy('id')
            ->first();
    }

    // ── Helper: resolve logo URL ──────────────────────────────────────────────
    private function resolveLogoUrl(?string $logo, int $clientId = 0): ?string
    {
        if (empty($logo)) return null;
        // Already a full URL
        if (str_starts_with($logo, 'http')) return $logo;
        // New: serve via tenant file endpoint (storage/app/clients/)
        if ($clientId > 0) {
            $tenantPath = TenantStorageService::getPath($clientId, 'company') . '/' . $logo;
            if (file_exists($tenantPath)) {
                return TenantStorageService::getLogoUrl($clientId, $logo);
            }
        }
        // Legacy fallback: public/logo/
        return rtrim(env('APP_URL'), '/') . '/logo/' . $logo;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // COMPANY SETTINGS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /crm/company-settings
     */
    public function getSettings(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $setting  = $this->getSystemSetting($clientId);

            if (!$setting) {
                // Return empty defaults — no record yet
                return $this->successResponse('Company settings', [
                    'id'              => null,
                    'company_name'    => '',
                    'company_email'   => '',
                    'company_phone'   => '',
                    'company_address' => '',
                    'state'           => '',
                    'city'            => '',
                    'zipcode'         => '',
                    'logo'            => null,
                    'logo_url'        => null,
                    'company_domain'  => '',
                    'affiliate_url_example' => null,
                    'merchant_url_example'  => null,
                ]);
            }

            $websiteUrl = $setting->company_domain ?? '';

            return $this->successResponse('Company settings', [
                'id'              => $setting->id,
                'company_name'    => $setting->company_name    ?? '',
                'company_email'   => $setting->company_email   ?? '',
                'company_phone'   => $setting->company_phone   ?? '',
                'company_address' => $setting->company_address ?? '',
                'state'           => $setting->state           ?? '',
                'city'            => $setting->city            ?? '',
                'zipcode'         => $setting->zipcode         ?? '',
                'logo'            => $setting->logo            ?? null,
                'logo_url'        => $this->resolveLogoUrl($setting->logo ?? null, $clientId),
                'company_domain'  => $websiteUrl,
                // Convenience alias fields
                'website_url'     => $websiteUrl,
                'support_email'   => $setting->company_email ?? '',
                'affiliate_url_example' => $websiteUrl
                    ? rtrim($websiteUrl, '/') . '/apply/{your_code}'
                    : null,
                'merchant_url_example' => $websiteUrl
                    ? rtrim($websiteUrl, '/') . '/merchant/customer/app/index/{client_id}/{lead_id}/{lead_token}'
                    : null,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to load company settings.', [$e->getMessage()], $e);
        }
    }

    /**
     * PUT /crm/company-settings
     * Upsert into crm_system_setting.
     */
    public function updateSettings(Request $request)
    {
        $this->validate($request, [
            'company_name'    => 'nullable|string|max:255',
            'company_email'   => 'nullable|email|max:255',
            'company_phone'   => 'nullable|string|max:50',
            'company_address' => 'nullable|string|max:500',
            'state'           => 'nullable|string|max:100',
            'city'            => 'nullable|string|max:100',
            'zipcode'         => 'nullable|string|max:20',
            'company_domain'  => 'nullable|string|max:255',
            'logo'            => 'nullable|string|max:500',
        ]);

        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";
            $existing = $this->getSystemSetting($clientId);

            $fields   = ['company_name','company_email','company_phone','company_address','state','city','zipcode','company_domain','logo'];
            $data     = [];
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $data[$field] = $request->input($field);
                }
            }
            $data['updated_at'] = now();

            if ($existing) {
                DB::connection($conn)->table('crm_system_setting')->where('id', $existing->id)->update($data);
            } else {
                $data['created_at'] = now();
                DB::connection($conn)->table('crm_system_setting')->insert($data);
            }

            return $this->successResponse('Company settings updated.');
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to update settings.', [$e->getMessage()], $e);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // LOGO UPLOAD / DELETE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * POST /crm/company-settings/logo
     *
     * Saves to:  storage/app/clients/client_{id}/company/logo.{ext}
     * Served via: GET /public/tenant/{clientId}/logo (no auth)
     */
    public function uploadLogo(Request $request)
    {
        $this->validate($request, [
            'logo' => 'required|file|mimes:jpeg,jpg,png,gif,webp,svg|max:2048',
        ]);

        try {
            $clientId = (int) $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";
            $file     = $request->file('logo');

            // Ensure tenant storage exists
            TenantStorageService::ensureDirectories($clientId);

            // Remove old logo from tenant storage (if local file)
            $existing = $this->getSystemSetting($clientId);
            if ($existing && !empty($existing->logo) && !str_starts_with($existing->logo, 'http')) {
                TenantStorageService::deleteLogo($clientId, $existing->logo);
            }

            // Store new logo in tenant company folder
            $filename = TenantStorageService::storeLogo($clientId, $file);

            // Upsert crm_system_setting
            $now = now();
            if ($existing) {
                DB::connection($conn)->table('crm_system_setting')
                    ->where('id', $existing->id)
                    ->update(['logo' => $filename, 'updated_at' => $now]);
            } else {
                DB::connection($conn)->table('crm_system_setting')
                    ->insert(['logo' => $filename, 'created_at' => $now, 'updated_at' => $now]);
            }

            return $this->successResponse('Logo uploaded.', [
                'logo'     => $filename,
                'logo_url' => $this->resolveLogoUrl($filename, $clientId),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to upload logo.', [$e->getMessage()], $e);
        }
    }

    /**
     * DELETE /crm/company-settings/logo
     * Removes logo file from tenant storage and clears the DB column.
     */
    public function deleteLogo(Request $request)
    {
        try {
            $clientId = (int) $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";
            $existing = $this->getSystemSetting($clientId);

            if ($existing && !empty($existing->logo)) {
                TenantStorageService::deleteLogo($clientId, $existing->logo);
            }

            if ($existing) {
                DB::connection($conn)->table('crm_system_setting')
                    ->where('id', $existing->id)
                    ->update(['logo' => null, 'updated_at' => now()]);
            }

            return $this->successResponse('Logo removed.');
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to remove logo.', [$e->getMessage()], $e);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AFFILIATE LINK MANAGEMENT
    // ──────────────────────────────────────────────────────────────────────────

    public function getMyAffiliateLink(Request $request)
    {
        try {
            $userId   = $request->auth->id;
            $clientId = $request->auth->parent_id;

            $user = DB::table('users')->where('id', $userId)
                ->select('id', 'first_name', 'last_name', 'email', 'affiliate_code')
                ->first();

            $affiliateUrl = $user->affiliate_code
                ? $this->svc->buildAffiliateUrl($clientId, $user->affiliate_code)
                : null;

            return $this->successResponse('Affiliate link', [
                'affiliate_code' => $user->affiliate_code,
                'affiliate_url'  => $affiliateUrl,
                'has_code'       => !empty($user->affiliate_code),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to load affiliate link.', [$e->getMessage()], $e);
        }
    }

    public function generateMyCode(Request $request)
    {
        $this->validate($request, [
            'custom_code' => 'nullable|string|max:50|regex:/^[a-z0-9_\-]+$/i',
        ]);

        try {
            $userId   = $request->auth->id;
            $clientId = $request->auth->parent_id;
            $user     = DB::table('users')->where('id', $userId)->first();

            if ($request->filled('custom_code')) {
                $code  = strtolower(trim($request->input('custom_code')));
                $taken = DB::table('users')->where('affiliate_code', $code)->where('id', '!=', $userId)->exists();
                if ($taken) {
                    return $this->failResponse('That code is already taken. Please choose another.', [], null, 422);
                }
            } else {
                $code = $this->svc->generateAffiliateCode($user);
            }

            DB::table('users')->where('id', $userId)->update(['affiliate_code' => $code, 'updated_at' => now()]);
            $affiliateUrl = $this->svc->buildAffiliateUrl($clientId, $code);

            return $this->successResponse('Affiliate code generated.', [
                'affiliate_code' => $code,
                'affiliate_url'  => $affiliateUrl,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to generate code.', [$e->getMessage()], $e);
        }
    }

    public function listAffiliateUsers(Request $request)
    {
        try {
            $clientId   = $request->auth->parent_id;
            $conn       = "mysql_{$clientId}";
            $setting    = $this->getSystemSetting($clientId);
            $websiteUrl = $setting->company_domain ?? '';

            $users = DB::table('users')
                ->where('parent_id', $clientId)
                ->where('is_deleted', 0)
                ->select('id', 'first_name', 'last_name', 'email', 'role', 'affiliate_code')
                ->orderBy('first_name')
                ->get();

            $result = $users->map(function ($u) use ($conn, $websiteUrl) {
                $leadCount = 0;
                try {
                    $leadCount = DB::connection($conn)->table('crm_leads')
                        ->where('affiliate_user_id', $u->id)->whereNull('deleted_at')->count();
                } catch (\Throwable $e) {}

                return [
                    'id'              => $u->id,
                    'name'            => trim($u->first_name . ' ' . $u->last_name),
                    'email'           => $u->email,
                    'role'            => $u->role,
                    'affiliate_code'  => $u->affiliate_code,
                    'affiliate_url'   => $u->affiliate_code && $websiteUrl
                        ? rtrim($websiteUrl, '/') . '/apply/' . $u->affiliate_code
                        : null,
                    'leads_generated' => $leadCount,
                ];
            });

            return $this->successResponse('Affiliate users', $result->values()->all());
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to list affiliate users.', [$e->getMessage()], $e);
        }
    }

    public function getLeadMerchantLink(Request $request, int $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";

            $lead = DB::connection($conn)->table('crm_leads')->where('id', $id)->first();
            if (!$lead) {
                return $this->failResponse('Lead not found.', [], null, 404);
            }

            if (empty($lead->lead_token)) {
                $token = \Illuminate\Support\Str::random(32);
                DB::connection($conn)->table('crm_leads')->where('id', $id)->update([
                    'lead_token' => $token, 'unique_token' => $token,
                ]);
                $lead->lead_token = $token;
            }

            $setting     = $this->getSystemSetting($clientId);
            $portalBase  = $this->getPortalBaseUrl($clientId);
            $merchantUrl = $portalBase . '/merchant/customer/app/index/' . $clientId . '/' . $id . '/' . $lead->lead_token;

            // Persist generated URL on the lead
            DB::connection($conn)->table('crm_leads')
                ->where('id', $id)
                ->update(['unique_url' => $merchantUrl, 'unique_token' => $lead->lead_token]);

            \Log::info('[getLeadMerchantLink] generated', [
                'client_id'  => $clientId,
                'lead_id'    => $id,
                'domain'     => $portalBase,
                'url'        => $merchantUrl,
            ]);

            return $this->successResponse('Merchant link', [
                'lead_token'   => $lead->lead_token,
                'merchant_url' => $merchantUrl,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to get merchant link.', [$e->getMessage()], $e);
        }
    }
}
