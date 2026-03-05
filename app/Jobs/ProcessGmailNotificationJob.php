<?php

namespace App\Jobs;

use App\Model\Client\GmailAiAnalysis;
use App\Model\Client\GmailNotificationLog;
use App\Model\Client\GmailNotificationSetting;
use App\Model\Master\GmailOAuthToken;
use App\Services\EmailAiAnalysisService;
use App\Services\GmailNotificationService;
use App\Services\GmailOAuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

class ProcessGmailNotificationJob extends Job
{
    protected int $userId;
    protected int $parentId;
    protected ?string $historyId;

    /**
     * The queue to use for this job.
     */
    public $queue = 'gmail';

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, int $parentId, ?string $historyId = null)
    {
        $this->userId = $userId;
        $this->parentId = $parentId;
        $this->historyId = $historyId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $connection = "mysql_{$this->parentId}";

        Log::info('ProcessGmailNotification: Starting job', [
            'user_id' => $this->userId,
            'parent_id' => $this->parentId,
            'history_id' => $this->historyId,
        ]);

        // Get user's OAuth token
        $token = GmailOAuthToken::getActiveForUser($this->userId);
        if (!$token) {
            Log::warning('ProcessGmailNotification: No active token', ['user_id' => $this->userId]);
            return;
        }

        // Get user's notification settings
        $settings = GmailNotificationSetting::on($connection)
            ->where('user_id', $this->userId)
            ->where('is_enabled', true)
            ->first();

        if (!$settings) {
            Log::info('ProcessGmailNotification: Notifications not enabled', ['user_id' => $this->userId]);
            return;
        }

        // Initialize services
        $oauthService = new GmailOAuthService();
        $notificationService = new GmailNotificationService();
        $aiService = new EmailAiAnalysisService();

        // Refresh token if needed
        if ($token->isExpiringSoon()) {
            $token = $oauthService->refreshAccessToken($token);
            if (!$token) {
                Log::error('ProcessGmailNotification: Failed to refresh token', ['user_id' => $this->userId]);
                return;
            }
        }

        try {
            // Fetch new emails using Gmail API (instead of IMAP)
            $startHistoryId = $token->last_history_id;
            $emails = $oauthService->getNewEmailsSinceHistory($this->userId, $startHistoryId);

            Log::info('ProcessGmailNotification: Found emails', [
                'user_id' => $this->userId,
                'count' => count($emails),
                'history_id' => $this->historyId,
            ]);

            $processedCount = 0;

            foreach ($emails as $email) {
                // Check if already processed
                if (GmailNotificationLog::wasAlreadyNotified($connection, $this->userId, $email['message_id'])) {
                    continue;
                }

                // Check priority filter (if AI analysis enabled)
                $aiAnalysis = null;
                if ($this->shouldAnalyzeWithAi($settings) && $aiService->isConfigured()) {
                    $aiAnalysis = $this->processWithAi($email, $aiService, $connection);

                    // Check minimum priority filter
                    if ($aiAnalysis && !$this->meetsMinimumPriority($aiAnalysis, $settings)) {
                        GmailNotificationLog::logSkipped(
                            $connection,
                            $this->userId,
                            $email['message_id'],
                            $email['thread_id'],
                            $email['subject'],
                            $email['sender_email'],
                            $email['sender_name'],
                            $email['date'],
                            "Priority {$aiAnalysis->priority} below minimum {$settings->min_priority_notify}"
                        );
                        continue;
                    }
                }

                // Send notification with AI analysis
                $success = $this->sendNotificationWithAi(
                    $notificationService,
                    $email,
                    $settings,
                    $aiAnalysis,
                    $connection
                );

                if ($success) {
                    $processedCount++;
                }
            }

            // Update last sync timestamp and history ID
            $token->last_history_id = $this->historyId;
            $token->updateLastSync();

            Log::info('ProcessGmailNotification: Job completed', [
                'user_id' => $this->userId,
                'processed' => $processedCount,
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessGmailNotification: Exception', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if AI analysis should be performed.
     */
    protected function shouldAnalyzeWithAi(GmailNotificationSetting $settings): bool
    {
        return $settings->ai_analysis_enabled ?? true;
    }

    /**
     * Process email with AI analysis.
     */
    protected function processWithAi(array $email, EmailAiAnalysisService $aiService, string $connection): ?GmailAiAnalysis
    {
        // Check for existing analysis
        $existing = GmailAiAnalysis::getForMessage($connection, $this->userId, $email['message_id']);
        if ($existing) {
            return $existing;
        }

        // Perform new analysis
        return $aiService->analyzeAndSave($email, $this->userId, $connection);
    }

    /**
     * Check if email meets minimum priority threshold.
     */
    protected function meetsMinimumPriority(GmailAiAnalysis $analysis, GmailNotificationSetting $settings): bool
    {
        $minPriority = $settings->min_priority_notify ?? 'low';

        $priorityLevels = ['high' => 3, 'medium' => 2, 'low' => 1];

        $emailLevel = $priorityLevels[$analysis->priority] ?? 1;
        $minLevel = $priorityLevels[$minPriority] ?? 1;

        return $emailLevel >= $minLevel;
    }

    /**
     * Send notification with AI analysis included.
     */
    protected function sendNotificationWithAi(
        GmailNotificationService $notificationService,
        array $email,
        GmailNotificationSetting $settings,
        ?GmailAiAnalysis $aiAnalysis,
        string $connection
    ): bool {
        try {
            // Format message with AI analysis
            $messageBody = $this->formatNotificationWithAi($email, $settings, $aiAnalysis);

            // Get bot user
            $bot = \App\Model\Master\SystemBotUser::getOrCreateGmailBot($this->parentId);

            // Send based on notification type
            if ($settings->isDmNotification()) {
                $teamMessage = $notificationService->sendToDm(
                    $this->userId,
                    $bot->user_id,
                    $messageBody,
                    $this->parentId,
                    $email
                );
            } else {
                $teamMessage = $notificationService->sendToChannel(
                    $settings->channel_uuid,
                    $bot->user_id,
                    $messageBody,
                    $this->parentId,
                    $email
                );
            }

            if ($teamMessage) {
                GmailNotificationLog::logSent(
                    $connection,
                    $this->userId,
                    $email['message_id'],
                    $email['thread_id'],
                    $email['subject'],
                    $email['sender_email'],
                    $email['sender_name'],
                    $teamMessage->id,
                    $email['date']
                );

                // Send direct Pusher notification for toastr
                $this->sendDirectPusherNotification($email);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('ProcessGmailNotification: Failed to send notification', [
                'user_id' => $this->userId,
                'message_id' => $email['message_id'],
                'error' => $e->getMessage(),
            ]);

            GmailNotificationLog::logFailed(
                $connection,
                $this->userId,
                $email['message_id'],
                $email['thread_id'],
                $email['subject'],
                $email['sender_email'],
                $email['sender_name'],
                $email['date'],
                $e->getMessage()
            );

            return false;
        }
    }

    /**
     * Format notification message with AI analysis.
     */
    protected function formatNotificationWithAi(
        array $email,
        GmailNotificationSetting $settings,
        ?GmailAiAnalysis $aiAnalysis
    ): string {
        $lines = [];

        // Sender name (bold)
        $senderName = $email['sender_name'] !== $email['sender_email']
            ? $email['sender_name']
            : explode('@', $email['sender_email'])[0];
        $lines[] = "**{$senderName}**";

        // Subject line
        $lines[] = $email['subject'];

        // AI Analysis section
        if ($aiAnalysis) {
            $lines[] = "";

            // Priority
            if ($aiAnalysis->priority) {
                $priorityLabel = strtoupper($aiAnalysis->priority);
                $lines[] = "Priority: **{$priorityLabel}**";
            }

            // Category
            if ($aiAnalysis->category) {
                $lines[] = "Category: " . ucfirst($aiAnalysis->category);
            }

            // AI Summary
            if ($aiAnalysis->summary) {
                $lines[] = "";
                $lines[] = "**Summary**";
                $lines[] = $aiAnalysis->summary;
            }

            // Suggested Reply
            if ($aiAnalysis->suggested_reply) {
                $lines[] = "";
                $lines[] = "**Suggested Reply**";
                $lines[] = "_{$aiAnalysis->suggested_reply}_";
            }
        } else {
            // Brief preview if no AI analysis (truncated to 1000 chars)
            $preview = $email['preview'] ?? $email['body'] ?? '';
            if (!empty($preview)) {
                $preview = trim(preg_replace('/\s+/', ' ', $preview));
                if (strlen($preview) > 1000) {
                    $preview = substr($preview, 0, 1000) . '...';
                }
                $lines[] = "";
                $lines[] = "_{$preview}_";
            }
        }

        // Attachment indicator
        if (!empty($email['attachments'])) {
            $count = count($email['attachments']);
            $indicator = $count === 1 ? "1 attachment" : "{$count} attachments";
            $lines[] = "";
            $lines[] = "[{$indicator}]";
        }

        // Link to open
        $oauthService = new GmailOAuthService();
        $link = $oauthService->buildGmailWebLink($email['message_id'], '');
        $lines[] = "";
        $lines[] = "[Open in Gmail]({$link})";

        return implode("\n", $lines);
    }

    /**
     * Send direct Pusher notification for toastr display.
     */
    protected function sendDirectPusherNotification(array $email): void
    {
        try {
            $pusher = new Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                ['cluster' => env('PUSHER_APP_CLUSTER'), 'useTLS' => true]
            );

            $senderName = $email['sender_name'] !== $email['sender_email']
                ? $email['sender_name']
                : explode('@', $email['sender_email'])[0];

            $pusher->trigger(
                "private-team-user.{$this->parentId}.{$this->userId}",
                'gmail.new_email',
                [
                    'subject' => $email['subject'],
                    'from' => $senderName,
                    'preview' => substr($email['preview'] ?? '', 0, 100),
                ]
            );

            Log::info('ProcessGmailNotification: Pusher notification sent', [
                'user_id' => $this->userId,
                'subject' => $email['subject'],
            ]);

        } catch (\Exception $e) {
            Log::warning('ProcessGmailNotification: Failed to send Pusher notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessGmailNotification: Job failed', [
            'user_id' => $this->userId,
            'parent_id' => $this->parentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
