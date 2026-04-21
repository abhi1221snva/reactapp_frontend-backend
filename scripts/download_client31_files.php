#!/usr/bin/env php
<?php
/**
 * Download client_31 document files from portal.voiptella.com
 *
 * Usage: php scripts/download_client31_files.php [--type=all|documents|signatures] [--batch=1000] [--offset=0] [--dry-run]
 *
 * READ-ONLY on DB. No modifications. Fault-tolerant.
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

// ── Configuration ──────────────────────────────────────────────────────────
$config = [
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'client_31',
    'db_user'     => 'root',
    'db_pass'     => 'HG@v2RM8ERULC',
    'base_url'    => 'https://portal.voiptella.com/uploads/',
    'sig_url'     => 'https://portal.voiptella.com/uploads/signature/',
    'storage_root'=> realpath(__DIR__ . '/../storage/app'),
    'curl_timeout'=> 30,
    'batch_size'  => 1000,
    'max_retries' => 2,
    'concurrency' => 10, // parallel curl handles
];

// ── Parse CLI arguments ────────────────────────────────────────────────────
$opts = getopt('', ['type::', 'batch::', 'offset::', 'dry-run', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php download_client31_files.php [--type=all|documents|signatures] [--batch=1000] [--offset=0] [--dry-run]\n";
    exit(0);
}
$downloadType = $opts['type'] ?? 'all';
$batchSize    = (int)($opts['batch'] ?? $config['batch_size']);
$startOffset  = (int)($opts['offset'] ?? 0);
$dryRun       = isset($opts['dry-run']);

// ── Storage paths ──────────────────────────────────────────────────────────
$docStorageBase = $config['storage_root'] . '/public/crm_documents/client_31';
$sigStorageBase = $config['storage_root'] . '/clients/client_31/leads';

// ── Log file ───────────────────────────────────────────────────────────────
$logDir  = $config['storage_root'] . '/clients/client_31/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/download_' . date('Y-m-d_His') . '.log';
$mappingFile = $logDir . '/file_mapping_' . date('Y-m-d_His') . '.csv';

// ── Stats ──────────────────────────────────────────────────────────────────
$stats = [
    'total_found'     => 0,
    'already_exists'  => 0,
    'downloaded'      => 0,
    'failed'          => 0,
    'skipped_empty'   => 0,
    'skipped_invalid' => 0,
];
$mappings = [];
$failures = [];

// ── Helper functions ───────────────────────────────────────────────────────

function logMsg(string $msg, string $level = 'INFO'): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg;
    echo $line . "\n";
    file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

function ensureDir(string $path): bool {
    if (is_dir($path)) return true;
    return mkdir($path, 0775, true);
}

/**
 * Download files in parallel using curl_multi
 *
 * @param array $jobs Array of ['url' => string, 'dest' => string, 'id' => int, 'file_name' => string]
 * @param int $concurrency Max parallel downloads
 * @param int $timeout Curl timeout per file
 * @param int $maxRetries Max retry attempts
 * @return array ['success' => [...], 'failed' => [...]]
 */
