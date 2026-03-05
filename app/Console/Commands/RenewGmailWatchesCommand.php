<?php

namespace App\Console\Commands;

use App\Model\Master\GmailOAuthToken;
use App\Services\GmailOAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewGmailWatchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gmail:renew-watches
                            {--user= : Renew watch for a specific user ID}
                            {--hours=24 : Renew watches expiring within this many hours}
                            {--dry-run : Run without actually renewing watches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Renew expiring Gmail push notification watches';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Gmail watch renewal...');

        $oauthService = new GmailOAuthService();

        $isDryRun = $this->option('dry-run');
        $specificUserId = $this->option('user');
        $hoursThreshold = (int) $this->option('hours');

        if ($specificUserId) {
            $userIds = [$specificUserId];
            $this->info("Renewing watch for user ID: {$specificUserId}");
        } else {
            // Get users with expiring watches
            $userIds = $oauthService->getUsersWithExpiringWatches($hoursThreshold);
            $this->info("Found " . count($userIds) . " user(s) with watches expiring within {$hoursThreshold} hours.");
        }

        if (empty($userIds)) {
            $this->info('No watches to renew.');
            return 0;
        }

        $totalRenewed = 0;
        $totalErrors = 0;

        foreach ($userIds as $userId) {
            try {
                $token = GmailOAuthToken::getActiveForUser($userId);

                if (!$token) {
                    $this->warn("No active token for user {$userId}. Skipping.");
                    continue;
                }

                $user = $token->user;

                if (!$user || $user->is_deleted) {
                    $this->warn("User {$userId} not found or deleted. Skipping.");
                    continue;
                }

                $this->line("Processing user: {$user->email} (ID: {$user->id})");

                // Show current watch status
                if ($token->watch_expiration) {
                    $this->line("  Current watch expires: {$token->watch_expiration->toIso8601String()}");
                } else {
                    $this->line("  No active watch found.");
                }

                if ($isDryRun) {
                    $this->info("  [DRY RUN] Would renew watch for {$token->gmail_email}");
                    continue;
                }

                // Renew the watch
                $watchData = $oauthService->renewGmailWatch($userId);

                if ($watchData) {
                    $newExpiration = isset($watchData['expiration'])
                        ? \Illuminate\Support\Carbon::createFromTimestampMs($watchData['expiration'])->toIso8601String()
                        : 'unknown';
                    $this->info("  Watch renewed successfully. New expiration: {$newExpiration}");
                    $totalRenewed++;
                } else {
                    $this->error("  Failed to renew watch for {$token->gmail_email}");
                    $totalErrors++;
                }

            } catch (\Throwable $e) {
                $this->error("  Error processing user {$userId}: {$e->getMessage()}");

                Log::error('Gmail watch renewal error', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $totalErrors++;
            }
        }

        $this->newLine();
        $this->info("Gmail watch renewal completed.");
        $this->info("Total watches renewed: {$totalRenewed}");

        if ($totalErrors > 0) {
            $this->warn("Total errors: {$totalErrors}");
        }

        return $totalErrors > 0 ? 1 : 0;
    }
}
