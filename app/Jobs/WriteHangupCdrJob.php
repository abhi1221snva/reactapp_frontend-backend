<?php

namespace App\Jobs;

use App\Model\Client\ExtensionLive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WriteHangupCdrJob extends Job
{
    public int    $tries = 3;
    public int    $backoff = 5;

    private int    $clientId;
    private string $extension;
    private int    $campaignId;
    private int    $leadId;
    private string $confRoom;
    private ?string $callStartedAt;

    public function __construct(
        int     $clientId,
        string  $extension,
        int     $campaignId,
        int     $leadId,
        string  $confRoom,
        ?string $callStartedAt
    ) {
        $this->clientId       = $clientId;
        $this->extension      = $extension;
        $this->campaignId     = $campaignId;
        $this->leadId         = $leadId;
        $this->confRoom       = $confRoom;
        $this->callStartedAt  = $callStartedAt;
    }

    public function handle(): void
    {
        $db = "mysql_{$this->clientId}";

        try {
            // Resolve customer phone number from list_data
            $phone   = null;
            $leadRow = DB::connection($db)->table('list_data')->where('id', $this->leadId)->first();

            if ($leadRow) {
                $dialCol = DB::connection($db)
                    ->table('list_header')
                    ->where('list_id', $leadRow->list_id)
                    ->where('is_dialing', 1)
                    ->value('column_name');
                $phone = $dialCol ? ($leadRow->$dialCol ?? null) : null;
            }

            // Calculate duration
            $duration  = null;
            $startTime = now();
            $endTime   = now();

            if (!empty($this->callStartedAt)) {
                try {
                    $startTime = \Carbon\Carbon::parse($this->callStartedAt);
                    $duration  = (int) $startTime->diffInSeconds($endTime);
                } catch (\Throwable $e) {
                    // Non-fatal
                }
            }

            // Recording filename matching Asterisk MixMonitor pattern
            $recording = null;
            if ($startTime && $phone) {
                $ts        = $startTime instanceof \Carbon\Carbon ? $startTime->format('YmdHis') : \Carbon\Carbon::parse($startTime)->format('YmdHis');
                $cleanNum  = preg_replace('/\D/', '', $phone);
                $recording = "{$this->extension}-{$cleanNum}-{$this->leadId}-{$ts}.wav";
            }

            // Insert CDR + clear extension_live in a transaction
            DB::connection($db)->transaction(function () use ($db, $phone, $duration, $startTime, $endTime, $recording) {
                DB::connection($db)->table('cdr')->insert([
                    'extension'      => $this->extension,
                    'route'          => 'OUT',
                    'type'           => 'dialer',
                    'number'         => preg_replace('/\D/', '', $phone ?? '0'),
                    'channel'        => '',
                    'duration'       => $duration,
                    'start_time'     => $startTime,
                    'end_time'       => $endTime,
                    'call_recording' => $recording,
                    'campaign_id'    => $this->campaignId,
                    'lead_id'        => $this->leadId,
                ]);

                ExtensionLive::on($db)
                    ->where('extension', $this->extension)
                    ->update([
                        'status'           => 0,
                        'lead_id'          => null,
                        'customer_channel' => null,
                    ]);
            });

            Log::info('CDR written for WebRTC hang-up (queued)', [
                'extension'   => $this->extension,
                'lead_id'     => $this->leadId,
                'campaign_id' => $this->campaignId,
                'client_id'   => $this->clientId,
                'duration'    => $duration,
            ]);
        } catch (\Throwable $e) {
            Log::error('WriteHangupCdrJob failed', [
                'error'     => $e->getMessage(),
                'extension' => $this->extension,
                'client_id' => $this->clientId,
                'lead_id'   => $this->leadId,
            ]);
            throw $e; // Let the queue retry
        }
    }
}
