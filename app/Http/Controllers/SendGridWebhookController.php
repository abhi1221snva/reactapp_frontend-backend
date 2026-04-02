<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendGridWebhookController extends Controller
{
    /**
     * SendGrid event → our email_status mapping.
     * Priority: higher number wins (prevents downgrading status).
     */
    private const STATUS_MAP = [
        'processed'   => ['status' => 'sent',      'priority' => 1],
        'delivered'   => ['status' => 'delivered',  'priority' => 2],
        'open'        => ['status' => 'opened',     'priority' => 3],
        'click'       => ['status' => 'opened',     'priority' => 3],
        'bounce'      => ['status' => 'failed',     'priority' => 4],
        'dropped'     => ['status' => 'failed',     'priority' => 4],
        'deferred'    => ['status' => 'sent',       'priority' => 1], // still in transit
        'spam_report' => ['status' => 'failed',     'priority' => 4],
    ];

    private const PRIORITY = [
        'sent'      => 1,
        'delivered' => 2,
        'opened'    => 3,
        'failed'    => 4,
    ];

    /**
     * POST /sendgrid/webhook/{clientId}/events
     *
     * Receives a batch of SendGrid Event Webhook events.
     * Each client's SendGrid account points to their own URL.
     */
    public function events(Request $request, string $clientId): Response
    {
        $conn = "mysql_{$clientId}";

        // Verify connection exists
        try {
            DB::connection($conn)->getPdo();
        } catch (\Throwable $e) {
            Log::error("SendGrid webhook: invalid client_id={$clientId}");
            return response('', 400);
        }

        $events = $request->json()->all();

        if (!is_array($events) || empty($events)) {
            return response('', 204);
        }

        foreach ($events as $event) {
            try {
                $this->processEvent($conn, $event);
            } catch (\Throwable $e) {
                Log::warning("SendGrid webhook event failed: " . $e->getMessage(), [
                    'client_id' => $clientId,
                    'event'     => $event['event'] ?? 'unknown',
                    'email'     => $event['email'] ?? 'unknown',
                ]);
            }
        }

        return response('', 204);
    }

    /**
     * Process a single SendGrid event.
     */
    private function processEvent(string $conn, array $event): void
    {
        $eventType = $event['event'] ?? null;
        $email     = $event['email'] ?? null;

        if (!$eventType || !$email || !isset(self::STATUS_MAP[$eventType])) {
            return;
        }

        $mapped   = self::STATUS_MAP[$eventType];
        $newStatus = $mapped['status'];

        // Find the most recent email submission to this recipient
        $submission = DB::connection($conn)
            ->table('crm_lender_submissions')
            ->where('lender_email', $email)
            ->where('submission_type', 'normal')
            ->orderByDesc('submitted_at')
            ->first(['id', 'email_status']);

        if (!$submission) {
            return;
        }

        // Only upgrade status (never downgrade)
        $currentPriority = self::PRIORITY[$submission->email_status] ?? 0;
        $newPriority     = self::PRIORITY[$newStatus] ?? 0;

        if ($newPriority <= $currentPriority) {
            return;
        }

        DB::connection($conn)
            ->table('crm_lender_submissions')
            ->where('id', $submission->id)
            ->update([
                'email_status'    => $newStatus,
                'email_status_at' => Carbon::now(),
            ]);
    }

    /**
     * GET /sendgrid/webhook/ping — health check.
     */
    public function ping(): Response
    {
        return response('ok', 200);
    }
}
