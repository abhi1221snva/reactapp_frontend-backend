<?php

namespace App\Http\Controllers;

use App\Model\Client\GmailNotificationLog;
use App\Model\Client\GmailNotificationSetting;
use App\Model\Client\TeamConversation;
use App\Model\Master\GmailOAuthToken;
use App\Services\GmailNotificationService;
use App\Services\GmailOAuthService;
use Illuminate\Http\Request;

/**
 * @OA\Get(
 *   path="/gmail/notification/settings",
 *   tags={"Gmail"},
 *   summary="Get Gmail notification settings",
 *   security={{"bearerAuth":{}}},
 *   @OA\Response(response=200, description="Settings retrieved"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/gmail/notification/settings",
 *   tags={"Gmail"},
 *   summary="Update Gmail notification settings",
 *   security={{"bearerAuth":{}}},
 *   @OA\RequestBody(required=false, @OA\JsonContent(
 *     @OA\Property(property="notification_type", type="string", enum={"dm","channel"}),
 *     @OA\Property(property="channel_uuid", type="string"),
 *     @OA\Property(property="is_enabled", type="boolean"),
 *     @OA\Property(property="include_subject", type="boolean"),
 *     @OA\Property(property="include_sender", type="boolean"),
 *     @OA\Property(property="include_preview", type="boolean"),
 *     @OA\Property(property="preview_length", type="integer"),
 *     @OA\Property(property="include_attachments_list", type="boolean"),
 *     @OA\Property(property="include_email_link", type="boolean"),
 *     @OA\Property(property="filter_labels", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="exclude_labels", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="only_unread", type="boolean")
 *   )),
 *   @OA\Response(response=200, description="Settings updated"),
 *   @OA\Response(response=400, description="Gmail not connected"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/gmail/notification/channels",
 *   tags={"Gmail"},
 *   summary="Get team channels available for Gmail notifications",
 *   security={{"bearerAuth":{}}},
 *   @OA\Response(response=200, description="Channels list"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/gmail/notification/test",
 *   tags={"Gmail"},
 *   summary="Send a test Gmail notification",
 *   security={{"bearerAuth":{}}},
 *   @OA\Response(response=200, description="Test notification sent"),
 *   @OA\Response(response=400, description="Settings not configured"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/gmail/notification/logs",
 *   tags={"Gmail"},
 *   summary="Get Gmail notification history/logs",
 *   security={{"bearerAuth":{}}},
 *   @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=50)),
 *   @OA\Response(response=200, description="Logs retrieved"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 */
class GmailNotificationSettingsController extends Controller
{
    protected $notificationService = null;
    protected $oauthService = null;

    protected function getOAuthService()
    {
        if (!$this->oauthService) {
            $this->oauthService = new GmailOAuthService();
        }
        return $this->oauthService;
    }

    protected function getNotificationService()
    {
        if (!$this->notificationService) {
            $this->notificationService = new GmailNotificationService();
        }
        return $this->notificationService;
    }

