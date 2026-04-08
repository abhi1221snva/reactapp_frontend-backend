<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TenantAware;
use App\Services\LeadVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *      version="2.0",
 *      title="Rocket Dialer API",
 *      description="Multi-tenant cloud contact center / VoIP SaaS REST API. All protected endpoints require a JWT Bearer token obtained from POST /authentication. Access is restricted to system_administrator (level 11) for Swagger UI.",
 *      @OA\Contact(email="admin@rocketdialer.com"),
 *      @OA\License(name="Proprietary")
 * )
 * @OA\Server(
 *      url="/",
 *      description="Rocket Dialer API Server"
 * )
 * @OA\SecurityScheme(
 *   securityScheme="Bearer",
 *   type="http",
 *   scheme="bearer",
 *   bearerFormat="JWT",
 *   description="JWT Bearer token. Obtain from POST /authentication then prefix with 'Bearer '."
 * )
 * @OA\Tag(name="Authentication",     description="Login, logout, OTP, and 2FA operations")
 * @OA\Tag(name="Lead",               description="CRM lead management (create, list, update, delete)")
 * @OA\Tag(name="Lead Fields",        description="Dynamic EAV field configuration for leads")
 * @OA\Tag(name="Lead Status",        description="Lead status management")
 * @OA\Tag(name="CRM",                description="CRM dashboard, pipeline, and analytics")
 * @OA\Tag(name="Campaign",           description="Campaign management and dialer")
 * @OA\Tag(name="Agent",              description="Agent management and monitoring")
 * @OA\Tag(name="User",               description="User account management")
 * @OA\Tag(name="DID",                description="Direct Inward Dialing number operations")
 * @OA\Tag(name="SMS",                description="SMS messaging operations")
 * @OA\Tag(name="Twilio",             description="Twilio account, numbers, trunks, calls, SMS")
 * @OA\Tag(name="Plivo",              description="Plivo account, numbers, trunks, calls, SMS")
 * @OA\Tag(name="Gmail",              description="Gmail OAuth and mailbox operations")
 * @OA\Tag(name="Google Calendar",    description="Google Calendar OAuth and events")
 * @OA\Tag(name="IVR",                description="Interactive Voice Response menu management")
 * @OA\Tag(name="Ring Group",         description="Ring group management")
 * @OA\Tag(name="Extension Group",    description="Extension group operations")
 * @OA\Tag(name="Voicemail",          description="Voicemail drop and mailbox operations")
 * @OA\Tag(name="Ringless",           description="Ringless voicemail campaign operations")
 * @OA\Tag(name="Report",             description="Call reports, CDR, daily, agent summary, disposition")
 * @OA\Tag(name="Attendance",         description="Agent clock-in/out and attendance tracking")
 * @OA\Tag(name="Billing",            description="Billing, charges, and subscription management")
 * @OA\Tag(name="Admin",              description="System administrator client management")
 * @OA\Tag(name="System",             description="System health, server info, and monitoring")
 * @OA\Tag(name="Workforce",          description="Workforce management — shifts, staffing, analytics")
 * @OA\Tag(name="AI",                 description="AI coaching and settings")
 * @OA\Tag(name="Fax",                description="Fax operations")
 * @OA\Tag(name="Profile",            description="User profile and account settings")
 * @OA\Tag(name="Lender",             description="Lender management and lead submission")
 * @OA\Tag(name="List",               description="Lead list management")
 */
class Controller extends BaseController
{
    use TenantAware;

    protected function successResponse(string $message, array $data = [])
    {
        return response()->json([
            "success" => true,
            "message" => $message,
            "data" => $data
        ]);
    }

    /**
     * Resolve the portal base URL for a tenant from crm_system_setting.company_domain.
     * Falls back to APP_FRONTEND_URL / APP_URL env vars if not set.
     * Always returns a URL with NO trailing slash.
     * Logs the resolved domain to aid debugging.
     */
    protected function getPortalBaseUrl(int $clientId): string
    {
        $domain = DB::connection("mysql_{$clientId}")
            ->table('crm_system_setting')
            ->orderBy('id')
            ->value('company_domain');

        if (empty($domain)) {
            $fallback = env('APP_FRONTEND_URL', env('APP_URL', ''));
            Log::warning("[getPortalBaseUrl] company_domain not configured for client {$clientId} — falling back to: {$fallback}");
            return rtrim($fallback, '/');
        }

        Log::info("[getPortalBaseUrl] client={$clientId} domain={$domain}");
        return rtrim($domain, '/');
    }

    /**
     * Assert that the authenticated user can access the given lead.
     *
     * Returns null when access is allowed, or a 403 JsonResponse to return immediately.
     *
     * Usage in controllers:
     *   if ($err = $this->assertLeadAccess($request, $lead)) return $err;
     */
    protected function assertLeadAccess(Request $request, object $lead): ?JsonResponse
    {
        $service  = new LeadVisibilityService();
        $clientId = $this->tenantId($request);

        if (!$service->canAccessLead($request->auth, $clientId, $lead)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this lead.',
            ], 403);
        }

        return null;
    }

    /**
     * Resolve a lead by ID (from crm_leads or crm_lead_data) and assert access.
     *
     * Convenience method for sub-resource controllers that receive a lead_id
     * parameter but don't load the full lead model.
     *
     * Usage:
     *   if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;
     */
    protected function assertLeadAccessById(Request $request, int $leadId): ?JsonResponse
    {
        $clientId = $this->tenantId($request);
        $conn     = "mysql_{$clientId}";

        $lead = DB::connection($conn)->table('crm_leads')
            ->where('id', $leadId)->where('is_deleted', 0)
            ->first(['id', 'assigned_to', 'created_by']);

        if (!$lead) {
            $lead = DB::connection($conn)->table('crm_lead_data')
                ->where('id', $leadId)->where('is_deleted', 0)
                ->first(['id', 'assigned_to', 'created_by']);
        }

        if (!$lead) {
            return response()->json(['success' => false, 'message' => 'Lead not found.'], 404);
        }

        return $this->assertLeadAccess($request, $lead);
    }

    protected function failResponse(string $message, array $errors = [], \Throwable $exception = null, $httpStatus=500)
    {
        if ($exception) {
            Log::error($exception->getMessage(), [
                "file" => $exception->getFile(),
                "line" => $exception->getLine()
            ]);
        }
        if (!is_numeric($httpStatus)) $httpStatus = 500;
        $code = intval($httpStatus / 100);
        if ($code < 2 || $code > 5) $httpStatus = 500;

        return response()->json([
            "success" => false,
            "message" => $message,
            "errors" => $errors
        ], $httpStatus);
    }
}
