<?php
/**
 * Tiny local webhook receiver used by the RVM end-to-end smoke test.
 *
 * Saves the incoming POST body + headers to latest.json alongside this
 * script. Always responds 200 OK. Run via:
 *
 *   php -S 127.0.0.1:9988 -t tests/webhook_receiver
 *
 * NOT a production component — test fixture only.
 */

$body    = file_get_contents('php://input');
$headers = [];

foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $name = str_replace('_', '-', substr($k, 5));
        $headers[$name] = $v;
    }
}

$entry = [
    'received_at' => date('c'),
    'method'      => $_SERVER['REQUEST_METHOD'] ?? '',
    'path'        => $_SERVER['REQUEST_URI']    ?? '',
    'headers'     => $headers,
    'body_raw'    => $body,
    'body_json'   => json_decode($body, true),
];

file_put_contents(__DIR__ . '/latest.json', json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Append to a log for cumulative review
file_put_contents(
    __DIR__ . '/log.ndjson',
    json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND,
);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'received' => strlen($body)]);
