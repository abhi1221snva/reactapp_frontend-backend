<?php

namespace App\Services;

use App\Model\Client\DripV2Campaign;
use App\Model\Client\DripV2Enrollment;
use App\Model\Client\DripV2Event;
use App\Model\Client\DripV2SendLog;
use App\Model\Client\DripV2Step;
use App\Model\Client\DripV2Unsubscribe;
use App\Model\Client\EmailSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DripExecutionService
{
    /**
     * Process all scheduled sends for a client.
     */
    public static function processScheduledSends(string $clientId): array
    {
        $conn   = "mysql_{$clientId}";
        $stats  = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $limit  = 100; // throttle per run

        $enrollments = DripV2Enrollment::on($conn)
            ->where('status', 'active')
            ->where('next_send_at', '<=', Carbon::now())
            ->whereNotNull('current_step_id')
            ->limit($limit)
            ->get();

        foreach ($enrollments as $enrollment) {
            $stats['processed']++;

            try {
                // Check exit conditions first
                if (DripEnrollmentService::checkExitConditions($clientId, $enrollment)) {
                    DripEnrollmentService::unenroll($clientId, $enrollment->id, 'exit_condition_met');
                    $stats['skipped']++;
                    continue;
                }

                $step = DripV2Step::on($conn)->find($enrollment->current_step_id);
                if (!$step || !$step->is_active) {
                    DripEnrollmentService::advanceToNextStep($clientId, $enrollment);
                    $stats['skipped']++;
                    continue;
                }

                $campaign = DripV2Campaign::on($conn)->find($enrollment->campaign_id);
                if (!$campaign || $campaign->status !== 'active') {
                    $stats['skipped']++;
                    continue;
                }

                // Check quiet hours
                if (self::isInQuietHours($campaign)) {
                    // Defer to next window — don't advance, just skip this run
                    $stats['skipped']++;
                    continue;
                }

                // Execute the step
                $success = self::executeStep($clientId, $enrollment, $step, $campaign);

                if ($success) {
                    $stats['sent']++;
                    DripEnrollmentService::advanceToNextStep($clientId, $enrollment);
                } else {
                    $stats['failed']++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error('DripExecution: step failed', [
                    'client'     => $clientId,
                    'enrollment' => $enrollment->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Execute a single step for an enrollment.
     */
    public static function executeStep(string $clientId, DripV2Enrollment $enrollment, DripV2Step $step, DripV2Campaign $campaign): bool
    {
        $conn = "mysql_{$clientId}";

        // Resolve merge tags
        $mergeTagService = new MergeTagService();

        $bodyHtml  = $mergeTagService->resolve($clientId, $enrollment->lead_id, $step->body_html ?? '');
        $bodyPlain = $mergeTagService->resolve($clientId, $enrollment->lead_id, $step->body_plain ?? '');
        $subject   = $mergeTagService->resolve($clientId, $enrollment->lead_id, $step->subject ?? '');

        if ($step->channel === 'email') {
            return self::sendEmail($clientId, $enrollment, $step, $campaign, $subject, $bodyHtml, $bodyPlain);
        }

        if ($step->channel === 'sms') {
            return self::sendSms($clientId, $enrollment, $step, $campaign, $bodyPlain ?: strip_tags($bodyHtml));
        }

        return false;
    }

    /**
     * Send an email step.
     */
    private static function sendEmail(
        string $clientId,
        DripV2Enrollment $enrollment,
        DripV2Step $step,
        DripV2Campaign $campaign,
        string $subject,
        string $bodyHtml,
        string $bodyPlain
    ): bool {
        $conn = "mysql_{$clientId}";

        // Load lead email
        $leadEmail = self::getLeadField($conn, $enrollment->lead_id, 'email');
        if (empty($leadEmail)) {
            self::logSend($conn, $enrollment, $step, 'email', null, null, $subject, 'failed', 'No email address on lead');
            ActivityService::log($clientId, $enrollment->lead_id, 'drip_step_failed',
                "Drip step #{$step->position} failed (email) — no email address",
                null, ['step_id' => $step->id], 0);
            return false;
        }

        // Check unsubscribe
        $unsub = DripV2Unsubscribe::on($conn)
            ->where('lead_id', $enrollment->lead_id)
            ->whereIn('channel', ['email', 'both'])
            ->first();
        if ($unsub) {
            self::logSend($conn, $enrollment, $step, 'email', $leadEmail, null, $subject, 'failed', 'Lead is unsubscribed');
            return false;
        }

        // Append unsubscribe link
        $unsubToken = base64_encode("{$clientId}:{$enrollment->lead_id}:" . md5($enrollment->lead_id . env('APP_KEY', 'drip')));
        $unsubUrl   = url("/drip/unsubscribe/{$unsubToken}");
        $bodyHtml  .= "\n<p style=\"font-size:11px;color:#999;margin-top:20px;\"><a href=\"{$unsubUrl}\" style=\"color:#999;\">Unsubscribe</a></p>";

        // Load SMTP settings
        $emailSetting = null;
        if ($campaign->email_setting_id) {
            $emailSetting = EmailSetting::on($conn)->find($campaign->email_setting_id);
        }
        if (!$emailSetting) {
            $emailSetting = EmailSetting::on($conn)->where('status', 1)->first();
        }
        if (!$emailSetting) {
            self::logSend($conn, $enrollment, $step, 'email', $leadEmail, null, $subject, 'failed', 'No SMTP settings configured');
            ActivityService::log($clientId, $enrollment->lead_id, 'drip_step_failed',
                "Drip step #{$step->position} failed (email) — no SMTP settings",
                null, ['step_id' => $step->id], 0);
            return false;
        }

        try {
            $transport = new \Swift_SmtpTransport($emailSetting->mail_host, $emailSetting->mail_port);
            $transport->setUsername($emailSetting->mail_username);
            $transport->setPassword($emailSetting->mail_password);
            if ($emailSetting->mail_encryption) {
                $transport->setEncryption($emailSetting->mail_encryption);
            }
            $mailer = new \Swift_Mailer($transport);

            $message = (new \Swift_Message($subject))
                ->setFrom([$emailSetting->sender_email => $emailSetting->sender_name])
                ->setTo([$leadEmail])
                ->setBody($bodyHtml, 'text/html');

            if (!empty($bodyPlain)) {
                $message->addPart($bodyPlain, 'text/plain');
            }

            $mailer->send($message);

            $providerMsgId = $message->getId();

            self::logSend($conn, $enrollment, $step, 'email', $leadEmail, $emailSetting->sender_email, $subject, 'sent', null, $providerMsgId);

            ActivityService::log($clientId, $enrollment->lead_id, 'drip_step_sent',
                "Drip step #{$step->position} sent (email): {$subject}",
                null, ['step_id' => $step->id, 'to' => $leadEmail], 0);

            return true;
        } catch (\Throwable $e) {
            self::logSend($conn, $enrollment, $step, 'email', $leadEmail, $emailSetting->sender_email ?? null, $subject, 'failed', $e->getMessage());

            ActivityService::log($clientId, $enrollment->lead_id, 'drip_step_failed',
                "Drip step #{$step->position} failed (email): " . mb_substr($e->getMessage(), 0, 200),
                null, ['step_id' => $step->id], 0);

            return false;
        }
    }

    /**
     * Send an SMS step.
     */
    private static function sendSms(
        string $clientId,
        DripV2Enrollment $enrollment,
        DripV2Step $step,
        DripV2Campaign $campaign,
        string $messageBody
    ): bool {
        $conn = "mysql_{$clientId}";

        $leadPhone = self::getLeadField($conn, $enrollment->lead_id, 'phone_number')
            ?? self::getLeadField($conn, $enrollment->lead_id, 'phone');
        if (empty($leadPhone)) {
            self::logSend($conn, $enrollment, $step, 'sms', null, null, null, 'failed', 'No phone number on lead');
            ActivityService::log($clientId, $enrollment->lead_id, 'drip_step_failed',
                "Drip step #{$step->position} failed (sms) — no phone number",
                null, ['step_id' => $step->id], 0);
            return false;
        }

        // Check SMS unsubscribe
        $unsub = DripV2Unsubscribe::on($conn)
            ->where('lead_id', $enrollment->lead_id)
            ->whereIn('channel', ['sms', 'both'])
            ->first();
        if ($unsub) {
            self::logSend($conn, $enrollment, $step, 'sms', $leadPhone, null, null, 'failed', 'Lead is unsubscribed from SMS');
            return false;
        }

        $fromNumber = $campaign->sms_from_number;
        if (empty($fromNumber)) {
            self::logSend($conn, $enrollment, $step, 'sms', $leadPhone, null, null, 'failed', 'No SMS from number configured');
            return false;
        }

        try {
            $twilio = TwilioService::forClient((int) $clientId);
            $result = $twilio->sendSms($fromNumber, $leadPhone, $messageBody);

            $providerMsgId = $result['sid'] ?? $result['message_sid'] ?? null;

            self::logSend($conn, $enrollment, $step, 'sms', $leadPhone, $fromNumber, null, 'sent', null, $providerMsgId);

            ActivityService::log($clientId, $enrollment->lead_id, 'drip_step_sent',
                "Drip step #{$step->position} sent (sms)",
                mb_substr($messageBody, 0, 200),
                ['step_id' => $step->id, 'to' => $leadPhone], 0);

            return true;
        } catch (\Throwable $e) {
            self::logSend($conn, $enrollment, $step, 'sms', $leadPhone, $fromNumber, null, 'failed', $e->getMessage());

            ActivityService::log($clientId, $enrollment->lead_id, 'drip_step_failed',
                "Drip step #{$step->position} failed (sms): " . mb_substr($e->getMessage(), 0, 200),
                null, ['step_id' => $step->id], 0);

            return false;
        }
    }

    /**
     * Handle a delivery event from a webhook.
     */
    public static function handleDeliveryEvent(string $clientId, string $providerMessageId, string $eventType, array $eventData = []): void
    {
        $conn = "mysql_{$clientId}";

        $sendLog = DripV2SendLog::on($conn)
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if (!$sendLog) return;

        // Update send_log status timestamps
        $now = Carbon::now();
        switch ($eventType) {
            case 'delivered':
                $sendLog->status       = 'delivered';
                $sendLog->delivered_at  = $now;
                break;
            case 'opened':
                $sendLog->status    = 'opened';
                $sendLog->opened_at = $now;
                ActivityService::log($clientId, $sendLog->lead_id, 'drip_opened',
                    "Opened drip email: " . ($sendLog->subject ?? 'Untitled'),
                    null, ['send_log_id' => $sendLog->id], 0);
                break;
            case 'clicked':
                $sendLog->status     = 'clicked';
                $sendLog->clicked_at = $now;
                ActivityService::log($clientId, $sendLog->lead_id, 'drip_clicked',
                    "Clicked link in drip email",
                    null, ['send_log_id' => $sendLog->id], 0);
                break;
            case 'bounced':
            case 'dropped':
                $sendLog->status    = 'bounced';
                $sendLog->failed_at = $now;
                break;
            case 'unsubscribed':
                $sendLog->status = 'unsubscribed';
                self::handleUnsubscribe($clientId, $sendLog->lead_id, 'email');
                break;
            case 'failed':
                $sendLog->status        = 'failed';
                $sendLog->failed_at     = $now;
                $sendLog->error_message = $eventData['reason'] ?? null;
                break;
        }
        $sendLog->save();

        // Create event record
        $event = new DripV2Event();
        $event->setConnection($conn);
        $event->fill([
            'send_log_id'       => $sendLog->id,
            'event_type'        => $eventType,
            'event_data'        => $eventData ?: null,
            'provider_event_id' => $eventData['sg_event_id'] ?? $eventData['event_id'] ?? null,
            'occurred_at'       => $now,
        ]);
        $event->save();
    }

    /**
     * Handle an unsubscribe request.
     */
    public static function handleUnsubscribe(string $clientId, int $leadId, string $channel, string $source = 'link'): void
    {
        $conn = "mysql_{$clientId}";

        // Create or update unsubscribe record
        $existing = DripV2Unsubscribe::on($conn)
            ->where('lead_id', $leadId)
            ->where('channel', $channel)
            ->first();

        if (!$existing) {
            $unsub = new DripV2Unsubscribe();
            $unsub->setConnection($conn);
            $unsub->fill([
                'lead_id' => $leadId,
                'email'   => $channel !== 'sms' ? self::getLeadField($conn, $leadId, 'email') : null,
                'phone'   => $channel !== 'email' ? (self::getLeadField($conn, $leadId, 'phone_number') ?? self::getLeadField($conn, $leadId, 'phone')) : null,
                'channel' => $channel,
                'reason'  => 'User unsubscribed',
                'source'  => $source,
            ]);
            $unsub->save();
        }

        // Stop all active enrollments for this lead
        $enrollments = DripV2Enrollment::on($conn)
            ->where('lead_id', $leadId)
            ->where('status', 'active')
            ->get();

        foreach ($enrollments as $enrollment) {
            $campaign = DripV2Campaign::on($conn)->find($enrollment->campaign_id);
            if ($campaign && in_array($campaign->channel, [$channel, 'both'])) {
                DripEnrollmentService::unenroll($clientId, $enrollment->id, 'unsubscribed');
            }
        }

        ActivityService::log($clientId, $leadId, 'drip_unsubscribed',
            "Unsubscribed from drip campaigns ({$channel})",
            null, ['channel' => $channel, 'source' => $source], 0);
    }

    /**
     * Check if current time is within campaign quiet hours.
     */
    public static function isInQuietHours(DripV2Campaign $campaign): bool
    {
        if (!$campaign->quiet_hours_start || !$campaign->quiet_hours_end) {
            return false;
        }

        $tz  = $campaign->quiet_hours_tz ?? 'UTC';
        $now = Carbon::now($tz);

        $start = Carbon::createFromFormat('H:i:s', $campaign->quiet_hours_start, $tz);
        $end   = Carbon::createFromFormat('H:i:s', $campaign->quiet_hours_end, $tz);

        if ($start->gt($end)) {
            // Overnight window (e.g. 22:00 → 08:00)
            return $now->gte($start) || $now->lte($end);
        }

        return $now->between($start, $end);
    }

    /**
     * Preview rendered step content with sample lead data.
     */
    public static function preview(string $clientId, array $data, ?int $leadId = null): array
    {
        $mergeTagService = new MergeTagService();

        $subject  = $data['subject'] ?? '';
        $bodyHtml = $data['body_html'] ?? '';

        if ($leadId) {
            $subject  = $mergeTagService->resolve($clientId, $leadId, $subject);
            $bodyHtml = $mergeTagService->resolve($clientId, $leadId, $bodyHtml);
        }

        return [
            'subject'   => $subject,
            'body_html' => $bodyHtml,
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Create a send log entry.
     */
    private static function logSend(
        string $conn,
        DripV2Enrollment $enrollment,
        DripV2Step $step,
        string $channel,
        ?string $toAddress,
        ?string $fromAddress,
        ?string $subject,
        string $status,
        ?string $errorMessage = null,
        ?string $providerMessageId = null
    ): DripV2SendLog {
        $log = new DripV2SendLog();
        $log->setConnection($conn);
        $log->fill([
            'enrollment_id'       => $enrollment->id,
            'step_id'             => $step->id,
            'lead_id'             => $enrollment->lead_id,
            'channel'             => $channel,
            'to_address'          => $toAddress,
            'from_address'        => $fromAddress,
            'subject'             => $subject ? mb_substr($subject, 0, 500) : null,
            'body_preview'        => mb_substr($step->body_plain ?? strip_tags($step->body_html ?? ''), 0, 500),
            'provider_message_id' => $providerMessageId,
            'status'              => $status,
            'sent_at'             => $status === 'sent' ? Carbon::now() : null,
            'failed_at'           => $status === 'failed' ? Carbon::now() : null,
            'error_message'       => $errorMessage,
        ]);
        $log->save();
        return $log;
    }

    /**
     * Get a lead field value from system cols or EAV.
     */
    private static function getLeadField(string $conn, int $leadId, string $fieldKey): ?string
    {
        // Check system columns first
        try {
            $lead = DB::connection($conn)->table('crm_leads')->where('id', $leadId)->first();
            if ($lead && isset($lead->{$fieldKey}) && $lead->{$fieldKey}) {
                return (string) $lead->{$fieldKey};
            }
        } catch (\Throwable $e) {
            // Table may not exist
        }

        // Check EAV
        try {
            $val = DB::connection($conn)->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->where('field_key', $fieldKey)
                ->value('field_value');
            if ($val) return (string) $val;
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return null;
    }
}
