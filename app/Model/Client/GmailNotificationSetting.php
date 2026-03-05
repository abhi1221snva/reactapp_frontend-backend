<?php

namespace App\Model\Client;

use App\Model\Client\TeamConversation;
use Illuminate\Database\Eloquent\Model;

class GmailNotificationSetting extends Model
{
    protected $table = 'gmail_notification_settings';

    protected $fillable = [
        'user_id',
        'notification_type',
        'channel_uuid',
        'is_enabled',
        'include_subject',
        'include_sender',
        'include_preview',
        'preview_length',
        'include_attachments_list',
        'include_email_link',
        'filter_labels',
        'exclude_labels',
        'only_unread',
        'ai_analysis_enabled',
        'include_ai_summary',
        'include_priority',
        'include_suggested_reply',
        'min_priority_notify'
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'include_subject' => 'boolean',
        'include_sender' => 'boolean',
        'include_preview' => 'boolean',
        'include_attachments_list' => 'boolean',
        'include_email_link' => 'boolean',
        'only_unread' => 'boolean',
        'filter_labels' => 'array',
        'exclude_labels' => 'array',
        'ai_analysis_enabled' => 'boolean',
        'include_ai_summary' => 'boolean',
        'include_priority' => 'boolean',
        'include_suggested_reply' => 'boolean',
    ];

    /**
     * Check if notifications should be sent via DM.
     */
    public function isDmNotification(): bool
    {
        return $this->notification_type === 'dm';
    }

    /**
     * Check if notifications should be sent to a channel.
     */
    public function isChannelNotification(): bool
    {
        return $this->notification_type === 'channel';
    }

    /**
     * Get settings for a user, creating defaults if not exists.
     */
    public static function getOrCreateForUser(int $userId, string $connection): self
    {
        $settings = static::on($connection)
            ->where('user_id', $userId)
            ->first();

        if (!$settings) {
            $settings = new static();
            $settings->setConnection($connection);
            $settings->user_id = $userId;
            $settings->notification_type = 'dm';
            $settings->is_enabled = false; // Disabled by default until Gmail is connected
            $settings->include_subject = true;
            $settings->include_sender = true;
            $settings->include_preview = true;
            $settings->preview_length = 200;
            $settings->include_attachments_list = true;
            $settings->include_email_link = true;
            $settings->only_unread = true;
            // AI Analysis defaults
            $settings->ai_analysis_enabled = true;
            $settings->include_ai_summary = true;
            $settings->include_priority = true;
            $settings->include_suggested_reply = true;
            $settings->min_priority_notify = 'low';
            $settings->save();
        }

        return $settings;
    }

    /**
     * Check if AI analysis is enabled for this user.
     */
    public function isAiAnalysisEnabled(): bool
    {
        return $this->ai_analysis_enabled ?? true;
    }

    /**
     * Get the team conversation for channel notifications.
     */
    public function getConversation()
    {
        if (!$this->channel_uuid) {
            return null;
        }

        return TeamConversation::on($this->getConnectionName())
            ->where('uuid', $this->channel_uuid)
            ->where('is_active', true)
            ->first();
    }
}
