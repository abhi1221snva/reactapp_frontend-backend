<?php

namespace App\Jobs;

use App\Services\MerchantNotificationService;
use Illuminate\Support\Facades\Log;

class MerchantNotificationJob extends Job
{
    public int   $tries    = 2;
    public int   $backoff  = 15;

    private int    $clientId;
    private int    $leadId;
    private string $event;
    private array  $meta;

    public function __construct(int $clientId, int $leadId, string $event, array $meta = [])
    {
        $this->clientId = $clientId;
        $this->leadId   = $leadId;
        $this->event    = $event;
        $this->meta     = $meta;
    }

    public function handle(): void
    {
        try {
            switch ($this->event) {
                case 'signature':
                    MerchantNotificationService::notifySignature($this->clientId, $this->leadId);
                    break;
                case 'document_upload':
                    MerchantNotificationService::notifyDocumentUpload($this->clientId, $this->leadId, $this->meta);
                    break;
                case 'submitted':
                    MerchantNotificationService::notifyApplicationSubmitted($this->clientId, $this->leadId);
                    break;
                default:
                    Log::warning("[MerchantNotificationJob] Unknown event: {$this->event}");
            }
        } catch (\Throwable $e) {
            Log::error("[MerchantNotificationJob] Failed: {$e->getMessage()}", [
                'client_id' => $this->clientId,
                'lead_id'   => $this->leadId,
                'event'     => $this->event,
            ]);
            throw $e;
        }
    }
}
