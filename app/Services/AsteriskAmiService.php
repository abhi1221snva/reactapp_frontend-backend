<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asterisk AMI (Asterisk Management Interface) client.
 *
 * Connects to the AMI socket, logs in, sends actions, and streams events.
 * Credentials are loaded from the master DB asterisk_server table, using the
 * client's user_asterisk_mapping when available.
 *
 * Usage (one-shot action):
 *   $ami = new AsteriskAmiService();
 *   $ami->connectForClient($clientId);
 *   $ami->originate([...]);
 *   $ami->disconnect();
 *
 * Usage (persistent event loop — in AmiListenCommand):
 *   $ami->connectForClient($clientId, withEvents: true);
 *   $ami->listen(function(array $event) { ... return true; });
 */
class AsteriskAmiService
{
    /** @var resource|null */
    protected $socket = null;

    protected string $host   = '127.0.0.1';
    protected int    $port   = 5038;
    protected string $username = '';
    protected string $secret   = '';
    protected string $trunk    = '';  // outbound trunk name (from asterisk_server.trunk)

    protected bool $connected = false;

    // -------------------------------------------------------------------------
    // Connection lifecycle
    // -------------------------------------------------------------------------

    /**
     * Load AMI credentials from master DB for the given client and connect.
     *
     * @param bool $withEvents  Pass true for long-running listeners that need AMI events.
     *                          Defaults to false (action-only mode) so that readPacket()
     *                          and readCommandOutput() receive clean responses.
     */
    public function connectForClient(int $clientId, bool $withEvents = false): bool
    {
        $server = $this->resolveServer($clientId);

        if (!$server) {
            Log::error("AsteriskAmiService: no Asterisk server found for client $clientId");
            return false;
        }

        $this->host     = $server->host;
        $this->username = $server->user;
        $this->secret   = $server->secret;
        $this->trunk    = $server->trunk ?? 'outbound-trunk';

        return $this->connect($withEvents);
    }

