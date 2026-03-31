<?php

namespace App\Services;

use App\Model\Client\TeamConversation;
use App\Model\Client\TeamConversationParticipant;
use App\Model\Client\TeamMessage;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

class SystemChannelService
{
    private const CHANNELS = [
        'lender'   => '#Lender',
        'merchant' => '#Merchant',
    ];

    /**
     * Ensure both system channels exist and the given user is a participant.
     */
    public static function ensureSystemChannels(int $clientId, ?int $userId = null): void
    {
        try {
            foreach (self::CHANNELS as $slug => $name) {
                $conv = self::getOrCreateChannel($clientId, $slug);
                if ($userId && $conv) {
                    self::addParticipantIfMissing($clientId, $conv->id, $userId);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("SystemChannelService::ensureSystemChannels failed: " . $e->getMessage());
        }
    }

    /**
     * Get or create a system channel by slug.
     */
    public static function getOrCreateChannel(int $clientId, string $slug): ?TeamConversation
    {
        $conn = "mysql_{$clientId}";

        $conv = TeamConversation::on($conn)
            ->where('system_slug', $slug)
            ->first();

        if ($conv) {
            return $conv;
        }

        $name = self::CHANNELS[$slug] ?? "#{$slug}";

        try {
            DB::connection($conn)->beginTransaction();

            $conv = new TeamConversation();
            $conv->setConnection($conn);
            $conv->type = 'group';
            $conv->name = $name;
            $conv->is_system = true;
            $conv->system_slug = $slug;
            $conv->created_by = 0;
            $conv->saveOrFail();

            // Create initial system message
            $msg = new TeamMessage();
            $msg->setConnection($conn);
            $msg->conversation_id = $conv->id;
            $msg->sender_id = 0;
            $msg->message_type = 'system';
            $msg->body = "{$name} channel created — broadcast updates will appear here.";
            $msg->saveOrFail();

            // Add all active org users as participants
            $userIds = User::where('parent_id', $clientId)
                ->where('is_deleted', 0)
                ->pluck('id');

            foreach ($userIds as $uid) {
                self::addParticipantIfMissing($clientId, $conv->id, $uid);
            }

            DB::connection($conn)->commit();
            return $conv;
        } catch (\Throwable $e) {
            DB::connection($conn)->rollBack();
            Log::error("SystemChannelService: Failed to create {$slug} channel for client {$clientId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sync all active org users into both system channels.
     */
    public static function syncParticipants(int $clientId): void
    {
        $userIds = User::where('parent_id', $clientId)
            ->where('is_deleted', 0)
            ->pluck('id');

        foreach (self::CHANNELS as $slug => $name) {
            $conv = self::getOrCreateChannel($clientId, $slug);
            if (!$conv) continue;

            foreach ($userIds as $uid) {
                self::addParticipantIfMissing($clientId, $conv->id, $uid);
            }
        }
    }

    /**
     * Post a system message to a channel and broadcast via Pusher.
     */
    public static function broadcast(int $clientId, string $slug, string $body, array $metadata = []): void
    {
        try {
            $conv = self::getOrCreateChannel($clientId, $slug);
            if (!$conv) return;

            $conn = "mysql_{$clientId}";

            $msg = new TeamMessage();
            $msg->setConnection($conn);
            $msg->conversation_id = $conv->id;
            $msg->sender_id = 0;
            $msg->message_type = 'system';
            $msg->body = $body;
            $msg->saveOrFail();

            $messageData = [
                'id'                => $msg->id,
                'uuid'              => $msg->uuid,
                'conversation_uuid' => $conv->uuid,
                'sender'            => ['id' => 0, 'name' => 'System'],
                'message_type'      => 'system',
                'body'              => $body,
                'attachments'       => [],
                'is_edited'         => false,
                'is_mine'           => false,
                'is_read'           => false,
                'is_delivered'      => false,
                'read_by'           => [],
                'created_at'        => $msg->created_at->toIso8601String(),
                'metadata'          => $metadata,
            ];

            $pusher = new Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                ['cluster' => env('PUSHER_APP_CLUSTER'), 'useTLS' => true]
            );

            // Broadcast to conversation channel
            $pusher->trigger(
                "private-team-chat.{$clientId}.{$conv->uuid}",
                'message.sent',
                $messageData
            );

            // Notify each participant on their personal channel
            $participantIds = TeamConversationParticipant::on($conn)
                ->where('conversation_id', $conv->id)
                ->where('is_active', true)
                ->pluck('user_id');

            foreach ($participantIds as $pid) {
                $pusher->trigger(
                    "private-team-user.{$clientId}.{$pid}",
                    'new.message',
                    [
                        'conversation_uuid' => $conv->uuid,
                        'sender_name'       => 'System',
                        'preview'           => mb_substr($body, 0, 50),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("SystemChannelService::broadcast failed [{$slug}]: " . $e->getMessage());
        }
    }

    /**
     * Add a user as participant if they're not already active.
     */
    private static function addParticipantIfMissing(int $clientId, int $conversationId, int $userId): void
    {
        $conn = "mysql_{$clientId}";

        $exists = TeamConversationParticipant::on($conn)
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->first();

        if ($exists) {
            if (!$exists->is_active) {
                $exists->is_active = true;
                $exists->left_at = null;
                $exists->save();
            }
            return;
        }

        $p = new TeamConversationParticipant();
        $p->setConnection($conn);
        $p->conversation_id = $conversationId;
        $p->user_id = $userId;
        $p->role = 'member';
        $p->joined_at = Carbon::now();
        $p->save();
    }
}
