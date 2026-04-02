<?php

namespace App\Jobs;

use App\Models\Client\EmailParseAuditLog;
use App\Models\Client\EmailParsedApplication;
use App\Models\Client\EmailParsedAttachment;
use App\Services\EmailParserService;
use Illuminate\Support\Facades\Log;

class ProcessEmailParsedAttachmentJob extends Job
{
    protected int $attachmentId;
    protected int $userId;
    protected int $clientId;

    public $queue = 'email-parser';
    public int $tries = 2;
    public array $backoff = [30, 60];
    public int $timeout = 300;

    public function __construct(int $attachmentId, int $userId, int $clientId)
    {
        $this->attachmentId = $attachmentId;
        $this->userId = $userId;
        $this->clientId = $clientId;
    }

    public function handle(): void
    {
        $conn = "mysql_{$this->clientId}";
        $service = new EmailParserService();

        $attachment = EmailParsedAttachment::on($conn)->find($this->attachmentId);
        if (!$attachment || $attachment->parse_status !== 'pending') {
            return;
        }

        $attachment->parse_status = 'parsing';
        $attachment->save();

        try {
            // 1. Download the attachment
            $localPath = $service->downloadAttachment(
                $this->userId,
                $attachment->gmail_message_id,
                $attachment->gmail_attachment_id,
                $this->clientId,
                $attachment->filename
            );

            if (!$localPath) {
                throw new \RuntimeException('Failed to download attachment from Gmail');
            }

            $attachment->local_path = $localPath;
            $attachment->save();

            // 2. Classify the document
            $classification = $service->classifyDocument($localPath, $attachment->filename);
            $attachment->doc_type                  = $classification['doc_type'];
            $attachment->classification_confidence = $classification['confidence'];
            $attachment->classification_method     = $classification['method'];
            $attachment->save();

            EmailParseAuditLog::log(
                $conn, $this->userId, 'pdf_classified',
                'attachment', $this->attachmentId, $attachment->gmail_message_id,
                ['doc_type' => $classification['doc_type'], 'confidence' => $classification['confidence']]
            );

            // 3. Route by document type for further parsing
            switch ($attachment->doc_type) {
                case 'application':
                    $parsed = $service->parseApplication($localPath);
                    if ($parsed) {
                        $appData = array_merge($parsed, [
                            'attachment_id'    => $this->attachmentId,
                            'gmail_message_id' => $attachment->gmail_message_id,
                            'user_id'          => $this->userId,
                        ]);
                        $app = EmailParsedApplication::on($conn)->create($appData);
                        $attachment->linked_application_id = $app->id;

                        // Auto-detect: find existing lead or create new one
                        try {
                            $result = $service->autoProcessApplication($app->id, $this->clientId, $this->userId);
                            if (isset($result['lead_id'])) {
                                $attachment->linked_lead_id = $result['lead_id'];
                            }
                        } catch (\Throwable $autoEx) {
                            Log::warning('[ProcessEmailParsedAttachment] Auto-process failed (non-fatal)', [
                                'attachment_id' => $this->attachmentId,
                                'error'         => $autoEx->getMessage(),
                            ]);
                        }
                    }
                    break;

                case 'bank_statement':
                    $parsed = $service->parseBankStatement($localPath);
                    if ($parsed) {
                        $attachment->parser_response = $parsed;
                    }
                    // Auto-link to existing lead
                    $service->autoLinkDocument($this->attachmentId, $this->clientId, $this->userId);
                    break;

                case 'void_cheque':
                    $parsed = $service->parseVoidCheque($localPath);
                    if ($parsed) {
                        $attachment->parser_response = $parsed;
                    }
                    // Auto-link to existing lead
                    $service->autoLinkDocument($this->attachmentId, $this->clientId, $this->userId);
                    break;

                case 'invoice':
                case 'unknown':
                    // Try to auto-link even unknown documents
                    $service->autoLinkDocument($this->attachmentId, $this->clientId, $this->userId);
                    break;
            }

            // Reload attachment to get any updates from auto-process
            $attachment = EmailParsedAttachment::on($conn)->find($this->attachmentId);
            $attachment->parse_status = 'parsed';
            $attachment->save();

            EmailParseAuditLog::log(
                $conn, $this->userId, 'parse_success',
                'attachment', $this->attachmentId, $attachment->gmail_message_id,
                ['doc_type' => $attachment->doc_type, 'linked_lead_id' => $attachment->linked_lead_id]
            );

        } catch (\Throwable $e) {
            $attachment->parse_status  = 'failed';
            $attachment->error_message = mb_substr($e->getMessage(), 0, 2000);
            $attachment->save();

            EmailParseAuditLog::log(
                $conn, $this->userId, 'parse_failed',
                'attachment', $this->attachmentId, $attachment->gmail_message_id,
                ['error' => mb_substr($e->getMessage(), 0, 500)]
            );

            Log::error('[ProcessEmailParsedAttachment] Failed', [
                'attachment_id' => $this->attachmentId,
                'error'         => $e->getMessage(),
            ]);

            throw $e; // re-throw for queue retry
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[ProcessEmailParsedAttachment] Permanently failed', [
            'attachment_id' => $this->attachmentId,
            'client_id'     => $this->clientId,
            'error'         => $exception->getMessage(),
        ]);
    }
}
