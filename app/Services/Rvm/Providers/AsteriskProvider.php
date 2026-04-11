<?php

namespace App\Services\Rvm\Providers;

use App\Model\Master\AsteriskServer;
use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\DTO\CallbackResult;
use App\Services\Rvm\DTO\DeliveryResult;
use App\Services\Rvm\DTO\HealthStatus;
use App\Services\Rvm\Exceptions\ProviderPermanentError;
use App\Services\Rvm\Exceptions\ProviderTransientError;
use RuntimeException;

/**
 * Asterisk driver — clean-room port of the legacy SendRvmJob.
 *
 * All P0 hardening from the April-2026 audit is preserved:
 *   - sanitizeAmiField() strips CRLF and protocol separators
 *   - explicit AMI response read with timeout
 *   - Response: Error → ProviderPermanentError
 *   - socket I/O failure → ProviderTransientError (queue retries)
 *
 * Behavioural differences from the legacy job:
 *   - No more rvm_queue_list dedup — the unique index on
 *     rvm_drops(client_id, idempotency_key) + the row lock in
 *     ProcessRvmDropJob handle that now.
 *   - No timezone checking — RvmComplianceService owns that.
 *   - No DB writes — ProcessRvmDropJob is the single writer.
 *   - Pure function of (Drop) → DeliveryResult.
 */
class AsteriskProvider implements RvmProviderInterface
{
    private const AMI_PORT = 5038;
    private const AMI_SOCKET_TIMEOUT = 5;

    public function name(): string
    {
        return 'asterisk';
    }

    public function supports(Drop $drop): bool
    {
        // Asterisk can handle anything — no geo restriction in our setup.
        return true;
    }

    public function estimateCost(Drop $drop): int
    {
        return (int) config('rvm.default_cost_cents', 2);
    }

    public function deliver(Drop $drop): DeliveryResult
    {
        // Resolve server:
        //   1. metadata.asterisk_server_id — tenants can pin a specific server.
        //   2. Otherwise pick the first rvm_status=1 row by id.
        //
        // Hardcoding id=1 as the fallback is a footgun — production DBs accumulate
        // stale rows and id=1 can be a long-dead host. Always look up by status.
        $pinnedId = isset($drop->metadata['asterisk_server_id'])
            ? (int) $drop->metadata['asterisk_server_id']
            : null;

        $q = AsteriskServer::on('master')->where('rvm_status', '1');
        if ($pinnedId !== null) {
            $q->where('id', $pinnedId);
        } else {
            $q->orderBy('id');
        }
        $server = $q->first();

        if (!$server) {
            $msg = $pinnedId !== null
                ? "AsteriskProvider: server id={$pinnedId} not found or RVM disabled"
                : 'AsteriskProvider: no RVM-enabled Asterisk server configured';
            throw new ProviderPermanentError($msg);
        }

        // Sanitize every field that lands in the channel string.
        $safePhone     = $this->sanitizeAmiField($drop->phone_e164, 'phone');
        $safeCli       = $this->sanitizeAmiField($drop->caller_id, 'caller_id');
        $safeTemplate  = $this->sanitizeAmiField((string) $drop->voice_template_id, 'voice_template_id');
        $safeDropId    = $this->sanitizeAmiField($drop->id, 'drop_id');
        $safeClientId  = $this->sanitizeAmiField((string) $drop->client_id, 'client_id');

        $channel = "Local/"
            . "{$safePhone}-"
            . "{$safeCli}-"
            . "{$safeTemplate}-"
            . "{$safeClientId}-"
            . "{$safeDropId}@rvm87";

        $commands = [
            "Action: Login",
            "Username: {$server->user}",
            "Secret: {$server->secret}",
            "",
            "Action: Originate",
            "Channel: {$channel}",
            "Exten: {$safePhone}",
            "Context: voice-drop-campaign",
            "Priority: 1",
            "Timeout: 10000",
            "ActionID: drop-{$safeDropId}",
            "",
            "Action: Logoff",
            "",
        ];
        $ami = implode("\r\n", $commands);

        $socket = @fsockopen($server->host, self::AMI_PORT, $errno, $errstr, self::AMI_SOCKET_TIMEOUT);
        if (!$socket) {
            throw new ProviderTransientError(
                "AsteriskProvider: cannot reach {$server->host}:" . self::AMI_PORT . " ({$errno}: {$errstr})"
            );
        }

        try {
            stream_set_timeout($socket, self::AMI_SOCKET_TIMEOUT);

            if (fwrite($socket, $ami) === false) {
                throw new ProviderTransientError('AsteriskProvider: AMI write failed');
            }

            $this->readAmiResponse($socket, "drop-{$safeDropId}");
        } catch (ProviderTransientError | ProviderPermanentError $e) {
            @fclose($socket);
            throw $e;
        } catch (RuntimeException $e) {
            @fclose($socket);
            // Unknown runtime issues → treat as transient (queue retry)
            throw new ProviderTransientError('AsteriskProvider: ' . $e->getMessage());
        }

        @fclose($socket);

        // Asterisk originate is fire-and-forget from our perspective.
        // The drop is "dispatching" until a callback (or a cron sweep) marks it delivered.
        return DeliveryResult::dispatching(
            externalId: "drop-{$drop->id}",
            costCents: $this->estimateCost($drop),
            raw: ['server_id' => (int) $server->id, 'host' => $server->host],
        );
    }

