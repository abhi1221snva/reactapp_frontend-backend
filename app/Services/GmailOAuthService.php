<?php

namespace App\Services;

use App\Model\Master\GmailOAuthToken;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GmailOAuthService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected array $scopes;

    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    protected const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    protected const GMAIL_API_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct()
    {
        $this->clientId = config('services.google.client_id');
        $this->clientSecret = config('services.google.client_secret');
        $this->redirectUri = config('services.google.gmail_redirect', config('services.google.redirect'));
        $this->scopes = config('services.google.gmail_scopes', [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.modify',
            'https://www.googleapis.com/auth/gmail.send',
            'email',
            'profile',
        ]);
    }

    /**
     * Generate the Google OAuth authorization URL.
     */
    public function getAuthorizationUrl(int $userId): string
    {
        $state = base64_encode(json_encode([
            'user_id' => $userId,
            'nonce' => Str::random(32),
        ]));

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Parse and validate the state parameter.
     */
    public function parseState(string $state): ?array
    {
        try {
            $decoded = json_decode(base64_decode($state), true);
            if (isset($decoded['user_id'], $decoded['nonce'])) {
                return $decoded;
            }
        } catch (\Exception $e) {
            Log::error('Gmail OAuth: Invalid state parameter', ['error' => $e->getMessage()]);
        }
        return null;
    }

    /**
     * Exchange authorization code for access and refresh tokens.
     */
    public function exchangeCodeForTokens(string $code, int $userId): ?GmailOAuthToken
    {
        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri,
            ]);

            if (!$response->successful()) {
                Log::error('Gmail OAuth: Token exchange failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            // Get user email from Google
            $email = $this->getUserEmail($data['access_token']);
            if (!$email) {
                Log::error('Gmail OAuth: Could not fetch user email');
                return null;
            }

            // Create or update the token
            $token = GmailOAuthToken::updateOrCreate(
                ['user_id' => $userId],
                [
                    'gmail_email' => $email,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'token_expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
                    'scope' => $data['scope'] ?? implode(' ', $this->scopes),
                    'is_active' => true,
                    'last_sync_at' => null,
                    'last_history_id' => null,
                ]
            );

            return $token;

        } catch (\Exception $e) {
            Log::error('Gmail OAuth: Token exchange exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Refresh an expired access token.
     */
    public function refreshAccessToken(GmailOAuthToken $token): ?GmailOAuthToken
    {
        $refreshToken = $token->getDecryptedRefreshToken();
        if (!$refreshToken) {
            Log::error('Gmail OAuth: No refresh token available', ['user_id' => $token->user_id]);
            $token->deactivate();
            return null;
        }

        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if (!$response->successful()) {
                Log::error('Gmail OAuth: Token refresh failed', [
                    'user_id' => $token->user_id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // If refresh fails with 400/401, the refresh token is invalid
                if (in_array($response->status(), [400, 401])) {
                    $token->deactivate();
                }
                return null;
            }

            $data = $response->json();

            // Update token - note: refresh token might not be returned
            $token->access_token = $data['access_token'];
            $token->token_expires_at = Carbon::now()->addSeconds($data['expires_in'] ?? 3600);
            if (isset($data['refresh_token'])) {
                $token->refresh_token = $data['refresh_token'];
            }
            $token->save();

            return $token;

        } catch (\Exception $e) {
            Log::error('Gmail OAuth: Token refresh exception', [
                'user_id' => $token->user_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get a valid access token, refreshing if necessary.
     */
    public function getValidAccessToken(int $userId): ?string
    {
        $token = GmailOAuthToken::getActiveForUser($userId);
        if (!$token) {
            return null;
        }

        if ($token->isExpiringSoon()) {
            $token = $this->refreshAccessToken($token);
            if (!$token) {
                return null;
            }
        }

        return $token->getDecryptedAccessToken();
    }

    /**
     * Revoke access and remove the token.
     */
    public function revokeAccess(int $userId): bool
    {
        $token = GmailOAuthToken::getActiveForUser($userId);
        if (!$token) {
            return true; // Already revoked
        }

        try {
            $accessToken = $token->getDecryptedAccessToken();
            if ($accessToken) {
                // Attempt to revoke at Google (best effort)
                Http::asForm()->post(self::REVOKE_URL, [
                    'token' => $accessToken,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Gmail OAuth: Revoke request failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        // Delete the token regardless
        $token->delete();

        return true;
    }

    /**
     * Get the user's email address from Google.
     */
    protected function getUserEmail(string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)->get(self::USERINFO_URL);

            if ($response->successful()) {
                return $response->json('email');
            }
        } catch (\Exception $e) {
            Log::error('Gmail OAuth: Failed to fetch user email', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Check if a user has an active Gmail connection.
     */
    public function hasActiveConnection(int $userId): bool
    {
        return GmailOAuthToken::where('user_id', $userId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get connection status for a user.
     */
    public function getConnectionStatus(int $userId): array
    {
        $token = GmailOAuthToken::getActiveForUser($userId);

        if (!$token) {
            return [
                'connected' => false,
                'email' => null,
                'last_sync' => null,
            ];
        }

        return [
            'connected' => true,
            'email' => $token->gmail_email,
            'last_sync' => $token->last_sync_at?->toIso8601String(),
            'token_valid' => !$token->isExpired(),
            'watch_expiration' => $token->watch_expiration?->toIso8601String(),
        ];
    }

    /**
     * Set up Gmail push notifications (watch) for a user.
     *
     * This registers a watch on the user's Gmail inbox to receive
     * push notifications via Google Cloud Pub/Sub when new emails arrive.
     *
     * @param int $userId The user ID
     * @return array|null Watch response data or null on failure
     */
    public function setupGmailWatch(int $userId): ?array
    {
        $accessToken = $this->getValidAccessToken($userId);
        if (!$accessToken) {
            Log::error('Gmail Watch: No valid access token', ['user_id' => $userId]);
            return null;
        }

        $topicName = env('GMAIL_PUBSUB_TOPIC');
        if (!$topicName) {
            Log::error('Gmail Watch: GMAIL_PUBSUB_TOPIC not configured');
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->post(self::GMAIL_API_URL . '/watch', [
                    'topicName' => $topicName,
                    'labelIds' => ['INBOX'],
                    'labelFilterAction' => 'include',
                ]);

            if (!$response->successful()) {
                Log::error('Gmail Watch: Setup failed', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            // Update token with watch info
            $token = GmailOAuthToken::getActiveForUser($userId);
            if ($token) {
                $token->last_history_id = $data['historyId'] ?? null;
                $token->watch_expiration = isset($data['expiration'])
                    ? Carbon::createFromTimestampMs($data['expiration'])
                    : null;
                $token->save();
            }

            Log::info('Gmail Watch: Setup successful', [
                'user_id' => $userId,
                'history_id' => $data['historyId'] ?? null,
                'expiration' => $data['expiration'] ?? null,
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Gmail Watch: Exception during setup', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Renew Gmail watch for a user.
     *
     * Watch expires after 7 days, so this should be called periodically.
     */
    public function renewGmailWatch(int $userId): ?array
    {
        return $this->setupGmailWatch($userId);
    }

    /**
     * Stop Gmail watch for a user.
     */
    public function stopGmailWatch(int $userId): bool
    {
        $accessToken = $this->getValidAccessToken($userId);
        if (!$accessToken) {
            return true; // No token means no active watch
        }

        try {
            $response = Http::withToken($accessToken)
                ->post(self::GMAIL_API_URL . '/stop');

            // Clear watch info from token
            $token = GmailOAuthToken::getActiveForUser($userId);
            if ($token) {
                $token->watch_expiration = null;
                $token->save();
            }

            if (!$response->successful() && $response->status() !== 404) {
                Log::warning('Gmail Watch: Stop request failed', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Gmail Watch: Exception during stop', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if a user's watch is expiring soon (within 24 hours).
     */
    public function isWatchExpiringSoon(int $userId, int $hoursThreshold = 24): bool
    {
        $token = GmailOAuthToken::getActiveForUser($userId);
        if (!$token || !$token->watch_expiration) {
            return true; // No watch set up
        }

        return $token->watch_expiration->subHours($hoursThreshold)->isPast();
    }

    /**
     * Get all users with expiring watches.
     */
    public function getUsersWithExpiringWatches(int $hoursThreshold = 24): array
    {
        $threshold = Carbon::now()->addHours($hoursThreshold);

        return GmailOAuthToken::where('is_active', true)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('watch_expiration')
                    ->orWhere('watch_expiration', '<', $threshold);
            })
            ->pluck('user_id')
            ->toArray();
    }

    /**
     * Get new emails since a specific history ID using Gmail API.
     *
     * @param int $userId The user ID
     * @param string|null $startHistoryId The history ID to start from
     * @return array Array of email data
     */
    public function getNewEmailsSinceHistory(int $userId, ?string $startHistoryId = null): array
    {
        $accessToken = $this->getValidAccessToken($userId);
        if (!$accessToken) {
            Log::error('Gmail API: No valid access token', ['user_id' => $userId]);
            return [];
        }

        $emails = [];

        try {
            // If no history ID, get recent messages directly
            if (!$startHistoryId) {
                return $this->getRecentEmails($userId, 10);
            }

            // Get history changes since the given ID
            $response = Http::withToken($accessToken)
                ->get(self::GMAIL_API_URL . '/history', [
                    'startHistoryId' => $startHistoryId,
                    'historyTypes' => 'messageAdded',
                    'labelId' => 'INBOX',
                ]);

            if (!$response->successful()) {
                // If history is too old, fetch recent messages instead
                if ($response->status() === 404) {
                    Log::info('Gmail API: History expired, fetching recent emails', ['user_id' => $userId]);
                    return $this->getRecentEmails($userId, 10);
                }

                Log::error('Gmail API: History fetch failed', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            $messageIds = [];

            // Extract message IDs from history
            if (isset($data['history'])) {
                foreach ($data['history'] as $history) {
                    if (isset($history['messagesAdded'])) {
                        foreach ($history['messagesAdded'] as $added) {
                            $messageIds[] = $added['message']['id'];
                        }
                    }
                }
            }

            // Fetch full details for each message
            foreach (array_unique($messageIds) as $messageId) {
                $emailData = $this->getEmailDetails($userId, $messageId);
                if ($emailData) {
                    $emails[] = $emailData;
                }
            }

            // Update last history ID
            if (isset($data['historyId'])) {
                $token = GmailOAuthToken::getActiveForUser($userId);
                if ($token) {
                    $token->last_history_id = $data['historyId'];
                    $token->save();
                }
            }

        } catch (\Exception $e) {
            Log::error('Gmail API: Exception fetching history', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return $emails;
    }

    /**
     * Get recent emails from inbox.
     */
    public function getRecentEmails(int $userId, int $maxResults = 10): array
    {
        $accessToken = $this->getValidAccessToken($userId);
        if (!$accessToken) {
            return [];
        }

        $emails = [];

        try {
            $response = Http::withToken($accessToken)
                ->get(self::GMAIL_API_URL . '/messages', [
                    'maxResults' => $maxResults,
                    'labelIds' => 'INBOX',
                    'q' => 'is:unread',
                ]);

            if (!$response->successful()) {
                Log::error('Gmail API: Messages list failed', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                ]);
                return [];
            }

            $data = $response->json();

            if (isset($data['messages'])) {
                foreach ($data['messages'] as $message) {
                    $emailData = $this->getEmailDetails($userId, $message['id']);
                    if ($emailData) {
                        $emails[] = $emailData;
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Gmail API: Exception fetching messages', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return $emails;
    }

    /**
     * Get full email details by message ID.
     */
    public function getEmailDetails(int $userId, string $messageId): ?array
    {
        $accessToken = $this->getValidAccessToken($userId);
        if (!$accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->get(self::GMAIL_API_URL . "/messages/{$messageId}", [
                    'format' => 'full',
                ]);

            if (!$response->successful()) {
                Log::error('Gmail API: Message fetch failed', [
                    'user_id' => $userId,
                    'message_id' => $messageId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $message = $response->json();

            return $this->parseGmailMessage($message);

        } catch (\Exception $e) {
            Log::error('Gmail API: Exception fetching message', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse Gmail API message response into a standardized format.
     */
    protected function parseGmailMessage(array $message): array
    {
        $headers = $this->extractHeaders($message['payload']['headers'] ?? []);

        // Get body content
        $body = $this->extractBody($message['payload']);

        // Parse sender
        $from = $headers['from'] ?? '';
        $senderName = $from;
        $senderEmail = $from;

        if (preg_match('/^(.+?)\s*<(.+?)>$/', $from, $matches)) {
            $senderName = trim($matches[1], '"');
            $senderEmail = $matches[2];
        } elseif (filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $senderEmail = $from;
            $senderName = $from;
        }

        // Get attachments
        $attachments = $this->extractAttachments($message['payload']);

        // Parse date safely
        $date = Carbon::now();
        if (!empty($message['internalDate'])) {
            try {
                $date = Carbon::createFromTimestampMs((int) $message['internalDate']);
            } catch (\Exception $e) {
                Log::warning('Gmail API: Failed to parse date', [
                    'internalDate' => $message['internalDate'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'message_id' => $message['id'],
            'thread_id' => $message['threadId'],
            'subject' => $headers['subject'] ?? '(no subject)',
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'date' => $date,
            'body' => $body,
            'preview' => $message['snippet'] ?? '',
            'attachments' => $attachments,
            'labels' => $message['labelIds'] ?? [],
            'is_unread' => in_array('UNREAD', $message['labelIds'] ?? []),
        ];
    }

    /**
     * Extract headers from Gmail message payload.
     */
    protected function extractHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            $name = strtolower($header['name']);
            $result[$name] = $header['value'];
        }
        return $result;
    }

    /**
     * Extract body content from Gmail message payload.
     */
    protected function extractBody(array $payload): string
    {
        // Check if body is directly in payload
        if (!empty($payload['body']['data'])) {
            return $this->decodeBase64Url($payload['body']['data']);
        }

        // Check parts for text/plain or text/html
        if (!empty($payload['parts'])) {
            foreach ($payload['parts'] as $part) {
                if ($part['mimeType'] === 'text/plain' && !empty($part['body']['data'])) {
                    return $this->decodeBase64Url($part['body']['data']);
                }
            }

            // Fallback to HTML if no plain text
            foreach ($payload['parts'] as $part) {
                if ($part['mimeType'] === 'text/html' && !empty($part['body']['data'])) {
                    return strip_tags($this->decodeBase64Url($part['body']['data']));
                }
            }

            // Check nested parts (multipart messages)
            foreach ($payload['parts'] as $part) {
                if (!empty($part['parts'])) {
                    $nestedBody = $this->extractBody($part);
                    if ($nestedBody) {
                        return $nestedBody;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Extract attachments from Gmail message payload.
     */
    protected function extractAttachments(array $payload): array
    {
        $attachments = [];

        if (!empty($payload['parts'])) {
            foreach ($payload['parts'] as $part) {
                if (!empty($part['filename']) && !empty($part['body']['attachmentId'])) {
                    $size = $part['body']['size'] ?? 0;
                    $attachments[] = [
                        'filename' => $part['filename'],
                        'mime_type' => $part['mimeType'],
                        'size' => $size,
                        'size_formatted' => $this->formatFileSize($size),
                        'attachment_id' => $part['body']['attachmentId'],
                    ];
                }

                // Check nested parts
                if (!empty($part['parts'])) {
                    $nestedAttachments = $this->extractAttachments($part);
                    $attachments = array_merge($attachments, $nestedAttachments);
                }
            }
        }

        return $attachments;
    }

    /**
     * Decode base64url encoded string.
     */
    protected function decodeBase64Url(string $data): string
    {
        $data = str_replace(['-', '_'], ['+', '/'], $data);
        return base64_decode($data);
    }

    /**
     * Format file size in human-readable format.
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * Build Gmail web link for a message.
     */
    public function buildGmailWebLink(string $messageId, string $email): string
    {
        return "https://mail.google.com/mail/u/0/#inbox/{$messageId}";
    }
}