    /**
     * Connect using current host/username/secret.
     *
     * @param bool $withEvents  When false (default), events are disabled so the socket
     *                          only receives direct responses to actions we send.
     *                          When true, Asterisk streams events (for ami:listen).
     */
    public function connect(bool $withEvents = false): bool
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);

        if (!$this->socket) {
            Log::error("AsteriskAmiService: fsockopen failed — $errstr ($errno)");
            return false;
        }

        stream_set_timeout($this->socket, 5);

        // Consume banner line: "Asterisk Call Manager/x.x"
        fgets($this->socket, 512);

        // Login — events off by default so responses are clean for action-only connections
        $this->sendRaw([
            'Action'   => 'Login',
            'Username' => $this->username,
            'Secret'   => $this->secret,
            'Events'   => $withEvents ? 'on' : 'off',
        ]);

        $response = $this->readPacket();

        if (($response['Response'] ?? '') !== 'Success') {
            Log::error('AsteriskAmiService: AMI login failed', $response ?? []);
            $this->forceClose();
            return false;
        }

        $this->connected = true;
        Log::info("AsteriskAmiService: connected to {$this->host}" . ($withEvents ? ' (events on)' : ''));
        return true;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @$this->sendRaw(['Action' => 'Logoff']);
        }
        $this->forceClose();
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null;
    }

    public function getTrunk(): string
    {
        return $this->trunk;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Asynchronously originate a call.
     * Returns the ActionID string; result comes via OriginateResponse event.
     *
     * @param  array  $params  AMI Originate fields (Channel, Context, Exten, Priority,
     *                          CallerID, Timeout, Variable[], etc.)
     */
    public function originate(array $params): string
    {
        $actionId = 'dialer_' . uniqid('', true);

        $this->sendRaw(array_merge([
            'Action'   => 'Originate',
            'ActionID' => $actionId,
            'Async'    => 'true',
        ], $params));

        return $actionId;
    }

    /**
     * Hangup a channel by name (fire-and-forget).
     *
     * AMI Hangup is async — Asterisk processes it immediately. We don't read
     * the response because even with Events:off, reading can block or pick up
     * stale data that interferes with subsequent commands.
     */
    public function hangup(string $channel, int $cause = 16): void
    {
        error_log("AMI >> Hangup Channel={$channel} Cause={$cause}");
        $this->sendRaw([
            'Action'  => 'Hangup',
            'Channel' => $channel,
            'Cause'   => (string) $cause,
        ]);
        error_log("AMI >> Hangup sent OK for {$channel}");
    }

    /**
     * Kick all participants from a ConfBridge room, tearing down the conference.
     */
    public function confbridgeKickAll(string $confRoom): void
    {
        $this->sendRaw([
            'Action'  => 'Command',
            'Command' => "confbridge kick {$confRoom} all",
        ]);
    }

    /**
     * List ConfBridge participants and kick every channel whose name does NOT
     * contain the agent's extension. This hangs up the customer while keeping
     * the agent connected.
     *
     * Tries the given $confRoom first, then falls back to the agent extension
     * as room name (Asterisk often names rooms by the marked user's extension).
     */
    public function confbridgeKickNonAgent(string $confRoom, int $agentExt): void
    {
        $agentStr = (string) $agentExt;

        // Try the given room name first, then fall back to agent extension
        $roomsToTry = [$confRoom];
        if ($confRoom !== $agentStr) {
            $roomsToTry[] = $agentStr;
        }

        foreach ($roomsToTry as $room) {
            $channels = $this->confbridgeListChannels($room);
            error_log("confbridgeKickNonAgent: room={$room} channels=" . json_encode($channels));

            if (empty($channels)) {
                error_log("confbridgeKickNonAgent: room={$room} not found or empty, trying next");
                continue;
            }

            // Kick every channel that does NOT contain the agent extension
            foreach ($channels as $ch) {
                if (!str_contains($ch, $agentStr) && !str_starts_with($ch, 'CBAnn/')) {
                    error_log("confbridgeKickNonAgent: kicking customer channel={$ch} from room={$room}");
                    $this->hangup($ch);
                    return; // success
                }
            }

            error_log("confbridgeKickNonAgent: room={$room} has no non-agent channels to kick");
        }

        error_log("confbridgeKickNonAgent: FAILED — could not find customer channel in any room");
    }

    /**
     * List channel names in a ConfBridge room via AMI Command.
     * Handles both modern (Output: prefix) and legacy (--END COMMAND--) AMI formats.
     *
     * @return string[] Channel names (e.g. ['PJSIP/1001-00000001', 'PJSIP/plivo-00000002'])
     */
    protected function confbridgeListChannels(string $room): array
    {
        $this->sendRaw([
            'Action'  => 'Command',
            'Command' => "confbridge list {$room}",
        ]);

        // Read the AMI response packet — with Events:off this is the only response
        $response = $this->readPacket();
        if (!$response) {
            return [];
        }

        // Check for "not found" error in the response
        $message = $response['Message'] ?? $response['Output'] ?? '';
        if (str_contains($message, 'No conference bridge named') || str_contains($message, 'not found')) {
            return [];
        }

        // Modern Asterisk (16+): output is in multiple Output: headers merged by readPacket.
        // Legacy Asterisk: output is raw lines between Response: Follows and --END COMMAND--.
        // Either way, look for PJSIP/SIP/Local channel patterns.
        $channels = [];
        $raw = implode("\n", array_values($response));
        if (preg_match_all('/(PJSIP\/\S+|SIP\/\S+|Local\/\S+|CBAnn\/\S+)/', $raw, $matches)) {
            $channels = array_unique($matches[1]);
        }

        return array_values($channels);
    }

    /**
     * Read one AMI response packet for a Command action.
     * Handles both modern format (Output: headers) and legacy (--END COMMAND--).
     */
    protected function readCommandOutput(): string
    {
        $response = $this->readPacket();
        if (!$response) {
            return '';
        }
        return implode("\n", array_values($response));
    }

    /**
     * Send a raw AMI action packet.
     * $action is an associative array; duplicate keys are supported by passing
     * an array as value (e.g., 'Variable' => ['K=V', 'K2=V2']).
     */
    public function sendRaw(array $action): void
    {
        if (!$this->socket) {
            throw new \RuntimeException('AsteriskAmiService: not connected');
        }

        $packet = '';
        foreach ($action as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $packet .= "{$key}: {$v}\r\n";
                }
            } else {
                $packet .= "{$key}: {$value}\r\n";
            }
        }
        $packet .= "\r\n";

        $written = fwrite($this->socket, $packet);

        if ($written === false) {
            $this->connected = false;
            throw new \RuntimeException('AsteriskAmiService: write failed — connection lost');
        }
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * Read one AMI packet (terminated by blank line).
     * Returns null on read timeout (non-blocking loop) or connection loss.
     *
     * @return array<string,string>|null
     */
    public function readPacket(): ?array
    {
        if (!$this->socket) {
            return null;
        }

        $fields = [];

        while (true) {
            $line = fgets($this->socket, 4096);

            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out']) {
                    return null; // normal timeout — no packet ready
                }
                $this->connected = false;
                return null; // EOF / connection closed
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                break; // blank line = end of packet
            }

            $colon = strpos($line, ': ');
            if ($colon !== false) {
                $key = substr($line, 0, $colon);
                $val = substr($line, $colon + 2);
                // AMI Command responses have multiple Output: lines — concatenate them
                if (isset($fields[$key])) {
                    $fields[$key] .= "\n" . $val;
                } else {
                    $fields[$key] = $val;
                }
            }
        }

        return empty($fields) ? null : $fields;
    }

    /**
     * Blocking event loop. Calls $handler with each event packet.
     * Loop exits when $handler returns false or connection drops.
     *
     * @param  callable(array): bool  $handler
     */
    public function listen(callable $handler): void
    {
        // Short read timeout so we can react to signals and keep the loop alive
        stream_set_timeout($this->socket, 1);

        while ($this->connected) {
            $packet = $this->readPacket();

            if ($packet === null) {
                // Timeout — continue looping (allows graceful shutdown checks)
                if (!$this->connected) {
                    break;
                }
                continue;
            }

            if (isset($packet['Event'])) {
                if ($handler($packet) === false) {
                    break;
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function resolveServer(int $clientId): ?object
    {
        // Try client-specific mapping first
        $mapping = DB::connection('master')
            ->table('user_asterisk_mapping')
            ->where('parent_id', $clientId)
            ->first();

        if ($mapping && $mapping->asterisk_server_id) {
            $server = DB::connection('master')
                ->table('asterisk_server')
                ->where('id', $mapping->asterisk_server_id)
                ->where('status', 1)
                ->first();

            if ($server) {
                return $server;
            }
        }

        // Fall back to first active server
        return DB::connection('master')
            ->table('asterisk_server')
            ->where('status', 1)
            ->first();
    }

    protected function forceClose(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }
}