    public function handleCallback(array $payload, array $headers): CallbackResult
    {
        // Asterisk callback path: existing /rvm-callback-cdr route posts CDRs
        // tied to the Local channel ID. Phase 1 just ignores — legacy
        // RvmCallbackHmacMiddleware continues to drive the old CDR path.
        // Phase 2 will parse the CDR body and map it back to rvm_drops.
        return CallbackResult::ignored();
    }

    public function healthCheck(): HealthStatus
    {
        try {
            $server = AsteriskServer::on('master')
                ->where('rvm_status', '1')
                ->first();

            if (!$server) {
                return HealthStatus::down('No RVM-enabled Asterisk server configured');
            }

            $start = microtime(true);
            $socket = @fsockopen($server->host, self::AMI_PORT, $errno, $errstr, 2);
            if (!$socket) {
                return HealthStatus::down("AMI unreachable: {$errstr}");
            }
            @fclose($socket);
            return HealthStatus::up((int) ((microtime(true) - $start) * 1000));
        } catch (\Throwable $e) {
            return HealthStatus::down($e->getMessage());
        }
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /**
     * Strip any character that could break out of an AMI header value.
     * A CRLF here is the CRLF-injection exploit from audit finding R7.
     */
    private function sanitizeAmiField(string $value, string $fieldName): string
    {
        $cleaned = preg_replace('/[\x00\r\n:|\s]/', '', $value);
        if ($cleaned === null || $cleaned === '') {
            throw new ProviderPermanentError("AsteriskProvider: empty AMI field '{$fieldName}'");
        }
        return $cleaned;
    }

    /**
     * Read the AMI response up to (and slightly past) the Originate result.
     * Throws on hard errors, returns silently on success.
     */
    private function readAmiResponse($socket, string $expectedActionId): void
    {
        $buffer = '';
        $deadline = microtime(true) + self::AMI_SOCKET_TIMEOUT;

        while (microtime(true) < $deadline) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new ProviderTransientError('AsteriskProvider: AMI read timeout');
                }
                break;
            }

            $buffer .= $line;

            if (preg_match('/Response:\s*Error.*?Message:\s*(.*)/is', $buffer, $m)) {
                throw new ProviderPermanentError('AsteriskProvider: AMI error: ' . trim($m[1]));
            }

            if (preg_match('/Response:\s*Success/i', $buffer) && substr($buffer, -2) === "\n\n") {
                return;
            }
        }

        throw new ProviderTransientError(
            'AsteriskProvider: AMI response not received in ' . self::AMI_SOCKET_TIMEOUT . 's'
        );
    }
}
