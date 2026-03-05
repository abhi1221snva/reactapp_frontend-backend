<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class GmailAiAnalysis extends Model
{
    protected $table = 'gmail_ai_analysis';

    protected $fillable = [
        'user_id',
        'gmail_message_id',
        'summary',
        'priority',
        'category',
        'urgency_reason',
        'suggested_actions',
        'suggested_reply',
        'sentiment',
        'key_points',
        'raw_response'
    ];

    protected $casts = [
        'suggested_actions' => 'array',
        'key_points' => 'array',
        'raw_response' => 'array',
    ];

    /**
     * Get analysis for a specific message.
     */
    public static function getForMessage(string $connection, int $userId, string $gmailMessageId): ?self
    {
        return static::on($connection)
            ->where('user_id', $userId)
            ->where('gmail_message_id', $gmailMessageId)
            ->first();
    }

    /**
     * Check if analysis exists for a message.
     */
    public static function hasAnalysis(string $connection, int $userId, string $gmailMessageId): bool
    {
        return static::on($connection)
            ->where('user_id', $userId)
            ->where('gmail_message_id', $gmailMessageId)
            ->exists();
    }

    /**
     * Get recent analyses for a user.
     */
    public static function getRecentForUser(string $connection, int $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return static::on($connection)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get high priority analyses for a user.
     */
    public static function getHighPriorityForUser(string $connection, int $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return static::on($connection)
            ->where('user_id', $userId)
            ->where('priority', 'high')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Check if priority is high.
     */
    public function isHighPriority(): bool
    {
        return $this->priority === 'high';
    }

    /**
     * Check if priority is at least medium.
     */
    public function isMediumOrHigher(): bool
    {
        return in_array($this->priority, ['high', 'medium']);
    }

    /**
     * Get formatted key points as string.
     */
    public function getKeyPointsAsString(): string
    {
        if (empty($this->key_points)) {
            return '';
        }

        return implode("\n", array_map(fn($p) => "- {$p}", $this->key_points));
    }

    /**
     * Get formatted suggested actions as string.
     */
    public function getSuggestedActionsAsString(): string
    {
        if (empty($this->suggested_actions)) {
            return '';
        }

        return implode("\n", array_map(fn($a) => "- {$a}", $this->suggested_actions));
    }

    /**
     * Get priority emoji for display.
     */
    public function getPriorityEmoji(): string
    {
        return match ($this->priority) {
            'high' => '🔴',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
    }

    /**
     * Get sentiment emoji for display.
     */
    public function getSentimentEmoji(): string
    {
        return match ($this->sentiment) {
            'positive' => '😊',
            'negative' => '😟',
            default => '😐',
        };
    }
}
