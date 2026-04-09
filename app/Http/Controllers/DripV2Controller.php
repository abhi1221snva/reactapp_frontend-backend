<?php

namespace App\Http\Controllers;

use App\Model\Client\DripV2Campaign;
use App\Model\Client\DripV2Unsubscribe;
use App\Model\Client\EmailSetting;
use App\Services\DripCampaignService;
use App\Services\DripEnrollmentService;
use App\Services\DripExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Routing\Controller;

class DripV2Controller extends Controller
{
    // ─── Campaign CRUD ────────────────────────────────────────────────────────

    /**
     * GET /crm/drip/campaigns
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $result = DripCampaignService::list((string) $clientId, $request->all());
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            Log::error('DripV2Controller@index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /crm/drip/campaigns
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $this->validate($request, [
                'name'                => 'required|string|max:200',
                'channel'             => 'required|in:email,sms,both',
                'steps'               => 'required|array|min:1',
                'steps.*.channel'     => 'required|in:email,sms',
                'steps.*.delay_value' => 'required|integer|min:0',
                'steps.*.delay_unit'  => 'required|in:minutes,hours,days',
            ]);

            $clientId = $request->auth->parent_id;
            $userId   = $request->auth->id;

            $campaign = DripCampaignService::create((string) $clientId, $request->all(), $userId);

            return response()->json(['success' => true, 'data' => $campaign], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            self::debugLog('STORE', $e, $request->all());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /crm/drip/campaigns/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $data = DripCampaignService::show((string) $clientId, $id);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            self::debugLog('SHOW', $e, ['id' => $id]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /crm/drip/campaigns/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            $userId   = $request->auth->id;

            // Lumen doesn't populate $request->all() for PUT — parse raw JSON body
            $all = json_decode($request->getContent(), true) ?? [];

            $campaign = DripCampaignService::update((string) $clientId, $id, $all, $userId);

            return response()->json(['success' => true, 'data' => $campaign]);
        } catch (\Throwable $e) {
            self::debugLog('UPDATE', $e, $request->all());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /crm/drip/campaigns/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $clientId = $request->auth->parent_id;
            DripCampaignService::delete((string) $clientId, $id);
            return response()->json(['success' => true, 'message' => 'Campaign archived.']);
        } catch (\Throwable $e) {
            self::debugLog('DESTROY', $e, ['id' => $id]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /crm/drip/campaigns/{id}/duplicate
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $userId   = $request->auth->id;

        $clone = DripCampaignService::duplicate((string) $clientId, $id, $userId);

        return response()->json(['success' => true, 'data' => $clone], 201);
    }

    /**
     * POST /crm/drip/campaigns/{id}/activate
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $clientId = $request->auth->parent_id;

        try {
            $campaign = DripCampaignService::activate((string) $clientId, $id);
            return response()->json(['success' => true, 'data' => $campaign]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /crm/drip/campaigns/{id}/pause
     */
    public function pause(Request $request, int $id): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $campaign = DripCampaignService::pause((string) $clientId, $id);
        return response()->json(['success' => true, 'data' => $campaign]);
    }

    /**
     * POST /crm/drip/campaigns/{id}/archive
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $campaign = DripCampaignService::archive((string) $clientId, $id);
        return response()->json(['success' => true, 'data' => $campaign]);
    }

    // ─── Enrollments ──────────────────────────────────────────────────────────

    /**
     * GET /crm/drip/campaigns/{id}/enrollments
     */
    public function enrollments(Request $request, int $id): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $result = DripEnrollmentService::listEnrollments((string) $clientId, $id, $request->all());
        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * POST /crm/drip/campaigns/{id}/enroll
     */
    public function enroll(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'lead_ids'   => 'required|array|min:1',
            'lead_ids.*' => 'required|integer',
        ]);

        $clientId = $request->auth->parent_id;
        $userId   = $request->auth->id;
        $leadIds  = $request->input('lead_ids');

        if (count($leadIds) === 1) {
            try {
                $enrollment = DripEnrollmentService::enroll((string) $clientId, $leadIds[0], $id, $userId);
                return response()->json(['success' => true, 'data' => $enrollment]);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        }

        $results = DripEnrollmentService::enrollBulk((string) $clientId, $leadIds, $id, $userId);
        return response()->json(['success' => true, 'data' => $results]);
    }

    /**
     * POST /crm/drip/enrollments/{id}/unenroll
     */
    public function unenroll(Request $request, int $id): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $reason   = $request->input('reason', 'manual');

        $enrollment = DripEnrollmentService::unenroll((string) $clientId, $id, $reason);

        return response()->json(['success' => true, 'data' => $enrollment]);
    }

    /**
     * GET /crm/drip/lead/{leadId}/enrollments
     */
    public function leadEnrollments(Request $request, int $leadId): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $data = DripEnrollmentService::leadEnrollments((string) $clientId, $leadId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── Analytics ────────────────────────────────────────────────────────────

    /**
     * GET /crm/drip/campaigns/{id}/analytics
     */
    public function analytics(Request $request, int $id): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $conn = "mysql_{$clientId}";
        $data = DripCampaignService::campaignAnalytics($conn, $id);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /crm/drip/campaigns/{id}/step-analytics
     */
    public function stepAnalytics(Request $request, int $id): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $data = DripCampaignService::stepAnalytics((string) $clientId, $id);
        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── Utility ──────────────────────────────────────────────────────────────

    /**
     * POST /crm/drip/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $leadId   = $request->input('lead_id');

        $data = DripExecutionService::preview((string) $clientId, $request->all(), $leadId);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /crm/drip/merge-tags
     */
    public function mergeTags(Request $request): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $conn = "mysql_{$clientId}";

        $systemTags = [
            ['key' => 'first_name', 'label' => 'First Name', 'group' => 'System'],
            ['key' => 'last_name', 'label' => 'Last Name', 'group' => 'System'],
            ['key' => 'email', 'label' => 'Email', 'group' => 'System'],
            ['key' => 'phone_number', 'label' => 'Phone', 'group' => 'System'],
            ['key' => 'company_name', 'label' => 'Company Name', 'group' => 'System'],
            ['key' => 'lead_status', 'label' => 'Lead Status', 'group' => 'System'],
        ];

        // Load dynamic CRM fields
        $dynamicTags = [];
        try {
            $labels = DB::connection($conn)->table('crm_labels')
                ->where('status', 1)
                ->orderBy('display_order')
                ->get(['field_key', 'label_name', 'section']);

            foreach ($labels as $label) {
                $dynamicTags[] = [
                    'key'   => $label->field_key,
                    'label' => $label->label_name,
                    'group' => ucfirst($label->section ?? 'Custom'),
                ];
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge($systemTags, $dynamicTags),
        ]);
    }

    /**
     * GET /crm/drip/sender-options
     */
    public function senderOptions(Request $request): JsonResponse
    {
        $clientId = $request->auth->parent_id;
        $conn = "mysql_{$clientId}";

        $smtp = EmailSetting::on($conn)->where('status', 1)->get(['id', 'sender_name', 'sender_email', 'mail_type']);

        $twilioNumbers = [];
        try {
            $twilioNumbers = DB::connection($conn)->table('twilio_numbers')
                ->where('status', 'active')
                ->get(['id', 'phone_number', 'friendly_name']);
        } catch (\Throwable $e) {
            // Table may not exist
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'email_settings'  => $smtp,
                'twilio_numbers'  => $twilioNumbers,
            ],
        ]);
    }

    // ─── Webhooks (Public) ────────────────────────────────────────────────────

    /**
     * POST /webhook/drip/sendgrid
     */
    public function sendgridWebhook(Request $request): JsonResponse
    {
        $events = $request->json()->all();

        foreach ($events as $event) {
            $msgId    = $event['sg_message_id'] ?? null;
            $type     = $event['event'] ?? null;
            $clientId = $event['client_id'] ?? null;

            if (!$msgId || !$type || !$clientId) continue;

            // Map SendGrid event types to our types
            $typeMap = [
                'delivered'   => 'delivered',
                'open'        => 'opened',
                'click'       => 'clicked',
                'bounce'      => 'bounced',
                'dropped'     => 'dropped',
                'unsubscribe' => 'unsubscribed',
                'spamreport'  => 'unsubscribed',
            ];

            $mappedType = $typeMap[$type] ?? null;
            if (!$mappedType) continue;

            try {
                DripExecutionService::handleDeliveryEvent((string) $clientId, $msgId, $mappedType, $event);
            } catch (\Throwable $e) {
                Log::error("SendGrid webhook error", ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * GET /drip/unsubscribe/{token}
     */
    public function unsubscribeLanding(Request $request, string $token): string
    {
        $parts = explode(':', base64_decode($token));
        if (count($parts) < 3) {
            return '<html><body><h2>Invalid unsubscribe link.</h2></body></html>';
        }

        return '<html><head><title>Unsubscribe</title>
            <style>body{font-family:Arial,sans-serif;max-width:480px;margin:60px auto;text-align:center;}
            .btn{background:#6366f1;color:#fff;border:none;padding:12px 32px;border-radius:6px;font-size:16px;cursor:pointer;}
            .btn:hover{background:#4f46e5;}</style></head>
            <body><h2>Unsubscribe from emails</h2>
            <p>Click below to stop receiving drip campaign emails.</p>
            <form method="POST" action="/drip/unsubscribe/' . htmlspecialchars($token) . '">
            <button type="submit" class="btn">Unsubscribe</button></form></body></html>';
    }

    /**
     * POST /drip/unsubscribe/{token}
     */
    public function processUnsubscribe(Request $request, string $token): string
    {
        $decoded = base64_decode($token);
        $parts   = explode(':', $decoded);

        if (count($parts) < 3) {
            return '<html><body><h2>Invalid link.</h2></body></html>';
        }

        [$clientId, $leadId, $hash] = $parts;

        // Validate hash
        $expectedHash = md5($leadId . env('APP_KEY', 'drip'));
        if (!hash_equals($expectedHash, $hash)) {
            return '<html><body><h2>Invalid link.</h2></body></html>';
        }

        try {
            DripExecutionService::handleUnsubscribe($clientId, (int) $leadId, 'email', 'link');
        } catch (\Throwable $e) {
            Log::error("Unsubscribe failed", ['client' => $clientId, 'lead' => $leadId, 'error' => $e->getMessage()]);
        }

        return '<html><head><title>Unsubscribed</title>
            <style>body{font-family:Arial,sans-serif;max-width:480px;margin:60px auto;text-align:center;}</style></head>
            <body><h2>You have been unsubscribed.</h2>
            <p>You will no longer receive drip campaign emails.</p></body></html>';
    }

    /**
     * GET /drip/track/open/{token}
     */
    public function trackOpen(Request $request, string $token): \Illuminate\Http\Response
    {
        $this->processTrackingEvent($token, 'opened');

        // Return 1x1 transparent pixel
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        return response($pixel, 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }

    /**
     * GET /drip/track/click/{token}
     */
    public function trackClick(Request $request, string $token): \Illuminate\Http\RedirectResponse
    {
        $decoded = base64_decode($token);
        $parts   = explode('|', $decoded);

        $url = $parts[0] ?? '/';
        if (count($parts) >= 3) {
            $this->processTrackingEvent($token, 'clicked');
        }

        return redirect($url);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function processTrackingEvent(string $token, string $eventType): void
    {
        try {
            $decoded = base64_decode($token);
            $parts   = explode('|', $decoded);
            if (count($parts) < 3) return;

            $clientId  = $parts[1] ?? null;
            $sendLogId = $parts[2] ?? null;

            if (!$clientId || !$sendLogId) return;

            $conn = "mysql_{$clientId}";
            $sendLog = \App\Model\Client\DripV2SendLog::on($conn)->find($sendLogId);
            if (!$sendLog) return;

            $now = \Illuminate\Support\Carbon::now();
            if ($eventType === 'opened' && !$sendLog->opened_at) {
                $sendLog->opened_at = $now;
                $sendLog->status    = 'opened';
                $sendLog->save();

                \App\Services\ActivityService::log($clientId, $sendLog->lead_id, 'drip_opened',
                    "Opened drip email: " . ($sendLog->subject ?? 'Untitled'),
                    null, ['send_log_id' => $sendLog->id], 0);
            } elseif ($eventType === 'clicked' && !$sendLog->clicked_at) {
                $sendLog->clicked_at = $now;
                $sendLog->status     = 'clicked';
                $sendLog->save();

                \App\Services\ActivityService::log($clientId, $sendLog->lead_id, 'drip_clicked',
                    "Clicked link in drip email",
                    null, ['send_log_id' => $sendLog->id], 0);
            }
        } catch (\Throwable $e) {
            Log::warning("Tracking event failed", ['token' => $token, 'type' => $eventType, 'error' => $e->getMessage()]);
        }
    }

    private static function debugLog(string $action, \Throwable $e, $context = null): void
    {
        $msg = date('Y-m-d H:i:s') . " [{$action}] " . $e->getMessage() . "\n" .
            "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        if ($context) {
            $msg .= "Context: " . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        $msg .= "Trace: " . $e->getTraceAsString() . "\n\n";
        file_put_contents(storage_path('logs/drip-debug.log'), $msg, FILE_APPEND);
    }
}
