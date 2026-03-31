<?php

namespace App\Services;

use App\Model\Master\AsteriskServer;
use Illuminate\Support\Facades\Log;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;

/**
 * AsteriskAudioSyncService
 *
 * Converts an IVR audio file to Asterisk-compatible WAV (8 kHz, mono, PCM-16)
 * and uploads it to the designated Asterisk server via SFTP.
 *
 * Local output : public/uploads/{ivr_id}.wav
 * Remote path  : /var/spool/asterisk/audio/ivr-recordings/{ivr_id}.wav
 */
class AsteriskAudioSyncService
{
    /** ID of the Asterisk server row to target */
    const ASTERISK_SERVER_ID = 7;

    /** Remote directory on the Asterisk server */
    const REMOTE_DIR = '/var/spool/asterisk/audio/ivr-recordings/';

    /** Absolute path to ffmpeg binary */
    const FFMPEG = '/usr/bin/ffmpeg';

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Convert any audio file to Asterisk-compatible WAV:
     *   - Container : WAV
     *   - Sample rate: 8 000 Hz
     *   - Channels  : 1 (mono)
     *   - Codec     : PCM signed 16-bit little-endian
     *
     * Output is saved to  public/uploads/{ivrId}.wav
     *
     * @param  string $sourcePath  Absolute path to the source audio file
     * @param  string $ivrId       IVR identifier (used as output filename)
     * @return string              Absolute path to the converted WAV file
     * @throws \RuntimeException   If ffmpeg exits non-zero or output file is missing
     */
    public function convertToAsteriskWav(string $sourcePath, string $ivrId): string
    {
        $outputDir = base_path('public/uploads');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $ivrId . '.wav';

        // Remove stale file so ffmpeg never blocks waiting for overwrite confirmation
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        // -y  : overwrite without prompting (belt-and-suspenders)
        // -ar 8000 : resample to 8 kHz
        // -ac 1    : downmix to mono
        // -acodec pcm_s16le : signed 16-bit PCM (standard Asterisk WAV)
        $cmd = sprintf(
            '%s -y -i %s -ar 8000 -ac 1 -acodec pcm_s16le %s 2>&1',
            escapeshellarg(self::FFMPEG),
            escapeshellarg($sourcePath),
            escapeshellarg($outputPath)
        );

        $output     = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            $detail = implode("\n", $output);
            Log::error('[AsteriskAudioSync] ffmpeg conversion failed', [
                'ivr_id'      => $ivrId,
                'source'      => $sourcePath,
                'return_code' => $returnCode,
                'ffmpeg_out'  => $detail,
            ]);
            throw new \RuntimeException(
                "ffmpeg conversion failed (exit {$returnCode}): {$detail}"
            );
        }

        Log::info('[AsteriskAudioSync] Converted to Asterisk WAV', [
            'ivr_id' => $ivrId,
            'source' => $sourcePath,
            'output' => $outputPath,
        ]);

