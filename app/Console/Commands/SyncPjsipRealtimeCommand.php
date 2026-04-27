<?php

namespace App\Console\Commands;

use App\Services\PjsipRealtimeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Back-fill / verify PJSIP realtime tables from user_extensions.
 *
 * Usage:
 *   php artisan pjsip:sync                   # sync all missing
 *   php artisan pjsip:sync --extension=32347  # sync one extension
 *   php artisan pjsip:sync --dry-run          # preview without writing
 */
class SyncPjsipRealtimeCommand extends Command
{
    protected $signature   = 'pjsip:sync
                              {--extension= : Sync a single extension username}
                              {--dry-run : Preview changes without writing}';
    protected $description = 'Back-fill ps_endpoints / ps_auths / ps_aors from user_extensions';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $single    = $this->option('extension');

        if ($dryRun) {
            $this->info('[DRY-RUN] No records will be written.');
        }

        if ($single) {
            return $this->syncSingle($single, $dryRun);
        }

        return $this->syncAll($dryRun);
    }

    private function syncSingle(string $extensionId, bool $dryRun): int
    {
        $ext = DB::connection('master')
            ->table('user_extensions')
            ->where('username', $extensionId)
            ->first();

        if (!$ext) {
            $this->error("Extension '{$extensionId}' not found in user_extensions.");
            return 1;
        }

        // Detect bcrypt
        if ($ext->secret && preg_match('/^\$2[yab]\$/', $ext->secret)) {
            $this->warn("Extension '{$extensionId}' has a bcrypt-hashed secret — PJSIP records will be created but the password will NOT work.");
            $this->warn('Reset the password via the UI after sync to fix authentication.');
        }

        if ($dryRun) {
            $this->info("[DRY-RUN] Would sync extension '{$extensionId}'.");
            return 0;
        }

        PjsipRealtimeService::syncExtension(
            $extensionId,
            $ext->secret ?? '',
            $ext->context ?? 'user-extensions-phones',
            $ext->fullname
        );

        // Verify
        $ok = DB::connection('master')->table('ps_auths')->where('id', $extensionId)->exists();
        if ($ok) {
            $this->info("Extension '{$extensionId}' synced to ps_endpoints / ps_auths / ps_aors.");
        } else {
            $this->error("Sync appeared to fail — ps_auths row not found for '{$extensionId}'.");
            return 1;
        }

        return 0;
    }

    private function syncAll(bool $dryRun): int
    {
        $this->info('Scanning user_extensions …');

        $result = PjsipRealtimeService::syncAllFromUserExtensions($dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Created / would create', $result['created']],
                ['Skipped (bcrypt secret)', $result['skippedBcrypt']],
                ['Already in sync',         $result['alreadyExists']],
            ]
        );

        if ($result['skippedBcrypt'] > 0) {
            $this->warn(
                "⚠  {$result['skippedBcrypt']} extension(s) have bcrypt-hashed secrets. "
                . 'Reset their passwords via the UI so ps_auths gets the plaintext password.'
            );
        }

        $this->info($dryRun ? 'Dry-run complete.' : 'Sync complete.');
        return 0;
    }
}
