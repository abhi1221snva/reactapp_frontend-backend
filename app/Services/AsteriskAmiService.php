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
 *   $ami->connectForClient($clientId);
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
     */
    public function connectForClient(int $clientId): bool
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

        return $this->connect();
    }

    /**
     * Connect using current host/username/secret.
     */
    public function connect(): bool
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);

        if (!$this->socket) {
            Log::error("AsteriskAmiService: fsockopen failed — $errstr ($errno)");
            return false;
        }

        stream_set_timeout($this->socket, 5);

        // Consume banner line: "Asterisk Call Manager/x.x"
        fgets($this->socket, 512);

        // Login
        $this->sendRaw([
            'Action'   => 'Login',
            'Username' => $this->username,
            'Secret'   => $this->secret,
            'Events'   => 'on',
        ]);

        $response = $this->readPacket();

        if (($response['Response'] ?? '') !== 'Success') {
            Log::error('AsteriskAmiService: AMI login failed', $response ?? []);
            $this->forceClose();
            return false;
        }

        $this->connected = true;
        Log::info("AsteriskAmiService: connected to {$this->host}");
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
     * Hangup a channel by name.
     */
    public function hangup(string $channel, int $cause = 16): void
    {
        $this->sendRaw([
            'Action'  => 'Hangup',
            'Channel' => $channel,
            'Cause'   => (string) $cause,
        ]);
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
                $fields[substr($line, 0, $colon)] = substr($line, $colon + 2);
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
