<?php

namespace App\Services;

use App\Model\Client\GmailNotificationLog;
use App\Model\Client\GmailNotificationSetting;
use App\Model\Client\TeamConversation;
use App\Model\Client\TeamConversationParticipant;
use App\Model\Client\TeamMessage;
use App\Model\Master\GmailOAuthToken;
use App\Model\Master\SystemBotUser;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

class GmailNotificationService
{
    protected GmailOAuthService $oauthService;
    protected GmailImapService $imapService;
    protected ?Pusher $pusher = null;

    public function __construct()
    {
        $this->oauthService = new GmailOAuthService();
        $this->imapService = new GmailImapService();
    }

    /**
     * Get Pusher instance.
     */
    protected function getPusher(): Pusher
    {
        if (!$this->pusher) {
            $this->pusher = new Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                [
                    'cluster' => env('PUSHER_APP_CLUSTER'),
                    'useTLS' => true
                ]
            );
        }
        return $this->pusher;
    }

    /**
     * Process new emails for a user and send notifications.
     */
    public function processNewEmails(int $userId, int $parentId): int
    {
        $connection = "mysql_{$parentId}";

        // Get user's token
        $token = GmailOAuthToken::getActiveForUser($userId);
        if (!$token) {
            return 0;
        }

        // Get user's settings
        $settings = GmailNotificationSetting::on($connection)
            ->where('user_id', $userId)
            ->where('is_enabled', true)
            ->first();

        if (!$settings) {
            return 0;
        }

        // Refresh token if needed
        if ($token->isExpiringSoon()) {
            $token = $this->oauthService->refreshAccessToken($token);
            if (!$token) {
                Log::error('Gmail Notification: Failed to refresh token', ['user_id' => $userId]);
                return 0;
            }
        }

        // Connect to IMAP
        if (!$this->imapService->connectWithToken($token)) {
            Log::error('Gmail Notification: IMAP connection failed', ['user_id' => $userId]);
            return 0;
        }

        try {
            // Determine since date (last sync or 24 hours ago)
            $sinceDate = $token->last_sync_at ?? Carbon::now()->subDay();

            // Fetch new emails
            $emails = $this->imapService->getNewEmails($sinceDate, $settings->only_unread);

            $notifiedCount = 0;

            foreach ($emails as $email) {
                // Check if already notified
                if (GmailNotificationLog::wasAlreadyNotified($connection, $userId, $email['message_id'])) {
                    continue;
                }

                // Send notification
                $success = $this->sendNotification($userId, $parentId, $email, $settings);

                if ($success) {
                    $notifiedCount++;
                }
            }

            // Update last sync timestamp
            $token->updateLastSync();

            return $notifiedCount;

        } finally {
            $this->imapService->disconnect();
        }
    }

    /**
     * Send a notification for an email.
     */
    protected function sendNotification(int $userId, int $parentId, array $email, GmailNotificationSetting $settings): bool
    {
        $connection = "mysql_{$parentId}";

        try {
            // Format the notification message
            $messageBody = $this->formatEmailNotification($email, $settings);

            // Get or create the bot user
            $bot = SystemBotUser::getOrCreateGmailBot($parentId);

            // Send based on notification type
            if ($settings->isDmNotification()) {
                $teamMessage = $this->sendToDm($userId, $bot->user_id, $messageBody, $parentId, $email);
            } else {
                $teamMessage = $this->sendToChannel($settings->channel_uuid, $bot->user_id, $messageBody, $parentId, $email);
            }

            if ($teamMessage) {
                // Log success
                GmailNotificationLog::logSent(
                    $connection,
                    $userId,
                    $email['message_id'],
                    $email['thread_id'],
                    $email['subject'],
                    $email['sender_email'],
                    $email['sender_name'],
                    $teamMessage->id,
                    $email['date']
                );
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Gmail Notification: Failed to send notification', [
                'user_id' => $userId,
                'message_id' => $email['message_id'],
                'error' => $e->getMessage(),
            ]);

            // Log failure
            GmailNotificationLog::logFailed(
                $connection,
                $userId,
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
     * Format email data into notification message.
     */
    public function formatEmailNotification(array $email, GmailNotificationSetting $settings): string
    {
        $lines = [];

        // Sender name (bold)
        $senderName = $email['sender_name'] !== $email['sender_email']
            ? $email['sender_name']
            : explode('@', $email['sender_email'])[0];
        $lines[] = "**{$senderName}**";

        // Subject line
        $lines[] = $email['subject'];

        // Brief preview (italicized, truncated to 1000 chars)
        $preview = $email['preview'] ?? $email['body'] ?? '';
        if (!empty($preview)) {
            $preview = trim(preg_replace('/\s+/', ' ', $preview));
            if (strlen($preview) > 1000) {
                $preview = substr($preview, 0, 1000) . '...';
            }
            $lines[] = "";
            $lines[] = "_{$preview}_";
        }

        // Attachment indicator
        if (!empty($email['attachments'])) {
            $count = count($email['attachments']);
            $indicator = $count === 1 ? "1 attachment" : "{$count} attachments";
            $lines[] = "";
            $lines[] = "[{$indicator}]";
        }

        // Link to open
        $link = $this->oauthService->buildGmailWebLink($email['message_id'], '');
        $lines[] = "";
        $lines[] = "[Open in Gmail]({$link})";

        return implode("\n", $lines);
    }

    /**
     * Send notification as a DM to the user.
     */
    public function sendToDm(int $userId, int $botUserId, string $messageBody, int $parentId, array $email): ?TeamMessage
    {
        $connection = "mysql_{$parentId}";

        // Find or create direct conversation between bot and user
        $conversation = $this->getOrCreateDmConversation($userId, $botUserId, $parentId);

        if (!$conversation) {
            return null;
        }

        return $this->createAndBroadcastMessage($conversation, $botUserId, $messageBody, $parentId, $email);
    }

    /**
     * Send notification to a team channel.
     */
    public function sendToChannel(string $channelUuid, int $botUserId, string $messageBody, int $parentId, array $email): ?TeamMessage
    {
        $connection = "mysql_{$parentId}";

        $conversation = TeamConversation::on($connection)
            ->where('uuid', $channelUuid)
            ->where('is_active', true)
            ->first();

        if (!$conversation) {
            Log::error('Gmail Notification: Channel not found', ['uuid' => $channelUuid]);
            return null;
        }

        // Ensure bot is a participant
        $this->ensureBotIsParticipant($conversation, $botUserId, $parentId);

        return $this->createAndBroadcastMessage($conversation, $botUserId, $messageBody, $parentId, $email);
    }

    /**
     * Create message and broadcast via Pusher.
     */
    protected function createAndBroadcastMessage(
        TeamConversation $conversation,
        int $botUserId,
        string $messageBody,
        int $parentId,
        array $email
    ): ?TeamMessage {
        $connection = "mysql_{$parentId}";

        try {
            // Create the message
            $message = new TeamMessage();
            $message->setConnection($connection);
            $message->conversation_id = $conversation->id;
            $message->sender_id = $botUserId;
            $message->message_type = 'system';
            $message->body = $messageBody;
            $message->metadata = [
                'type' => 'gmail_notification',
                'gmail_message_id' => $email['message_id'],
                'subject' => $email['subject'],
                'sender' => $email['sender_email'],
            ];
            $message->saveOrFail();

            // Get bot info for broadcast
            $bot = User::find($botUserId);
            $botName = $bot ? trim("{$bot->first_name} {$bot->last_name}") : 'Gmail Bot';

            $messageData = [
                'uuid' => $message->uuid,
                'conversation_uuid' => $conversation->uuid,
                'sender' => [
                    'id' => $botUserId,
                    'name' => $botName,
                ],
                'message_type' => 'system',
                'body' => $messageBody,
                'metadata' => $message->metadata,
                'attachments' => [],
                'is_mine' => false,
                'created_at' => $message->created_at->toIso8601String(),
            ];

            // Broadcast to conversation channel
            $this->getPusher()->trigger(
                "private-team-chat.{$parentId}.{$conversation->uuid}",
                'message.sent',
                $messageData
            );

            // Notify all participants on their personal channels
            $participants = TeamConversationParticipant::on($connection)
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $botUserId)
                ->where('is_active', true)
                ->pluck('user_id');

            foreach ($participants as $participantId) {
                $this->getPusher()->trigger(
                    "private-team-user.{$parentId}.{$participantId}",
                    'new.message',
                    [
                        'conversation_uuid' => $conversation->uuid,
                        'sender_name' => $botName,
                        'preview' => mb_substr($messageBody, 0, 50),
                        'type' => 'gmail_notification',
                    ]
                );
            }

            return $message;

        } catch (\Exception $e) {
            Log::error('Gmail Notification: Failed to create message', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get or create a DM conversation between user and bot.
     */
    protected function getOrCreateDmConversation(int $userId, int $botUserId, int $parentId): ?TeamConversation
    {
        $connection = "mysql_{$parentId}";

        // Look for existing direct conversation
        $conversation = TeamConversation::on($connection)
            ->where('type', 'direct')
            ->where('is_active', true)
            ->whereHas('participants', function ($q) use ($userId) {
                $q->where('user_id', $userId)->where('is_active', true);
            })
            ->whereHas('participants', function ($q) use ($botUserId) {
                $q->where('user_id', $botUserId)->where('is_active', true);
            })
            ->first();

        if ($conversation) {
            return $conversation;
        }

        // Create new conversation
        try {
            $conversation = new TeamConversation();
            $conversation->setConnection($connection);
            $conversation->type = 'direct';
            $conversation->name = null;
            $conversation->created_by = $botUserId;
            $conversation->is_active = true;
            $conversation->save();

            // Add participants
            foreach ([$userId, $botUserId] as $participantId) {
                $participant = new TeamConversationParticipant();
                $participant->setConnection($connection);
                $participant->conversation_id = $conversation->id;
                $participant->user_id = $participantId;
                $participant->role = $participantId === $botUserId ? 'admin' : 'member';
                $participant->is_active = true;
                $participant->joined_at = Carbon::now();
                $participant->save();
            }

            return $conversation;

        } catch (\Exception $e) {
            Log::error('Gmail Notification: Failed to create DM conversation', [
                'user_id' => $userId,
                'bot_user_id' => $botUserId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Ensure bot is a participant in the channel.
     */
    protected function ensureBotIsParticipant(TeamConversation $conversation, int $botUserId, int $parentId): void
    {
        $connection = "mysql_{$parentId}";

        $exists = TeamConversationParticipant::on($connection)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $botUserId)
            ->where('is_active', true)
            ->exists();

        if (!$exists) {
            $participant = new TeamConversationParticipant();
            $participant->setConnection($connection);
            $participant->conversation_id = $conversation->id;
            $participant->user_id = $botUserId;
            $participant->role = 'member';
            $participant->is_active = true;
            $participant->joined_at = Carbon::now();
            $participant->save();
        }
    }

    /**
     * Send a test notification.
     */
    public function sendTestNotification(int $userId, int $parentId): bool
    {
        $connection = "mysql_{$parentId}";

        $settings = GmailNotificationSetting::getOrCreateForUser($userId, $connection);

        $testEmail = [
            'message_id' => 'test_' . time(),
            'thread_id' => null,
            'subject' => 'Test Email Notification',
            'sender_email' => 'test@example.com',
            'sender_name' => 'Gmail Test',
            'date' => Carbon::now(),
            'preview' => 'This is a test notification to verify your Gmail notification settings are working correctly.',
            'attachments' => [
                ['filename' => 'document.pdf', 'size' => 1024000, 'size_formatted' => '1.00 MB'],
                ['filename' => 'image.jpg', 'size' => 512000, 'size_formatted' => '500.00 KB'],
            ],
            'has_attachments' => true,
        ];

        $messageBody = $this->formatEmailNotification($testEmail, $settings);
        $bot = SystemBotUser::getOrCreateGmailBot($parentId);

        if ($settings->isDmNotification()) {
            $result = $this->sendToDm($userId, $bot->user_id, $messageBody, $parentId, $testEmail);
        } else {
            if (!$settings->channel_uuid) {
                return false;
            }
            $result = $this->sendToChannel($settings->channel_uuid, $bot->user_id, $messageBody, $parentId, $testEmail);
        }

        return $result !== null;
    }
}
