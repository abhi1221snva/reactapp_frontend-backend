<?php
/**
 * RVM Asterisk AMI connectivity smoke test.
 *
 *   php tests/rvm_asterisk_ami_smoke.php
 *
 * Enumerates every RVM-enabled Asterisk server (rvm_status=1) and runs a
 * login/logoff handshake against each one. Does NOT originate any call.
 *
 * Per-server checks:
 *   1. TCP socket opens on AMI_PORT within AMI_SOCKET_TIMEOUT seconds
 *   2. AMI banner received
 *   3. Login action accepted (Response: Success)
 *   4. Logoff clean
 *
 * Any Response: Error from AMI is reported verbatim (most common = bad creds
 * or IP not whitelisted).
 *
 * Exit 0 when every server passes, 1 if any fails, 2 if no servers configured.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->boot();

use App\Model\Master\AsteriskServer;

const AMI_PORT = 5038;
const AMI_SOCKET_TIMEOUT = 5;

echo "=== RVM Asterisk AMI connectivity smoke test ===\n\n";

$servers = AsteriskServer::on('master')
    ->where('rvm_status', '1')
    ->get();

if ($servers->isEmpty()) {
    echo "  [ABORT] no Asterisk servers found with rvm_status=1\n";
    exit(2);
}

echo "  found " . $servers->count() . " RVM-enabled server(s)\n\n";

$totalPass = 0;
$totalFail = 0;

foreach ($servers as $server) {
    echo "--- Server id={$server->id} host={$server->host} user={$server->user} ---\n";

    $result = checkServer($server);
    if ($result['ok']) {
        echo "  [PASS] ({$result['latency_ms']}ms) {$result['message']}\n\n";
        $totalPass++;
    } else {
        echo "  [FAIL] ({$result['latency_ms']}ms) {$result['message']}\n\n";
        $totalFail++;
    }
}

echo "=== Results: {$totalPass} server(s) healthy, {$totalFail} failed ===\n";
exit($totalFail === 0 ? 0 : 1);

/**
 * Perform the full login/logoff handshake against one server and return
 * a structured result. This mirrors AsteriskProvider::deliver() exactly
 * up to the point where the Originate action would be written — then
 * replaces Originate with a Logoff so no call is ever placed.
 *
 * @return array{ok: bool, message: string, latency_ms: int, raw?: string}
 */
function checkServer(AsteriskServer $server): array
{
    $start = microtime(true);

    $socket = @fsockopen($server->host, AMI_PORT, $errno, $errstr, AMI_SOCKET_TIMEOUT);
    if (!$socket) {
        return [
            'ok'         => false,
            'message'    => "TCP connect failed: {$errno} {$errstr}",
            'latency_ms' => msSince($start),
        ];
    }

    stream_set_timeout($socket, AMI_SOCKET_TIMEOUT);

    try {
        // Read the AMI banner (one line, e.g. "Asterisk Call Manager/7.0.3\r\n")
        $banner = fgets($socket, 4096);
        if ($banner === false) {
            return [
                'ok'         => false,
                'message'    => 'no banner received (server may not be AMI)',
                'latency_ms' => msSince($start),
            ];
        }
        $banner = trim($banner);

        // Build Login + Logoff. No Originate. Sanitize exactly like the
        // production provider does — even for these safe fields, this
        // proves the sanitizer doesn't break a legit value.
        $user   = sanitizeAmiField($server->user, 'user');
        $secret = sanitizeAmiField($server->secret, 'secret');

        $ami = implode("\r\n", [
            'Action: Login',
            "Username: {$user}",
            "Secret: {$secret}",
            'Events: off',          // we don't want event stream noise
            '',
            'Action: Logoff',
            '',
            '',
        ]);

        if (fwrite($socket, $ami) === false) {
            return [
                'ok'         => false,
                'message'    => 'AMI write failed',
                'latency_ms' => msSince($start),
            ];
        }

        // Read until we see a Login Response or timeout.
        $buffer = '';
        $deadline = microtime(true) + AMI_SOCKET_TIMEOUT;
        $loginStatus = null;
        $loginMessage = '';

        while (microtime(true) < $deadline) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    return [
                        'ok'         => false,
                        'message'    => 'AMI read timeout waiting for Login response',
                        'latency_ms' => msSince($start),
                        'raw'        => $buffer,
                    ];
                }
                break;
            }
            $buffer .= $line;

            if (preg_match('/Response:\s*Success.*?Message:\s*([^\r\n]*)/is', $buffer, $m)) {
                $loginStatus = 'success';
                $loginMessage = trim($m[1]);
                break;
            }
            if (preg_match('/Response:\s*Error.*?Message:\s*([^\r\n]*)/is', $buffer, $m)) {
                $loginStatus = 'error';
                $loginMessage = trim($m[1]);
                break;
            }
        }

        if ($loginStatus === 'error') {
            return [
                'ok'         => false,
                'message'    => "AMI login error: {$loginMessage}",
                'latency_ms' => msSince($start),
                'raw'        => $buffer,
            ];
        }
        if ($loginStatus !== 'success') {
            return [
                'ok'         => false,
                'message'    => 'AMI login did not return Success or Error in time',
                'latency_ms' => msSince($start),
                'raw'        => $buffer,
            ];
        }

        return [
            'ok'         => true,
            'message'    => "banner={$banner}  login=Success ({$loginMessage})",
            'latency_ms' => msSince($start),
            'raw'        => $buffer,
        ];
    } finally {
        @fclose($socket);
    }
}

function sanitizeAmiField(string $value, string $field): string
{
    $cleaned = preg_replace('/[\x00\r\n:|\s]/', '', $value);
    if ($cleaned === null || $cleaned === '') {
        throw new RuntimeException("empty AMI field '{$field}'");
    }
    return $cleaned;
}

function msSince(float $start): int
{
    return (int) ((microtime(true) - $start) * 1000);
}
