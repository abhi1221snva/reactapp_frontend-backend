<?php

namespace App\Console\Commands;

use App\Model\Master\GmailOAuthToken;
use App\Services\GmailNotificationService;
use App\Services\GmailOAuthService;
use App\Services\GmailImapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckGmailEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gmail:check-emails
                            {--user= : Check emails for a specific user ID}
                            {--dry-run : Run without sending notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Gmail for new emails and send team chat notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Gmail email check...');

        $oauthService = new GmailOAuthService();
        $imapService = new GmailImapService();
        $notificationService = new GmailNotificationService($oauthService, $imapService);

        $isDryRun = $this->option('dry-run');
        $specificUserId = $this->option('user');

        // Get all active tokens
        $query = GmailOAuthToken::where('is_active', true);

        if ($specificUserId) {
            $query->where('user_id', $specificUserId);
            $this->info("Checking emails for user ID: {$specificUserId}");
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->info('No active Gmail connections found.');
            return 0;
        }

        $this->info("Found {$tokens->count()} active Gmail connection(s).");

        $totalProcessed = 0;
        $totalErrors = 0;

        foreach ($tokens as $token) {
            try {
                $user = $token->user;

                if (!$user || $user->is_deleted) {
                    $this->warn("User {$token->user_id} not found or deleted. Skipping.");
                    continue;
                }

                $this->line("Processing user: {$user->email} (ID: {$user->id})");

                // Check if token needs refresh
                if ($token->isExpiringSoon()) {
                    $this->line("  Refreshing token...");
                    $token = $oauthService->refreshAccessToken($token);

                    if (!$token) {
                        $this->error("  Failed to refresh token for user {$user->id}");
                        $totalErrors++;
                        continue;
                    }
                }

                if ($isDryRun) {
                    $this->info("  [DRY RUN] Would check emails for {$token->gmail_email}");
                    continue;
                }

                // Process new emails
                $count = $notificationService->processNewEmails($user->id, $user->parent_id);

                if ($count > 0) {
                    $this->info("  Sent {$count} notification(s) for {$token->gmail_email}");
                } else {
                    $this->line("  No new emails to notify for {$token->gmail_email}");
                }

                $totalProcessed += $count;

            } catch (\Throwable $e) {
                $this->error("  Error processing user {$token->user_id}: {$e->getMessage()}");

                Log::error('Gmail check command error', [
                    'user_id' => $token->user_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $totalErrors++;
            }
        }

        $this->newLine();
        $this->info("Gmail email check completed.");
        $this->info("Total notifications sent: {$totalProcessed}");

        if ($totalErrors > 0) {
            $this->warn("Total errors: {$totalErrors}");
        }

        return $totalErrors > 0 ? 1 : 0;
    }
}