function downloadBatch(array $jobs, int $concurrency, int $timeout, int $maxRetries): array {
    $results = ['success' => [], 'failed' => []];

    if (empty($jobs)) return $results;

    $queue = $jobs;
    $active = [];
    $mh = curl_multi_init();

    // Fill initial batch
    while (count($active) < $concurrency && !empty($queue)) {
        $job = array_shift($queue);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $job['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'RocketDialer-FileSync/1.0',
        ]);
        curl_multi_add_handle($mh, $ch);
        $active[(int)$ch] = ['handle' => $ch, 'job' => $job, 'attempt' => 1];
    }

    // Process
    do {
        $status = curl_multi_exec($mh, $running);

        // Check for completed transfers
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int)$ch;
            $entry = $active[$key];
            $job = $entry['job'];

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($info['result'] === CURLE_OK && $httpCode === 200) {
                $content = curl_multi_getcontent($ch);
                $contentLen = strlen($content);

                if ($contentLen > 0) {
                    $dir = dirname($job['dest']);
                    ensureDir($dir);

                    if (file_put_contents($job['dest'], $content) !== false) {
                        $results['success'][] = $job;
                        logMsg("OK [{$job['id']}] {$job['file_name']} ({$contentLen} bytes) -> {$job['dest']}");
                    } else {
                        $results['failed'][] = array_merge($job, ['reason' => 'Write failed']);
                        logMsg("WRITE FAIL [{$job['id']}] {$job['file_name']} -> {$job['dest']}", 'ERROR');
                    }
                } else {
                    $results['failed'][] = array_merge($job, ['reason' => 'Empty response']);
                    logMsg("EMPTY [{$job['id']}] {$job['file_name']}", 'WARN');
                }
            } else {
                // Retry logic
                if ($entry['attempt'] < $maxRetries) {
                    logMsg("RETRY [{$job['id']}] {$job['file_name']} attempt {$entry['attempt']} - HTTP {$httpCode} {$error}", 'WARN');
                    array_unshift($queue, $job);
                    // Track retry count
                    $queue[0]['_attempt'] = $entry['attempt'] + 1;
                } else {
                    $reason = $httpCode === 404 ? '404 Not Found' : ($error ?: "HTTP {$httpCode}");
                    $results['failed'][] = array_merge($job, ['reason' => $reason]);
                    logMsg("FAIL [{$job['id']}] {$job['file_name']} - {$reason}", 'ERROR');
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[$key]);

            // Add next job from queue
            if (!empty($queue)) {
                $nextJob = array_shift($queue);
                $attempt = $nextJob['_attempt'] ?? 1;
                unset($nextJob['_attempt']);

                $ch2 = curl_init();
                curl_setopt_array($ch2, [
                    CURLOPT_URL            => $nextJob['url'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FAILONERROR    => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT      => 'RocketDialer-FileSync/1.0',
                ]);
                curl_multi_add_handle($mh, $ch2);
                $active[(int)$ch2] = ['handle' => $ch2, 'job' => $nextJob, 'attempt' => $attempt];
            }
        }

        if ($running > 0) {
            curl_multi_select($mh, 1);
        }
    } while ($running > 0 || !empty($active));

    curl_multi_close($mh);
    return $results;
}

// ── Main ───────────────────────────────────────────────────────────────────

logMsg("=== Starting file download for client_31 ===");
logMsg("Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE') . " | Type: {$downloadType} | Batch: {$batchSize} | Offset: {$startOffset}");
logMsg("Doc storage: {$docStorageBase}");
logMsg("Sig storage: {$sigStorageBase}");

// Connect to DB (read-only)
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    logMsg("Database connected: {$config['db_name']}");
} catch (PDOException $e) {
    logMsg("DB connection failed: " . $e->getMessage(), 'FATAL');
    exit(1);
}

// ── Initialize mapping CSV ─────────────────────────────────────────────────
file_put_contents($mappingFile, "db_table,record_id,lead_id,original_file_name,source_url,stored_path,status,reason\n");

