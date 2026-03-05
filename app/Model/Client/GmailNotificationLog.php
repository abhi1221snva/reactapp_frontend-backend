<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GmailNotificationLog extends Model
{
    protected $table = 'gmail_notification_logs';

    protected $fillable = [
        'user_id',
        'gmail_message_id',
        'thread_id',
        'subject',
        'sender_email',
        'sender_name',
        'team_message_id',
        'status',
        'error_message',
        'gmail_date',
        'notified_at'
    ];

    protected $casts = [
        'gmail_date' => 'datetime',
        'notified_at' => 'datetime',
    ];

    /**
     * Check if a Gmail message has already been notified.
     */
    public static function wasAlreadyNotified(string $connection, int $userId, string $gmailMessageId): bool
    {
        return static::on($connection)
            ->where('user_id', $userId)
            ->where('gmail_message_id', $gmailMessageId)
            ->whereIn('status', ['sent', 'skipped'])
            ->exists();
    }

    /**
     * Create a log entry for a sent notification.
     */
    public static function logSent(
        string $connection,
        int $userId,
        string $gmailMessageId,
        ?string $threadId,
        ?string $subject,
        string $senderEmail,
        ?string $senderName,
        int $teamMessageId,
        Carbon $gmailDate
    ): self {
        $log = new static();
        $log->setConnection($connection);
        $log->user_id = $userId;
        $log->gmail_message_id = $gmailMessageId;
        $log->thread_id = $threadId;
        $log->subject = $subject;
        $log->sender_email = $senderEmail;
        $log->sender_name = $senderName;
        $log->team_message_id = $teamMessageId;
        $log->status = 'sent';
        $log->gmail_date = $gmailDate;
        $log->notified_at = Carbon::now();
        $log->save();

        return $log;
    }

    /**
     * Create a log entry for a failed notification.
     */
    public static function logFailed(
        string $connection,
        int $userId,
        string $gmailMessageId,
        ?string $threadId,
        ?string $subject,
        string $senderEmail,
        ?string $senderName,
        Carbon $gmailDate,
        string $errorMessage
    ): self {
        $log = new static();
        $log->setConnection($connection);
        $log->user_id = $userId;
        $log->gmail_message_id = $gmailMessageId;
        $log->thread_id = $threadId;
        $log->subject = $subject;
        $log->sender_email = $senderEmail;
        $log->sender_name = $senderName;
        $log->status = 'failed';
        $log->error_message = $errorMessage;
        $log->gmail_date = $gmailDate;
        $log->save();

        return $log;
    }

    /**
     * Create a log entry for a skipped notification.
     */
    public static function logSkipped(
        string $connection,
        int $userId,
        string $gmailMessageId,
        ?string $threadId,
        ?string $subject,
        string $senderEmail,
        ?string $senderName,
        Carbon $gmailDate,
        string $reason
    ): self {
        $log = new static();
        $log->setConnection($connection);
        $log->user_id = $userId;
        $log->gmail_message_id = $gmailMessageId;
        $log->thread_id = $threadId;
        $log->subject = $subject;
        $log->sender_email = $senderEmail;
        $log->sender_name = $senderName;
        $log->status = 'skipped';
        $log->error_message = $reason;
        $log->gmail_date = $gmailDate;
        $log->save();

        return $log;
    }

    /**
     * Get recent logs for a user.
     */
    public static function getRecentForUser(string $connection, int $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return static::on($connection)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