    /**
     * Get user's Gmail notification settings.
     */
    public function show(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $connection = "mysql_{$parentId}";

            // Get connection status
            $connectionStatus = $this->getOAuthService()->getConnectionStatus($userId);

            // Get or create settings
            $settings = GmailNotificationSetting::getOrCreateForUser($userId, $connection);

            // Get channel name if channel notification is set
            $channelName = null;
            if ($settings->channel_uuid) {
                $channel = TeamConversation::on($connection)
                    ->where('uuid', $settings->channel_uuid)
                    ->first();
                $channelName = $channel ? $channel->name : null;
            }

            return $this->successResponse("Settings retrieved", [
                'connection' => $connectionStatus,
                'settings' => [
                    'notification_type' => $settings->notification_type,
                    'channel_uuid' => $settings->channel_uuid,
                    'channel_name' => $channelName,
                    'is_enabled' => $settings->is_enabled,
                    'include_subject' => $settings->include_subject,
                    'include_sender' => $settings->include_sender,
                    'include_preview' => $settings->include_preview,
                    'preview_length' => $settings->preview_length,
                    'include_attachments_list' => $settings->include_attachments_list,
                    'include_email_link' => $settings->include_email_link,
                    'filter_labels' => $settings->filter_labels,
                    'exclude_labels' => $settings->exclude_labels,
                    'only_unread' => $settings->only_unread,
                ],
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get settings", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Update user's Gmail notification settings.
     */
    public function update(Request $request)
    {
        $this->validate($request, [
            'notification_type' => 'in:dm,channel',
            'channel_uuid' => 'nullable|string|max:36',
            'is_enabled' => 'boolean',
            'include_subject' => 'boolean',
            'include_sender' => 'boolean',
            'include_preview' => 'boolean',
            'preview_length' => 'integer|min:50|max:1000',
            'include_attachments_list' => 'boolean',
            'include_email_link' => 'boolean',
            'filter_labels' => 'nullable|array',
            'exclude_labels' => 'nullable|array',
            'only_unread' => 'boolean',
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $connection = "mysql_{$parentId}";

            // Check if Gmail is connected before enabling
            if ($request->input('is_enabled', false)) {
                $token = GmailOAuthToken::getActiveForUser($userId);
                if (!$token) {
                    return $this->failResponse("Please connect Gmail first before enabling notifications", [], null, 400);
                }
            }

            // Validate channel exists if channel notification type
            if ($request->input('notification_type') === 'channel') {
                $channelUuid = $request->input('channel_uuid');
                if (!$channelUuid) {
                    return $this->failResponse("Channel UUID is required for channel notifications", [], null, 400);
                }

                $channel = TeamConversation::on($connection)
                    ->where('uuid', $channelUuid)
                    ->where('is_active', true)
                    ->first();

                if (!$channel) {
                    return $this->failResponse("Channel not found", [], null, 404);
                }
            }

            $settings = GmailNotificationSetting::getOrCreateForUser($userId, $connection);

            // Update fields
            $updateFields = [
                'notification_type', 'channel_uuid', 'is_enabled',
                'include_subject', 'include_sender', 'include_preview',
                'preview_length', 'include_attachments_list', 'include_email_link',
                'filter_labels', 'exclude_labels', 'only_unread'
            ];

            foreach ($updateFields as $field) {
                if ($request->has($field)) {
                    $settings->$field = $request->input($field);
                }
            }

            // Clear channel_uuid if switching to DM
            if ($settings->notification_type === 'dm') {
                $settings->channel_uuid = null;
            }

            $settings->save();

            return $this->successResponse("Settings updated successfully", [
                'settings' => [
                    'notification_type' => $settings->notification_type,
                    'channel_uuid' => $settings->channel_uuid,
                    'is_enabled' => $settings->is_enabled,
                    'include_subject' => $settings->include_subject,
                    'include_sender' => $settings->include_sender,
                    'include_preview' => $settings->include_preview,
                    'preview_length' => $settings->preview_length,
                    'include_attachments_list' => $settings->include_attachments_list,
                    'include_email_link' => $settings->include_email_link,
                    'filter_labels' => $settings->filter_labels,
                    'exclude_labels' => $settings->exclude_labels,
                    'only_unread' => $settings->only_unread,
                ],
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update settings", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get available team channels for notification.
     */
    public function getChannels(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $connection = "mysql_{$parentId}";

            // Get group conversations the user is part of
            $channels = TeamConversation::on($connection)
                ->where('type', 'group')
                ->where('is_active', true)
                ->whereHas('participants', function ($query) use ($userId) {
                    $query->where('user_id', $userId)->where('is_active', true);
                })
                ->orderBy('name')
                ->get(['uuid', 'name', 'avatar', 'created_at']);

            $result = $channels->map(function ($channel) {
                return [
                    'uuid' => $channel->uuid,
                    'name' => $channel->name,
                    'avatar' => $channel->avatar,
                ];
            });

            return $this->successResponse("Channels retrieved", [
                'channels' => $result,
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get channels", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Send a test notification.
     */
    public function testNotification(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            // Verify settings are properly configured
            $settings = GmailNotificationSetting::on("mysql_{$parentId}")
                ->where('user_id', $userId)
                ->first();

            if (!$settings) {
                return $this->failResponse("Please configure notification settings first", [], null, 400);
            }

            if ($settings->notification_type === 'channel' && !$settings->channel_uuid) {
                return $this->failResponse("Please select a channel for notifications", [], null, 400);
            }

            $success = $this->getNotificationService()->sendTestNotification($userId, $parentId);

            if ($success) {
                return $this->successResponse("Test notification sent successfully", [
                    'sent' => true,
                ]);
            } else {
                return $this->failResponse("Failed to send test notification", [], null, 500);
            }

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to send test notification", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get notification history/logs.
     */
    public function getLogs(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $connection = "mysql_{$parentId}";

            $limit = min($request->input('limit', 50), 100);

            $logs = GmailNotificationLog::getRecentForUser($connection, $userId, $limit);

            $result = $logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'gmail_message_id' => $log->gmail_message_id,
                    'subject' => $log->subject,
                    'sender_email' => $log->sender_email,
                    'sender_name' => $log->sender_name,
                    'status' => $log->status,
                    'error_message' => $log->error_message,
                    'gmail_date' => $log->gmail_date?->toIso8601String(),
                    'notified_at' => $log->notified_at?->toIso8601String(),
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

            return $this->successResponse("Logs retrieved", [
                'logs' => $result,
                'total' => $logs->count(),
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get logs", [$exception->getMessage()], $exception, 500);
        }
    }
}