// ═══════════════════════════════════════════════════════════════════════════
// PART 1: CRM DOCUMENTS
// ═══════════════════════════════════════════════════════════════════════════
if (in_array($downloadType, ['all', 'documents'])) {
    logMsg("\n--- Processing crm_documents ---");

    // Count total
    $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM crm_documents WHERE file_name IS NOT NULL AND file_name != ''");
    $totalDocs = (int)$countStmt->fetch()['cnt'];
    logMsg("Total document records with file_name: {$totalDocs}");
    $stats['total_found'] += $totalDocs;

    $offset = $startOffset;
    $batchNum = 0;

    while ($offset < $totalDocs) {
        $batchNum++;
        $stmt = $pdo->prepare("
            SELECT id, lead_id, file_name, document_type
            FROM crm_documents
            WHERE file_name IS NOT NULL AND file_name != ''
            ORDER BY id ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if (empty($rows)) break;

        $end = $offset + count($rows);
        logMsg("Batch {$batchNum}: records {$offset}-{$end} of {$totalDocs}");

        $jobs = [];

        foreach ($rows as $row) {
            $fileName = trim($row['file_name']);
            $leadId   = (int)$row['lead_id'];
            $docId    = (int)$row['id'];

            // Validate
            if (empty($fileName)) {
                $stats['skipped_empty']++;
                appendMapping($mappingFile, 'crm_documents', $docId, $leadId, $fileName, '', '', 'SKIPPED', 'Empty filename');
                continue;
            }

            // Sanitize filename (prevent path traversal)
            $safeFileName = basename($fileName);
            if ($safeFileName !== $fileName) {
                logMsg("Sanitized [{$docId}] '{$fileName}' -> '{$safeFileName}'", 'WARN');
            }

            $url  = $config['base_url'] . rawurlencode($safeFileName);
            $dest = $docStorageBase . '/lead_' . $leadId . '/' . $safeFileName;

            // Check if already exists
            if (file_exists($dest) && filesize($dest) > 0) {
                $stats['already_exists']++;
                appendMapping($mappingFile, 'crm_documents', $docId, $leadId, $safeFileName, $url, $dest, 'EXISTS', '');
                continue;
            }

            if ($dryRun) {
                logMsg("DRY [{$docId}] Would download: {$url} -> {$dest}");
                appendMapping($mappingFile, 'crm_documents', $docId, $leadId, $safeFileName, $url, $dest, 'DRY_RUN', '');
                continue;
            }

            $jobs[] = [
                'url'       => $url,
                'dest'      => $dest,
                'id'        => $docId,
                'lead_id'   => $leadId,
                'file_name' => $safeFileName,
                'table'     => 'crm_documents',
            ];
        }

        // Download batch in parallel
        if (!empty($jobs) && !$dryRun) {
            $result = downloadBatch($jobs, $config['concurrency'], $config['curl_timeout'], $config['max_retries']);

            foreach ($result['success'] as $s) {
                $stats['downloaded']++;
                appendMapping($mappingFile, $s['table'], $s['id'], $s['lead_id'], $s['file_name'], $s['url'], $s['dest'], 'OK', '');
            }
            foreach ($result['failed'] as $f) {
                $stats['failed']++;
                $failures[] = $f;
                appendMapping($mappingFile, $f['table'], $f['id'], $f['lead_id'], $f['file_name'], $f['url'], $f['dest'] ?? '', 'FAILED', $f['reason']);
            }
        }

        $offset += $batchSize;

        // Progress
        $pct = min(100, round(($end / $totalDocs) * 100, 1));
        logMsg("Progress: {$pct}% | Downloaded: {$stats['downloaded']} | Exists: {$stats['already_exists']} | Failed: {$stats['failed']}");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// PART 2: SIGNATURE IMAGES
// ═══════════════════════════════════════════════════════════════════════════
if (in_array($downloadType, ['all', 'signatures'])) {
    logMsg("\n--- Processing signature images (crm_lead_data) ---");

    // Signature 1
    $stmt = $pdo->query("
        SELECT id, signature_image as file_name, 'signature' as sig_type
        FROM crm_lead_data
        WHERE signature_image IS NOT NULL AND signature_image != ''
        UNION ALL
        SELECT id, owner_2_signature_image as file_name, 'signature2' as sig_type
        FROM crm_lead_data
        WHERE owner_2_signature_image IS NOT NULL AND owner_2_signature_image != ''
        ORDER BY id ASC
    ");
    $sigRows = $stmt->fetchAll();

    $totalSigs = count($sigRows);
    logMsg("Total signature records: {$totalSigs}");
    $stats['total_found'] += $totalSigs;

    // Process in batches
    $chunks = array_chunk($sigRows, $batchSize);
    $batchNum = 0;

    foreach ($chunks as $chunk) {
        $batchNum++;
        logMsg("Signature batch {$batchNum}/" . count($chunks));

        $jobs = [];

        foreach ($chunk as $row) {
            $fileName = trim($row['file_name']);
            $leadId   = (int)$row['id']; // crm_lead_data.id IS the lead_id
            $sigType  = $row['sig_type'];

            if (empty($fileName)) {
                $stats['skipped_empty']++;
                continue;
            }

            $safeFileName = basename($fileName);
            $url  = $config['sig_url'] . rawurlencode($safeFileName);
            $dest = $sigStorageBase . '/' . $leadId . '/signatures/' . $safeFileName;

            if (file_exists($dest) && filesize($dest) > 0) {
                $stats['already_exists']++;
                appendMapping($mappingFile, 'crm_lead_data', $leadId, $leadId, $safeFileName, $url, $dest, 'EXISTS', $sigType);
                continue;
            }

            if ($dryRun) {
                logMsg("DRY [sig-{$leadId}] Would download: {$url} -> {$dest}");
                appendMapping($mappingFile, 'crm_lead_data', $leadId, $leadId, $safeFileName, $url, $dest, 'DRY_RUN', $sigType);
                continue;
            }

            $jobs[] = [
                'url'       => $url,
                'dest'      => $dest,
                'id'        => $leadId,
                'lead_id'   => $leadId,
                'file_name' => $safeFileName,
                'table'     => 'crm_lead_data',
            ];
        }

        if (!empty($jobs) && !$dryRun) {
            $result = downloadBatch($jobs, $config['concurrency'], $config['curl_timeout'], $config['max_retries']);

            foreach ($result['success'] as $s) {
                $stats['downloaded']++;
                appendMapping($mappingFile, $s['table'], $s['id'], $s['lead_id'], $s['file_name'], $s['url'], $s['dest'], 'OK', '');
            }
            foreach ($result['failed'] as $f) {
                $stats['failed']++;
                $failures[] = $f;
                appendMapping($mappingFile, $f['table'], $f['id'], $f['lead_id'], $f['file_name'], $f['url'], $f['dest'] ?? '', 'FAILED', $f['reason']);
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// FINAL REPORT
// ═══════════════════════════════════════════════════════════════════════════
logMsg("\n" . str_repeat('=', 60));
logMsg("DOWNLOAD COMPLETE — FINAL REPORT");
logMsg(str_repeat('=', 60));
logMsg("Total files found in DB:    {$stats['total_found']}");
logMsg("Already existed (skipped):  {$stats['already_exists']}");
logMsg("Successfully downloaded:    {$stats['downloaded']}");
logMsg("Failed downloads:           {$stats['failed']}");
logMsg("Skipped (empty filename):   {$stats['skipped_empty']}");
logMsg("Skipped (invalid):          {$stats['skipped_invalid']}");
logMsg(str_repeat('-', 60));
logMsg("Doc storage path:  {$docStorageBase}");
logMsg("Sig storage path:  {$sigStorageBase}");
logMsg("Log file:          {$logFile}");
logMsg("Mapping CSV:       {$mappingFile}");

if (!empty($failures)) {
    logMsg("\n--- Failed files (first 20) ---");
    $shown = 0;
    foreach ($failures as $f) {
        if ($shown >= 20) {
            logMsg("... and " . (count($failures) - 20) . " more failures (see mapping CSV)");
            break;
        }
        logMsg("  [{$f['id']}] {$f['file_name']} — {$f['reason']}");
        $shown++;
    }
}

// Verify downloaded files
if ($stats['downloaded'] > 0) {
    logMsg("\n--- Verification: checking file integrity ---");
    $verified = 0;
    $corrupt  = 0;

    // Quick check: scan the doc storage dir for zero-byte files
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($docStorageBase, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            if ($file->getSize() === 0) {
                $corrupt++;
                logMsg("CORRUPT (0 bytes): " . $file->getPathname(), 'WARN');
                // Remove zero-byte files
                unlink($file->getPathname());
            } else {
                $verified++;
            }
        }
    }

    if (is_dir($sigStorageBase)) {
        $sigIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sigStorageBase, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($sigIterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                if ($file->getSize() === 0) {
                    $corrupt++;
                    unlink($file->getPathname());
                } else {
                    $verified++;
                }
            }
        }
    }

    logMsg("Verified OK: {$verified} | Corrupt (removed): {$corrupt}");
}

logMsg("\n=== Done ===");

$pdo = null;
exit($stats['failed'] > 0 ? 1 : 0);

// ── Helpers ────────────────────────────────────────────────────────────────

function appendMapping(string $file, string $table, int $id, int $leadId, string $fileName, string $url, string $path, string $status, string $reason): void {
    $row = implode(',', [
        $table,
        $id,
        $leadId,
        '"' . str_replace('"', '""', $fileName) . '"',
        '"' . $url . '"',
        '"' . $path . '"',
        $status,
        '"' . str_replace('"', '""', $reason) . '"',
    ]);
    file_put_contents($file, $row . "\n", FILE_APPEND | LOCK_EX);
}
