<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Internal command dispatch system.
 *
 * Commands are dispatched synchronously or queued. All dispatches are logged.
 *
 * Usage:
 *   $cmd = new CommandDispatchService();
 *   $cmd->dispatch('assign_lead',   ['lead_id' => 42, 'agent_id' => 5]);
 *   $cmd->dispatch('notify_agent',  ['agent_id' => 5, 'message' => 'You have a new lead']);
 */
class CommandDispatchService
{
    /** Registered command handlers. Maps command name → callable */
    private array $handlers = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Dispatch a named command with a payload.
     *
     * @param  string $command   Command name (e.g. assign_lead, dispatch_call)
     * @param  array  $payload   Arbitrary data
     * @param  bool   $async     If true, queue the command instead of running inline
     * @return array             ['success' => bool, 'result' => mixed]
     */
    public function dispatch(string $command, array $payload = [], bool $async = false): array
    {
        $this->logDispatch($command, $payload, $async);

        if ($async) {
            return $this->enqueue($command, $payload);
        }

        return $this->executeHandler($command, $payload);
    }

    /**
     * Register a custom command handler.
     *
     * @param  string   $command
     * @param  callable $handler  fn(array $payload): array
     */
    public function register(string $command, callable $handler): void
    {
        $this->handlers[$command] = $handler;
    }

    // ----------------------------------------------------------------
    // Default handlers
    // ----------------------------------------------------------------

    private function registerDefaults(): void
    {
        $this->handlers['assign_lead']    = [$this, 'handleAssignLead'];
        $this->handlers['dispatch_call']  = [$this, 'handleDispatchCall'];
        $this->handlers['notify_agent']   = [$this, 'handleNotifyAgent'];
        $this->handlers['start_campaign'] = [$this, 'handleStartCampaign'];
        $this->handlers['send_sms']       = [$this, 'handleSendSms'];
    }

    protected function handleAssignLead(array $payload): array
    {
        $leadId  = $payload['lead_id']  ?? null;
        $agentId = $payload['agent_id'] ?? null;

        if (!$leadId || !$agentId) {
            return ['success' => false, 'result' => 'assign_lead requires lead_id and agent_id'];
        }

        $updated = DB::connection('mysql_' . ($payload['client_id'] ?? 0))
            ->table('crm_leads')
            ->where('id', $leadId)
            ->update(['assigned_to' => $agentId, 'updated_at' => Carbon::now()]);

        Log::info('CommandDispatch: assign_lead', compact('leadId', 'agentId', 'updated'));
        return ['success' => true, 'result' => compact('updated')];
    }

    protected function handleDispatchCall(array $payload): array
    {
        // Placeholder — actual AMI call dispatch is handled by DialerController/CallService.
        // This entry point allows future integration via a single dispatch call.
        Log::info('CommandDispatch: dispatch_call', $payload);
        return ['success' => true, 'result' => 'call dispatch acknowledged'];
    }

    protected function handleNotifyAgent(array $payload): array
    {
        $agentId = $payload['agent_id'] ?? null;
        $message = $payload['message']  ?? '';

        if (!$agentId) {
            return ['success' => false, 'result' => 'notify_agent requires agent_id'];
        }

        // Persist system notification in client DB if client_id provided
        if (!empty($payload['client_id'])) {
            try {
                DB::connection('mysql_' . $payload['client_id'])
                    ->table('system_notifications')
                    ->insert([
                        'user_id'    => $agentId,
                        'message'    => $message,
                        'type'       => $payload['type'] ?? 'info',
                        'is_read'    => 0,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
            } catch (\Throwable $e) {
                Log::warning('CommandDispatch: notify_agent DB write failed', ['error' => $e->getMessage()]);
            }
        }

        Log::info('CommandDispatch: notify_agent', compact('agentId', 'message'));
        return ['success' => true, 'result' => 'notification dispatched'];
    }

    protected function handleStartCampaign(array $payload): array
    {
        Log::info('CommandDispatch: start_campaign', $payload);
        return ['success' => true, 'result' => 'campaign start acknowledged'];
    }

    protected function handleSendSms(array $payload): array
    {
        $to      = $payload['to']      ?? null;
        $message = $payload['message'] ?? '';

        if (!$to) {
            return ['success' => false, 'result' => 'send_sms requires to'];
        }

        $sms = new SmsGatewayService();
        return $sms->send($to, $message);
    }

    // ----------------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------------

    private function executeHandler(string $command, array $payload): array
    {
        if (!isset($this->handlers[$command])) {
            Log::warning('CommandDispatch: unknown command', ['command' => $command]);
            return ['success' => false, 'result' => "Unknown command: {$command}"];
        }

        try {
            $result = call_user_func($this->handlers[$command], $payload);
            return is_array($result) ? $result : ['success' => true, 'result' => $result];
        } catch (\Throwable $e) {
            Log::error('CommandDispatch: handler error', [
                'command' => $command,
                'error'   => $e->getMessage(),
            ]);
            return ['success' => false, 'result' => $e->getMessage()];
        }
    }

    private function enqueue(string $command, array $payload): array
    {
        // Store in the jobs table for async processing
        try {
            DB::table('jobs')->insert([
                'queue'        => 'commands',
                'payload'      => json_encode(['command' => $command, 'payload' => $payload]),
                'attempts'     => 0,
                'reserved_at'  => null,
                'available_at' => time(),
                'created_at'   => time(),
            ]);
            Log::info('CommandDispatch: enqueued', ['command' => $command]);
            return ['success' => true, 'result' => 'enqueued'];
        } catch (\Throwable $e) {
            Log::error('CommandDispatch: enqueue failed', ['error' => $e->getMessage()]);
            // Fall back to synchronous execution
            return $this->executeHandler($command, $payload);
        }
    }

    private function logDispatch(string $command, array $payload, bool $async): void
    {
        Log::info('CommandDispatch: dispatch', [
            'command' => $command,
            'async'   => $async,
            'payload' => array_diff_key($payload, array_flip(['password', 'token', 'secret'])),
        ]);
    }
}
