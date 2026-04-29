<?php

namespace App\Services;

use App\Model\Client\Notification;
use App\Model\UserFcmToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MerchantNotificationService
{
    /**
     * Notify that the merchant signed the application.
     */
    public static function notifySignature(int $clientId, int $leadId): void
    {
        self::dispatch($clientId, $leadId, [
            'title'       => 'Application Signed',
            'description' => 'The merchant has signed the application.',
            'sms_body'    => 'Merchant signed the application.',
            'type'        => 'merchant_update',
        ]);
    }

    /**
     * Notify that the merchant uploaded a document.
     */
    public static function notifyDocumentUpload(int $clientId, int $leadId, array $meta = []): void
    {
        $fileName = $meta['file_name'] ?? 'a document';
        self::dispatch($clientId, $leadId, [
            'title'       => 'Document Uploaded',
            'description' => "The merchant uploaded {$fileName}.",
            'sms_body'    => "Merchant uploaded: {$fileName}",
            'type'        => 'merchant_update',
        ]);
    }

    /**
     * Notify that the merchant clicked Finish (application submitted).
     */
    public static function notifyApplicationSubmitted(int $clientId, int $leadId): void
    {
        self::dispatch($clientId, $leadId, [
            'title'       => 'Application Submitted',
            'description' => 'The merchant has completed and submitted their application.',
            'sms_body'    => 'Merchant completed and submitted the application.',
            'type'        => 'merchant_update',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // INTERNAL DISPATCH
    // ──────────────────────────────────────────────────────────────────────────

    private static function dispatch(int $clientId, int $leadId, array $event): void
    {
        $conn = "mysql_{$clientId}";

        // ── 1. Resolve recipient agent ──────────────────────────────────────
        $lead = DB::connection($conn)->table('crm_leads')->where('id', $leadId)->first(['assigned_to', 'created_by']);
        if (!$lead) {
            Log::warning("[MerchantNotification] Lead {$leadId} not found on client {$clientId}");
            return;
        }

        $agentId = $lead->assigned_to ?: $lead->created_by;
        if (!$agentId) {
            Log::warning("[MerchantNotification] No agent for lead {$leadId} on client {$clientId}");
            return;
        }

        $agent = DB::connection('master')->table('users')->where('id', $agentId)->first(['id', 'first_name', 'last_name', 'email', 'timezone']);
        if (!$agent) {
            Log::warning("[MerchantNotification] Agent {$agentId} not found in master.users");
            return;
        }

        $agentName = trim("{$agent->first_name} {$agent->last_name}");
        $agentTz   = $agent->timezone ?: 'America/New_York';

        // ── 2. Resolve merchant info from EAV ───────────────────────────────
        $eav = DB::connection($conn)->table('crm_lead_values')
            ->where('lead_id', $leadId)
            ->whereIn('field_key', ['first_name', 'last_name', 'company_name'])
            ->pluck('field_value', 'field_key')
            ->toArray();

        $merchantName = trim(($eav['first_name'] ?? '') . ' ' . ($eav['last_name'] ?? '')) ?: 'Unknown Merchant';
        $businessName = $eav['company_name'] ?? '';

        // ── 3. Resolve tenant company name ──────────────────────────────────
        $client      = DB::connection('master')->table('clients')->where('id', $clientId)->first(['company_name', 'website_url']);
        $companyName = $client->company_name ?? 'Rocket Dialer';
        $websiteUrl  = $client->website_url ?? '';

        $timestamp = Carbon::now($agentTz)->format('M j, Y g:i A T');

        // Build CRM lead URL
        $baseUrl = rtrim($websiteUrl, '/') ?: rtrim(env('APP_URL', ''), '/');
        $leadUrl = $baseUrl ? "{$baseUrl}/crm/leads/{$leadId}" : '';

        // ── Channel 1: In-app notification ──────────────────────────────────
        try {
            Notification::on($conn)->create([
                'lead_id'           => $leadId,
                'recipient_user_id' => $agentId,
                'type'              => $event['type'],
                'title'             => "{$event['title']} — {$merchantName}",
                'message'           => $event['description'],
                'is_read'           => false,
                'meta'              => json_encode([
                    'event'         => $event['title'],
                    'merchant_name' => $merchantName,
                    'business_name' => $businessName,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[MerchantNotification] In-app notification failed: " . $e->getMessage());
        }

        // ── Channel 2: FCM push ─────────────────────────────────────────────
        try {
            $fcmTokens = UserFcmToken::where('user_id', $agentId)->pluck('device_token')->toArray();
            if (!empty($fcmTokens)) {
                FirebaseService::sendNotification(
                    $fcmTokens,
                    "{$event['title']} — {$merchantName}",
                    $event['description'],
                    [
                        'type'      => 'crm_notification',
                        'lead_id'   => (string) $leadId,
                        'clientId'  => (string) $clientId,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::warning("[MerchantNotification] FCM push failed: " . $e->getMessage());
        }

        // ── Channel 3: Email ─────────────────────────────────────────────────
        try {
            try {
                $smtpType = $clientId === 11 ? 'notification' : 'online application';
                $emailSvc = EmailService::forClient($clientId, $smtpType);
            } catch (\Throwable $_) {
                $emailSvc = EmailService::forClientAny($clientId);
            }
            $html     = view('emails.merchant-notification', [
                'companyName'      => $companyName,
                'agentName'        => $agentName,
                'merchantName'     => $merchantName,
                'businessName'     => $businessName,
                'eventTitle'       => $event['title'],
                'eventDescription' => $event['description'],
                'leadUrl'          => $leadUrl,
                'timestamp'        => $timestamp,
            ])->render();

            $emailSvc->send(
                $agent->email,
                "{$event['title']} — {$merchantName}",
                $html,
            );
        } catch (\Throwable $e) {
            Log::warning("[MerchantNotification] Email failed: " . $e->getMessage());
        }

        // Channel 4 (SMS chat note) removed — these system events should not
        // appear in the SMS Inbox. The other 3 channels (in-app, push, email)
        // already notify the agent.
    }
}