        return $outputPath;
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Upload a local WAV file to the Asterisk server via SFTP.
     *
     * Steps:
     *  1. Load Asterisk server credentials from DB (id = ASTERISK_SERVER_ID)
     *  2. Authenticate with the pre-configured RSA private key
     *  3. Ensure the remote directory exists (mkdir -p equivalent)
     *  4. Put the file; remote filename is {ivrId}.wav
     *
     * @param  string $localWavPath  Absolute path to the local WAV file
     * @param  string $ivrId         IVR identifier (remote filename stem)
     * @throws \RuntimeException     On server-not-found, login failure, or upload error
     */
    public function syncToAsterisk(string $localWavPath, string $ivrId): void
    {
        /** @var AsteriskServer|null $server */
        $server = AsteriskServer::find(self::ASTERISK_SERVER_ID);

        if (!$server) {
            throw new \RuntimeException(
                'Asterisk server ID ' . self::ASTERISK_SERVER_ID . ' not found in asterisk_server table'
            );
        }

        $host       = $server->domain;
        $port       = (int) ($server->ssh_port ?? 22);
        $remotePath = self::REMOTE_DIR . $ivrId . '.wav';

        // Resolve SSH private key — prefer the system key file (confirmed working),
        // fall back to TEL_PRIVATE_KEY env var.
        $keyFile = trim(env('ASTERISK_SSH_KEY_PATH', '/etc/asterisk_sftp_key'));
        $rawKey  = file_exists($keyFile)
            ? file_get_contents($keyFile)
            : env('TEL_PRIVATE_KEY', '');

        if (empty($rawKey)) {
            throw new \RuntimeException('No SSH private key available for Asterisk SFTP');
        }

        $key = new RSA();
        $key->loadKey($rawKey);

        $sftp = new SFTP($host, $port);

        if (!$sftp->login('root', $key)) {
            Log::error('[AsteriskAudioSync] SFTP authentication failed', [
                'ivr_id' => $ivrId,
                'host'   => $host,
                'port'   => $port,
            ]);
            throw new \RuntimeException(
                "SFTP authentication failed for root@{$host}:{$port}"
            );
        }

        // Create remote directory if it does not exist yet
        if (!$sftp->is_dir(self::REMOTE_DIR)) {
            $sftp->mkdir(self::REMOTE_DIR, 0755, true);
            Log::info('[AsteriskAudioSync] Created remote directory', [
                'dir'    => self::REMOTE_DIR,
                'host'   => $host,
            ]);
        }

        $success = $sftp->put($remotePath, $localWavPath, SFTP::SOURCE_LOCAL_FILE);

        if (!$success) {
            Log::error('[AsteriskAudioSync] SFTP upload failed', [
                'ivr_id'      => $ivrId,
                'host'        => $host,
                'remote_path' => $remotePath,
            ]);
            throw new \RuntimeException(
                "SFTP put failed: {$remotePath} on {$host}"
            );
        }

        Log::info('[AsteriskAudioSync] File uploaded successfully', [
            'ivr_id'      => $ivrId,
            'host'        => $host,
            'remote_path' => $remotePath,
            'local_path'  => $localWavPath,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Full pipeline entry-point: resolve → convert → upload.
     *
     * Resolves the absolute source path from the tenant-scoped ann_id,
     * converts to Asterisk WAV, then uploads via SFTP.
     *
     * This method never throws — all errors are logged so that IVR creation
     * is never blocked by a sync failure.
     *
     * @param  string $annId     Relative path stored in ivr.ann_id
     *                           (e.g. "uploads/tts_1711234567_5432.mp3")
     * @param  int    $clientId  Tenant parent_id (resolves storage base path)
     * @param  string $ivrId     IVR identifier (ivr.ivr_id)
     */
    public function run(string $annId, int $clientId, string $ivrId): void
    {
        if (empty($annId)) {
            Log::warning('[AsteriskAudioSync] Skipped — ann_id is empty', [
                'ivr_id' => $ivrId,
            ]);
            return;
        }

        try {
            // Resolve absolute path: storage/app/clients/client_{id}/uploads/file.mp3
            $sourcePath = TenantStorageService::getBasePath($clientId)
                . DIRECTORY_SEPARATOR
                . ltrim($annId, '/\\');

            if (!file_exists($sourcePath)) {
                throw new \RuntimeException(
                    "Source audio file not found at: {$sourcePath}"
                );
            }

            $wavPath = $this->convertToAsteriskWav($sourcePath, $ivrId);
            $this->syncToAsterisk($wavPath, $ivrId);

        } catch (\Throwable $e) {
            // Log but never re-throw — sync failure must not break IVR creation
            Log::error('[AsteriskAudioSync] Sync pipeline failed', [
                'ivr_id'  => $ivrId,
                'ann_id'  => $annId,
                'client'  => $clientId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
