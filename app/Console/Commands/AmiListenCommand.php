<?php

namespace App\Console\Commands;

use App\Model\Client\ExtensionLive;
use App\Services\AsteriskAmiService;
use App\Services\CampaignDialerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-running Asterisk AMI event listener.
 *
 * Run one process per Asterisk server (or per client if each has its own server).
 *
 * Usage:
 *   php artisan ami:listen --client=1 --connection=client_1
 *
 * Process manager (supervisord example):
 *   [program:ami-listen]
 *   command=php /var/www/html/branch/dialer_backend_v2/artisan ami:listen --client=1 --connection=client_1
 *   autostart=true
 *   autorestart=true
 *   stderr_logfile=/var/log/supervisor/ami-listen.err.log
 *   stdout_logfile=/var/log/supervisor/ami-listen.out.log
 *
 * AMI UserEvents this command handles:
 *   AgentAnswered  — agent picked up + entered conf; second originate → customer
 *   CallBridged    — customer answered + joined conf; push lead data to browser
 *   CallHangup     — call ended (Who: agent|customer); reset state, trigger next dial
 *   CustomerNoAnswer — customer originate failed (future use if dialplan sends it)
 *
 * OriginateResponse (built-in):
 *   ActionID cust_* + Reason != 4 — customer didn't answer; kick conf, notify agent
 *   ActionID camp_* + Reason != 4 — agent didn't answer in time; retry or mark failed
 */
class AmiListenCommand extends Command
{
    protected $signature   = 'ami:listen
                                {--client=1 : Client (tenant) ID}
                                {--connection= : Laravel DB connection name for tenant (defaults to mysql_{client})';

    protected $description = 'Start a persistent Asterisk AMI event listener for a client tenant';

    private bool $shouldStop = false;

    public function handle(): int
    {
        $clientId     = (int) $this->option('client');
        $dbConnection = $this->option('connection') ?: "mysql_{$clientId}";

        $this->info("AMI listener starting for client {$clientId} (db: {$dbConnection})");

        // Allow clean shutdown on SIGTERM / SIGINT
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT,  fn () => $this->shouldStop = true);
        }

        while (!$this->shouldStop) {
            $ami     = new AsteriskAmiService();
            $service = new CampaignDialerService($ami);

            try {
                if (!$ami->connectForClient($clientId, withEvents: true)) {
                    $this->error("AMI connection failed — retrying in 5s");
                    sleep(5);
                    continue;
                }

                $this->info("AMI connected. Listening for events...");

                $ami->listen(function (array $event) use ($ami, $service, $clientId, $dbConnection): bool {
                    if ($this->shouldStop) {
                        return false; // Signal stop to listen() loop
                    }

                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }

                    $eventName = $event['Event'] ?? '';

                    Log::debug("AMI [{$eventName}]", array_intersect_key($event, array_flip([
                        'UserEvent', 'LeadID', 'CampaignID', 'AgentExt', 'Channel',
                        'ActionID', 'Response', 'Reason',
                    ])));

                    switch ($eventName) {
                        case 'UserEvent':
                            $this->dispatchUserEvent($event, $service, $clientId, $dbConnection);
                            break;

                        case 'OriginateResponse':
                            $reason   = (int) ($event['Reason'] ?? 0);
                            $actionId = $event['ActionID'] ?? '';

                            if ($reason === 4 && str_starts_with($actionId, 'cust_')) {
                                // Customer answered — store channel for customer-only hangup
                                $channel = $event['Channel'] ?? null;
                                if ($channel && preg_match('/^cust_(.+)_\d+$/', $actionId, $m)) {
                                    $confRoom = $m[1];
                                    ExtensionLive::on($dbConnection)
                                        ->where('conf_room', $confRoom)
                                        ->where('status', 1)
                                        ->update(['customer_channel' => $channel]);
                                }
                            } elseif ($reason !== 4) {
                                if (str_starts_with($actionId, 'cust_')) {
                                    // Customer originate failed — kick conf, notify agent
                                    $service->handleCustomerOriginateFailed($event, $dbConnection, $clientId);
                                } else {
                                    // Agent originate failed — retry or mark failed
                                    $service->handleAgentNoAnswer($event, $dbConnection, $clientId);
                                }
                            }
                            break;
                    }

                    return true;
                });

            } catch (\Throwable $e) {
                Log::error("AMI listener exception: {$e->getMessage()}", [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                $this->error("AMI error: {$e->getMessage()}");
            } finally {
                $ami->disconnect();
            }

            if (!$this->shouldStop) {
                $this->warn("AMI disconnected — reconnecting in 5s");
                sleep(5);
            }
        }

        $this->info("AMI listener stopped.");
        return self::SUCCESS;
    }

    protected function dispatchUserEvent(
        array                  $event,
        CampaignDialerService  $service,
        int                    $clientId,
        string                 $dbConnection
    ): void {
        $userEvent = $event['UserEvent'] ?? '';

        switch ($userEvent) {
            case 'AgentAnswered':
                $service->handleAgentAnswered($event, $dbConnection, $clientId);
                break;

            case 'CallBridged':
                $service->handleCallBridged($event, $dbConnection, $clientId);
                break;

            case 'CallHangup':
                $service->handleCallHangup($event, $dbConnection, $clientId);
                break;

            case 'CustomerNoAnswer':
                $service->handleCustomerOriginateFailed($event, $dbConnection, $clientId);
                break;

            default:
                // Ignore unrelated UserEvents
                break;
        }
    }
}
