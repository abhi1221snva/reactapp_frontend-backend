<?php

namespace App\Http\Controllers;

use App\Model\Client\CrmBankStatementSession;
use App\Services\EasifyBankStatementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EasifyWebhookController extends Controller
{
    /**
     * POST /easify/webhook/bank-statement/{clientId}
     *
     * Called by Balji when batch analysis completes.
     */
    public function bankStatementComplete(Request $request, $clientId)
    {
        $clientId  = (int) $clientId;
        $conn      = "mysql_{$clientId}";
        $batchId   = $request->input('batch_id');
        $sessions  = $request->input('sessions', []);

        Log::info('[BaljiWebhook] Received', [
            'client_id' => $clientId,
            'batch_id'  => $batchId,
            'sessions'  => count($sessions),
        ]);

        // Process each session in the batch
        if (!empty($sessions)) {
            foreach ($sessions as $sess) {
                $sessionId = $sess['session_id'] ?? null;
                if (!$sessionId) continue;
                $this->syncSession($clientId, $conn, $sessionId);
            }
        } elseif ($batchId) {
            // If sessions not provided, look up by batch_id
            $rows = CrmBankStatementSession::on($conn)
                ->where('batch_id', $batchId)
                ->whereIn('status', ['pending', 'processing'])
                ->get();

            foreach ($rows as $row) {
                $this->syncSession($clientId, $conn, $row->session_id);
            }
        }

        return response('', 200);
    }

    private function syncSession(int $clientId, string $conn, string $sessionId): void
    {
        $row = CrmBankStatementSession::on($conn)
            ->where('session_id', $sessionId)
            ->first();

        if (!$row) {
            Log::warning('[BaljiWebhook] Unknown session_id', compact('sessionId', 'clientId'));
            return;
        }

        try {
            $service = EasifyBankStatementService::forClient($clientId);
            $service->syncSessionData($sessionId);
        } catch (\Throwable $e) {
            Log::error('[BaljiWebhook] Sync failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            $row->update([
                'status'        => 'failed',
                'error_message' => 'Webhook sync failed: ' . $e->getMessage(),
            ]);
        }
    }
}
