<?php

namespace App\Jobs;

use App\Model\Client\PlivoSms;
use App\Services\PlivoService;
use Illuminate\Support\Facades\Log;

class PlivoBulkSmsJob extends Job
{
    private int    $clientId;
    private int    $agentId;
    private array  $recipients;
    private string $from;
    private string $body;
    private ?int   $campaignId;

    public function __construct(
        int    $clientId,
        int    $agentId,
        array  $recipients,
        string $from,
        string $body,
        ?int   $campaignId = null
    ) {
        $this->clientId   = $clientId;
        $this->agentId    = $agentId;
        $this->recipients = $recipients;
        $this->from       = $from;
        $this->body       = $body;
        $this->campaignId = $campaignId;
    }

    public function handle(): void
    {
        $conn = "mysql_{$this->clientId}";

        try {
            $service = PlivoService::forClient($this->clientId);
        } catch (\Exception $e) {
            Log::error('PlivoBulkSmsJob: cannot build service', ['err' => $e->getMessage()]);
            return;
        }

        foreach ($this->recipients as $to) {
            try {
                $data = $service->sendSms($to, $this->from, $this->body);

                PlivoSms::on($conn)->create([
                    'message_uuid' => $data['message_uuid'],
                    'from_number'  => $this->from,
                    'to_number'    => $to,
                    'body'         => $this->body,
                    'direction'    => 'outbound',
                    'status'       => $data['status'],
                    'campaign_id'  => $this->campaignId,
                    'agent_id'     => $this->agentId,
                    'sent_at'      => \Carbon\Carbon::now(),
                ]);

            } catch (\Exception $e) {
                Log::warning('PlivoBulkSmsJob: SMS failed', ['to' => $to, 'err' => $e->getMessage()]);
                // Continue to next recipient on failure
            }
        }
    }
}