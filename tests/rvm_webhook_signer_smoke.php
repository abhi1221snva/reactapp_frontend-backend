<?php
/**
 * Standalone smoke test for App\Services\Rvm\Support\WebhookSigner.
 *
 *   php tests/rvm_webhook_signer_smoke.php
 *
 * No DB, no framework boot — just loads the class and exercises it.
 * Exit code 0 on full pass, non-zero on any failure.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Rvm\Support\WebhookSigner;

$signer = new WebhookSigner();
$fail = 0;
$pass = 0;

function check(string $label, bool $cond, int &$pass, int &$fail): void
{
    if ($cond) {
        echo "  [PASS] {$label}\n";
        $pass++;
    } else {
        echo "  [FAIL] {$label}\n";
        $fail++;
    }
}

echo "=== WebhookSigner smoke test ===\n";

// 1. generateSecret() produces a non-empty string with the whsec_ prefix
$secret = WebhookSigner::generateSecret();
check('generateSecret() returns non-empty', strlen($secret) > 10, $pass, $fail);
check('generateSecret() starts with whsec_', str_starts_with($secret, 'whsec_'), $pass, $fail);

$secret2 = WebhookSigner::generateSecret();
check('generateSecret() is unique across calls', $secret !== $secret2, $pass, $fail);

// 2. sign() produces the expected shape
$body = '{"id":"evt_abc","type":"rvm.drop.delivered","data":{"phone":"+15551234567"}}';
$signed = $signer->sign($body, $secret);

check('sign() returns header key', isset($signed['header']), $pass, $fail);
check('sign() returns timestamp key', isset($signed['timestamp']), $pass, $fail);
check('sign() returns signature key', isset($signed['signature']), $pass, $fail);
check('sign() header format is "t=...,v1=..."',
    (bool) preg_match('/^t=\d+,v1=[a-f0-9]{64}$/', $signed['header']),
    $pass, $fail);

// 3. verify() accepts the signature we just made
check('verify() accepts a fresh signature',
    $signer->verify($body, $secret, $signed['header']),
    $pass, $fail);

// 4. verify() REJECTS a tampered body
$tamperedBody = $body . 'tampered';
check('verify() rejects tampered body',
    !$signer->verify($tamperedBody, $secret, $signed['header']),
    $pass, $fail);

// 5. verify() REJECTS wrong secret
$wrongSecret = WebhookSigner::generateSecret();
check('verify() rejects wrong secret',
    !$signer->verify($body, $wrongSecret, $signed['header']),
    $pass, $fail);

// 6. verify() REJECTS expired timestamp (>5min tolerance)
$oldSigned = $signer->sign($body, $secret, time() - 400);
check('verify() rejects expired signature (400s old)',
    !$signer->verify($body, $secret, $oldSigned['header']),
    $pass, $fail);

// 7. verify() ACCEPTS borderline timestamp (within tolerance)
$freshOld = $signer->sign($body, $secret, time() - 60);
check('verify() accepts 60s-old signature',
    $signer->verify($body, $secret, $freshOld['header']),
    $pass, $fail);

// 8. verify() REJECTS malformed headers
check('verify() rejects empty header', !$signer->verify($body, $secret, ''), $pass, $fail);
check('verify() rejects garbage header', !$signer->verify($body, $secret, 'hello'), $pass, $fail);
check('verify() rejects half-valid header',
    !$signer->verify($body, $secret, 't=123456'),
    $pass, $fail);
check('verify() rejects wrong-length signature',
    !$signer->verify($body, $secret, 't=' . time() . ',v1=abc'),
    $pass, $fail);

// 9. Deterministic — same inputs → same output
$a = $signer->sign($body, $secret, 1712845200)['signature'];
$b = $signer->sign($body, $secret, 1712845200)['signature'];
check('sign() is deterministic for fixed timestamp', $a === $b, $pass, $fail);

// 10. Different timestamps produce different signatures
$c = $signer->sign($body, $secret, 1712845201)['signature'];
check('sign() produces different signature for different timestamp',
    $a !== $c, $pass, $fail);

// 11. HEADER_NAME constant is correct
check('HEADER_NAME is X-Rvm-Signature',
    WebhookSigner::HEADER_NAME === 'X-Rvm-Signature',
    $pass, $fail);

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
