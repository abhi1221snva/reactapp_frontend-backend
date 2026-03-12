<?php

namespace App\Jobs;

use App\Model\Client\TwilioCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TwilioIngestCallJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        private readonly int   $clientId,
        private readonly array $payload
    ) {}

    public function handle(): void
    {
        try {
            $conn = 'mysql_' . $this->clientId;

            $callSid = $this->payload['CallSid'] ?? null;

            if (!$callSid) {
                Log::warning('[TwilioIngestCallJob] Missing CallSid - skipping', [
                    'client_id' => $this->clientId,
                    'payload'   => $this->payload,
                ]);
                return;
            }

            // Normalise direction: outbound-api, outbound-dial -> outbound
            $rawDirection = strtolower($this->payload['Direction'] ?? 'inbound');
            $direction    = str_starts_with($rawDirection, 'outbound') ? 'outbound' : 'inbound';

            // Normalise status to enum values used in twilio_calls
            $allowedStatuses = ['queued', 'ringing', 'in-progress', 'completed', 'busy', 'no-answer', 'canceled', 'failed'];
            $status = strtolower($this->payload['CallStatus'] ?? 'unknown');
            if (!in_array($status, $allowedStatuses)) {
                $status = 'completed';
            }

            // Parse optional timestamps
            $startTime = null;
            $endTime   = null;
            if (!empty($this->payload['StartTime'])) {
                try {
                    $startTime = \Carbon\Carbon::parse($this->payload['StartTime'])->toDateTimeString();
                } catch (\Exception $parseEx) {
                    // leave null if unparseable
                }
            }
            if (!empty($this->payload['EndTime'])) {
                try {
                    $endTime = \Carbon\Carbon::parse($this->payload['EndTime'])->toDateTimeString();
                } catch (\Exception $parseEx) {
                    // leave null if unparseable
                }
            }

            $data = [
                'from_number' => $this->payload['From']         ?? null,
                'to_number'   => $this->payload['To']           ?? null,
                'status'      => $status,
                'direction'   => $direction,
                'duration'    => (int) ($this->payload['CallDuration'] ?? 0),
                'price'       => $this->payload['Price']        ?? null,
                'price_unit'  => $this->payload['PriceUnit']    ?? 'USD',
                'started_at'  => $startTime,
                'ended_at'    => $endTime,
                'updated_at'  => \Carbon\Carbon::now(),
            ];

            TwilioCall::on($conn)->updateOrCreate(
                ['call_sid' => $callSid],
                $data + ['created_at' => \Carbon\Carbon::now()]
            );

            Log::info('[TwilioIngestCallJob] Upserted call', [
                'client_id' => $this->clientId,
                'call_sid'  => $callSid,
                'status'    => $status,
            ]);
        } catch (\Exception $e) {
            Log::error('[TwilioIngestCallJob] Failed', [
                'client_id' => $this->clientId,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
