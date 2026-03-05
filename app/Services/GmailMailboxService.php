<?php

namespace App\Services;

use App\Services\GmailOAuthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GmailMailboxService
{
    protected const GMAIL_API_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    protected $oauthService;

    public function __construct()
    {
        $this->oauthService = new GmailOAuthService();
    }

    /**
     * List emails from a specific folder/label.
     */
    public function listEmails(int $userId, string $labelId = 'INBOX', int $maxResults = 20, ?string $pageToken = null, ?string $query = null): ?array
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return null;
        }

        try {
            $params = [
                'labelIds' => $labelId,
                'maxResults' => $maxResults,
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            if ($query) {
                $params['q'] = $query;
            }

            $response = Http::withToken($accessToken)
                ->get(self::GMAIL_API_URL . '/messages', $params);

            if (!$response->successful()) {
                Log::error('Gmail API: Failed to list emails', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $messages = $data['messages'] ?? [];
            $emails = [];

            // Fetch metadata for each message
            foreach ($messages as $message) {
                $email = $this->getEmailMetadata($accessToken, $message['id']);
                if ($email) {
                    $emails[] = $email;
                }
            }

            return [
                'emails' => $emails,
                'next_page_token' => $data['nextPageToken'] ?? null,
                'result_size_estimate' => $data['resultSizeEstimate'] ?? 0,
            ];

        } catch (\Exception $e) {
            Log::error('Gmail API: List emails exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get email metadata (for list view).
     */
    protected function getEmailMetadata(string $accessToken, string $messageId): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get(self::GMAIL_API_URL . '/messages/' . $messageId, [
                    'format' => 'metadata',
                    'metadataHeaders' => ['From', 'To', 'Subject', 'Date'],
                ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            return $this->parseEmailMetadata($data);

        } catch (\Exception $e) {
            Log::error('Gmail API: Get metadata exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get full email content.
     */
    public function getEmail(int $userId, string $messageId): ?array
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->get(self::GMAIL_API_URL . '/messages/' . $messageId, [
                    'format' => 'full',
                ]);

            if (!$response->successful()) {
                Log::error('Gmail API: Failed to get email', [
                    'user_id' => $userId,
                    'message_id' => $messageId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();
            return $this->parseFullEmail($data);

        } catch (\Exception $e) {
            Log::error('Gmail API: Get email exception', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send an email.
     */
    public function sendEmail(int $userId, array $emailData): ?string
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return null;
        }

        try {
            $rawMessage = $this->buildRawEmail($emailData);

            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(self::GMAIL_API_URL . '/messages/send', [
                    'raw' => $rawMessage,
                ]);

            if (!$response->successful()) {
                Log::error('Gmail API: Failed to send email', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            return $response->json('id');

        } catch (\Exception $e) {
            Log::error('Gmail API: Send email exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Modify labels on an email.
     */
    public function modifyLabels(int $userId, string $messageId, array $addLabels = [], array $removeLabels = []): bool
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->post(self::GMAIL_API_URL . '/messages/' . $messageId . '/modify', [
                    'addLabelIds' => $addLabels,
                    'removeLabelIds' => $removeLabels,
                ]);

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('Gmail API: Modify labels exception', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Move email to trash.
     */
    public function trashEmail(int $userId, string $messageId): bool
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->post(self::GMAIL_API_URL . '/messages/' . $messageId . '/trash');

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('Gmail API: Trash email exception', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Permanently delete an email.
     */
    public function deleteEmail(int $userId, string $messageId): bool
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->delete(self::GMAIL_API_URL . '/messages/' . $messageId);

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('Gmail API: Delete email exception', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get all labels.
     */
    public function getLabels(int $userId): ?array
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->get(self::GMAIL_API_URL . '/labels');

            if (!$response->successful()) {
                return null;
            }

            $labels = $response->json('labels') ?? [];

            return array_map(function ($label) {
                return [
                    'id' => $label['id'],
                    'name' => $label['name'],
                    'type' => $label['type'] ?? 'user',
                    'messages_total' => $label['messagesTotal'] ?? 0,
                    'messages_unread' => $label['messagesUnread'] ?? 0,
                ];
            }, $labels);

        } catch (\Exception $e) {
            Log::error('Gmail API: Get labels exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse email metadata for list view.
     */
    protected function parseEmailMetadata(array $data): array
    {
        $headers = $this->extractHeaders($data['payload']['headers'] ?? []);
        $labelIds = $data['labelIds'] ?? [];

        return [
            'id' => $data['id'],
            'thread_id' => $data['threadId'],
            'from' => $this->parseEmailAddress($headers['From'] ?? ''),
            'to' => $this->parseEmailAddress($headers['To'] ?? ''),
            'subject' => $headers['Subject'] ?? '(No Subject)',
            'date' => $headers['Date'] ?? null,
            'snippet' => $data['snippet'] ?? '',
            'is_unread' => in_array('UNREAD', $labelIds),
            'is_starred' => in_array('STARRED', $labelIds),
            'is_important' => in_array('IMPORTANT', $labelIds),
            'labels' => $labelIds,
        ];
    }

    /**
     * Parse full email content.
     */
    protected function parseFullEmail(array $data): array
    {
        $headers = $this->extractHeaders($data['payload']['headers'] ?? []);
        $labelIds = $data['labelIds'] ?? [];

        $body = $this->extractEmailBody($data['payload']);
        $attachments = $this->extractAttachments($data['payload'], $data['id']);

        return [
            'id' => $data['id'],
            'thread_id' => $data['threadId'],
            'from' => $this->parseEmailAddress($headers['From'] ?? ''),
            'to' => $this->parseEmailAddress($headers['To'] ?? ''),
            'cc' => $this->parseEmailAddress($headers['Cc'] ?? ''),
            'bcc' => $this->parseEmailAddress($headers['Bcc'] ?? ''),
            'subject' => $headers['Subject'] ?? '(No Subject)',
            'date' => $headers['Date'] ?? null,
            'body_html' => $body['html'] ?? '',
            'body_text' => $body['text'] ?? '',
            'snippet' => $data['snippet'] ?? '',
            'is_unread' => in_array('UNREAD', $labelIds),
            'is_starred' => in_array('STARRED', $labelIds),
            'is_important' => in_array('IMPORTANT', $labelIds),
            'labels' => $labelIds,
            'attachments' => $attachments,
        ];
    }

    /**
     * Extract headers from payload.
     */
    protected function extractHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            $result[$header['name']] = $header['value'];
        }
        return $result;
    }

    /**
     * Parse email address string.
     */
    protected function parseEmailAddress(string $address): array
    {
        if (empty($address)) {
            return ['name' => '', 'email' => ''];
        }

        // Match "Name <email@example.com>" or just "email@example.com"
        if (preg_match('/^(.+?)\s*<(.+?)>$/', $address, $matches)) {
            return [
                'name' => trim($matches[1], '"\''),
                'email' => $matches[2],
            ];
        }

        return ['name' => '', 'email' => $address];
    }

    /**
     * Extract email body from payload.
     */
    protected function extractEmailBody(array $payload): array
    {
        $body = ['html' => '', 'text' => ''];

        // Check if body is directly in payload
        if (isset($payload['body']['data'])) {
            $content = $this->decodeBase64Url($payload['body']['data']);
            $mimeType = $payload['mimeType'] ?? 'text/plain';

            if (strpos($mimeType, 'html') !== false) {
                $body['html'] = $content;
            } else {
                $body['text'] = $content;
            }
            return $body;
        }

        // Check parts for multipart messages
        if (isset($payload['parts'])) {
            $body = $this->extractBodyFromParts($payload['parts']);
        }

        return $body;
    }

    /**
     * Extract body from multipart message parts.
     */
    protected function extractBodyFromParts(array $parts): array
    {
        $body = ['html' => '', 'text' => ''];

        foreach ($parts as $part) {
            $mimeType = $part['mimeType'] ?? '';

            if ($mimeType === 'text/html' && isset($part['body']['data'])) {
                $body['html'] = $this->decodeBase64Url($part['body']['data']);
            } elseif ($mimeType === 'text/plain' && isset($part['body']['data'])) {
                $body['text'] = $this->decodeBase64Url($part['body']['data']);
            } elseif (strpos($mimeType, 'multipart') !== false && isset($part['parts'])) {
                $nested = $this->extractBodyFromParts($part['parts']);
                if (empty($body['html']) && !empty($nested['html'])) {
                    $body['html'] = $nested['html'];
                }
                if (empty($body['text']) && !empty($nested['text'])) {
                    $body['text'] = $nested['text'];
                }
            }
        }

        return $body;
    }

    /**
     * Extract attachments from payload.
     */
    protected function extractAttachments(array $payload, string $messageId): array
    {
        $attachments = [];

        if (isset($payload['parts'])) {
            $this->findAttachments($payload['parts'], $messageId, $attachments);
        }

        return $attachments;
    }

    /**
     * Recursively find attachments in parts.
     */
    protected function findAttachments(array $parts, string $messageId, array &$attachments): void
    {
        foreach ($parts as $part) {
            if (isset($part['filename']) && !empty($part['filename']) && isset($part['body']['attachmentId'])) {
                $attachments[] = [
                    'id' => $part['body']['attachmentId'],
                    'message_id' => $messageId,
                    'filename' => $part['filename'],
                    'mime_type' => $part['mimeType'] ?? 'application/octet-stream',
                    'size' => $part['body']['size'] ?? 0,
                ];
            }

            if (isset($part['parts'])) {
                $this->findAttachments($part['parts'], $messageId, $attachments);
            }
        }
    }

    /**
     * Decode base64url encoded string.
     */
    protected function decodeBase64Url(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Build raw email for sending.
     */
    protected function buildRawEmail(array $emailData): string
    {
        $headers = [];
        $headers[] = "To: {$emailData['to']}";

        if (!empty($emailData['cc'])) {
            $headers[] = "Cc: {$emailData['cc']}";
        }

        if (!empty($emailData['bcc'])) {
            $headers[] = "Bcc: {$emailData['bcc']}";
        }

        $headers[] = "Subject: {$emailData['subject']}";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/html; charset=UTF-8";

        $rawMessage = implode("\r\n", $headers) . "\r\n\r\n" . $emailData['body'];

        return rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');
    }
}
