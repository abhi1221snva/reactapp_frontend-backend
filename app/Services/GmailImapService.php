<?php

namespace App\Services;

use App\Model\Master\GmailOAuthToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class GmailImapService
{
    protected const IMAP_HOST = 'imap.gmail.com';
    protected const IMAP_PORT = 993;

    protected $connection = null;
    protected string $email;
    protected string $accessToken;

    /**
     * Connect to Gmail IMAP using XOAUTH2.
     */
    public function connect(string $accessToken, string $email): bool
    {
        $this->email = $email;
        $this->accessToken = $accessToken;

        try {
            // Build XOAUTH2 authentication string
            $authString = $this->buildXOAuth2String($email, $accessToken);

            // Connect to Gmail IMAP
            $mailbox = sprintf(
                '{%s:%d/imap/ssl/novalidate-cert}INBOX',
                self::IMAP_HOST,
                self::IMAP_PORT
            );

            // Use imap_open with XOAUTH2
            $this->connection = @imap_open(
                $mailbox,
                $email,
                $authString,
                0,
                1,
                ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
            );

            if (!$this->connection) {
                $error = imap_last_error();
                Log::error('Gmail IMAP: Connection failed', [
                    'email' => $email,
                    'error' => $error,
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Gmail IMAP: Connection exception', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Connect using OAuth token model.
     */
    public function connectWithToken(GmailOAuthToken $token): bool
    {
        $accessToken = $token->getDecryptedAccessToken();
        if (!$accessToken) {
            return false;
        }

        return $this->connect($accessToken, $token->gmail_email);
    }

    /**
     * Get new/unread emails since a specific date.
     */
    public function getNewEmails(?Carbon $since = null, bool $unreadOnly = true): array
    {
        if (!$this->connection) {
            return [];
        }

        try {
            // Build search criteria
            $criteria = [];

            if ($unreadOnly) {
                $criteria[] = 'UNSEEN';
            }

            if ($since) {
                $criteria[] = 'SINCE "' . $since->format('d-M-Y') . '"';
            }

            $searchCriteria = !empty($criteria) ? implode(' ', $criteria) : 'ALL';

            // Search for emails
            $emails = @imap_search($this->connection, $searchCriteria, SE_UID);

            if (!$emails) {
                return [];
            }

            $results = [];
            foreach ($emails as $uid) {
                $emailData = $this->getEmailDetails($uid);
                if ($emailData) {
                    $results[] = $emailData;
                }
            }

            // Sort by date descending (newest first)
            usort($results, function ($a, $b) {
                return $b['date']->timestamp - $a['date']->timestamp;
            });

            return $results;

        } catch (\Exception $e) {
            Log::error('Gmail IMAP: Search failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get email details by UID.
     */
    public function getEmailDetails(int $uid): ?array
    {
        if (!$this->connection) {
            return null;
        }

        try {
            // Get email headers
            $headers = @imap_fetchheader($this->connection, $uid, FT_UID);
            $headerInfo = @imap_headerinfo($this->connection, imap_msgno($this->connection, $uid));

            if (!$headerInfo) {
                return null;
            }

            // Get message structure for attachments
            $structure = @imap_fetchstructure($this->connection, $uid, FT_UID);

            // Parse sender
            $from = $headerInfo->from[0] ?? null;
            $senderEmail = $from ? ($from->mailbox . '@' . $from->host) : 'unknown';
            $senderName = $from ? ($from->personal ?? $senderEmail) : 'Unknown';
            $senderName = $this->decodeMimeString($senderName);

            // Get subject
            $subject = $this->decodeMimeString($headerInfo->subject ?? '(No Subject)');

            // Get message ID
            $messageId = trim($headerInfo->message_id ?? '', '<>');
            if (empty($messageId)) {
                $messageId = 'uid_' . $uid . '_' . time();
            }

            // Get thread ID (References or In-Reply-To header)
            $threadId = $this->extractThreadId($headers);

            // Get date
            $date = isset($headerInfo->date)
                ? Carbon::parse($headerInfo->date)
                : Carbon::now();

            // Get body preview
            $preview = $this->getBodyPreview($uid, $structure);

            // Get attachments list
            $attachments = $this->getAttachmentsList($structure);

            return [
                'uid' => $uid,
                'message_id' => $messageId,
                'thread_id' => $threadId,
                'subject' => $subject,
                'sender_email' => $senderEmail,
                'sender_name' => $senderName,
                'date' => $date,
                'preview' => $preview,
                'attachments' => $attachments,
                'has_attachments' => !empty($attachments),
            ];

        } catch (\Exception $e) {
            Log::error('Gmail IMAP: Failed to get email details', [
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get body preview text.
     */
    protected function getBodyPreview(int $uid, $structure, int $maxLength = 500): string
    {
        if (!$this->connection || !$structure) {
            return '';
        }

        try {
            $body = '';

            if ($structure->type == 0) {
                // Simple text message
                $body = @imap_fetchbody($this->connection, $uid, '1', FT_UID);
                $body = $this->decodeBody($body, $structure->encoding);
            } elseif ($structure->type == 1) {
                // Multipart message - try to get plain text first
                if (isset($structure->parts)) {
                    foreach ($structure->parts as $partNum => $part) {
                        if ($part->subtype == 'PLAIN') {
                            $body = @imap_fetchbody($this->connection, $uid, (string)($partNum + 1), FT_UID);
                            $body = $this->decodeBody($body, $part->encoding);
                            break;
                        }
                    }
                    // If no plain text, try HTML
                    if (empty($body)) {
                        foreach ($structure->parts as $partNum => $part) {
                            if ($part->subtype == 'HTML') {
                                $body = @imap_fetchbody($this->connection, $uid, (string)($partNum + 1), FT_UID);
                                $body = $this->decodeBody($body, $part->encoding);
                                $body = strip_tags($body);
                                break;
                            }
                        }
                    }
                }
            }

            // Clean up the body
            $body = trim(preg_replace('/\s+/', ' ', $body));

            if (strlen($body) > $maxLength) {
                $body = substr($body, 0, $maxLength) . '...';
            }

            return $body;

        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get list of attachments.
     */
    public function getAttachmentsList($structure): array
    {
        if (!$structure) {
            return [];
        }

        $attachments = [];

        try {
            if (isset($structure->parts)) {
                foreach ($structure->parts as $partNum => $part) {
                    // Check if this part is an attachment
                    $isAttachment = false;
                    $filename = '';
                    $size = 0;

                    // Check disposition
                    if (isset($part->disposition) && strtolower($part->disposition) == 'attachment') {
                        $isAttachment = true;
                    }

                    // Get filename from parameters
                    if (isset($part->dparameters)) {
                        foreach ($part->dparameters as $param) {
                            if (strtolower($param->attribute) == 'filename') {
                                $filename = $this->decodeMimeString($param->value);
                                $isAttachment = true;
                            }
                        }
                    }

                    if (!$filename && isset($part->parameters)) {
                        foreach ($part->parameters as $param) {
                            if (strtolower($param->attribute) == 'name') {
                                $filename = $this->decodeMimeString($param->value);
                            }
                        }
                    }

                    // Get size
                    $size = $part->bytes ?? 0;

                    if ($isAttachment && $filename) {
                        $attachments[] = [
                            'filename' => $filename,
                            'size' => $size,
                            'size_formatted' => $this->formatFileSize($size),
                            'type' => $part->subtype ?? 'UNKNOWN',
                        ];
                    }

                    // Check nested parts
                    if (isset($part->parts)) {
                        $nestedAttachments = $this->getAttachmentsList($part);
                        $attachments = array_merge($attachments, $nestedAttachments);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Gmail IMAP: Failed to parse attachments', ['error' => $e->getMessage()]);
        }

        return $attachments;
    }

    /**
     * Build Gmail web URL for a message.
     */
    public function buildGmailWebLink(string $messageId, string $email): string
    {
        // Extract the user part to determine which account slot to use
        $encodedId = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($messageId));

        return sprintf(
            'https://mail.google.com/mail/u/0/#search/rfc822msgid:%s',
            urlencode('<' . $messageId . '>')
        );
    }

    /**
     * Close the IMAP connection.
     */
    public function disconnect(): void
    {
        if ($this->connection) {
            @imap_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Build XOAUTH2 authentication string.
     */
    protected function buildXOAuth2String(string $email, string $accessToken): string
    {
        return base64_encode("user={$email}\001auth=Bearer {$accessToken}\001\001");
    }

    /**
     * Decode MIME encoded string.
     */
    protected function decodeMimeString(string $string): string
    {
        $elements = imap_mime_header_decode($string);
        $result = '';
        foreach ($elements as $element) {
            $charset = ($element->charset == 'default') ? 'UTF-8' : $element->charset;
            $result .= @iconv($charset, 'UTF-8//IGNORE', $element->text);
        }
        return $result ?: $string;
    }

    /**
     * Decode email body based on encoding.
     */
    protected function decodeBody(string $body, int $encoding): string
    {
        switch ($encoding) {
            case 0: // 7BIT
            case 1: // 8BIT
                return $body;
            case 2: // BINARY
                return $body;
            case 3: // BASE64
                return base64_decode($body);
            case 4: // QUOTED-PRINTABLE
                return quoted_printable_decode($body);
            default:
                return $body;
        }
    }

    /**
     * Extract thread ID from headers.
     */
    protected function extractThreadId(string $headers): ?string
    {
        // Try to find References header
        if (preg_match('/References:\s*<([^>]+)>/i', $headers, $matches)) {
            return $matches[1];
        }
        // Try In-Reply-To header
        if (preg_match('/In-Reply-To:\s*<([^>]+)>/i', $headers, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Format file size to human readable.
     */
    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Destructor to ensure connection is closed.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
