<?php

namespace App\Console\Commands;

use App\Model\Master\GmailOAuthToken;
use App\Services\EmailParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EmailParserScanCommand extends Command
{
    protected $signature = 'email-parser:scan
                            {--user= : Scan for a specific user ID}
                            {--dry-run : Run without dispatching jobs}';

    protected $description = 'Scan Gmail inboxes for PDF attachments and queue them for parsing';

    public function handle()
    {
        $this->info('Starting email parser scan...');

        $isDryRun = $this->option('dry-run');
        $specificUserId = $this->option('user');

        $query = GmailOAuthToken::where('is_active', true);

        if ($specificUserId) {
            $query->where('user_id', $specificUserId);
            $this->info("Scanning for user ID: {$specificUserId}");
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->info('No active Gmail connections found.');
            return 0;
        }

        $this->info("Found {$tokens->count()} active Gmail connection(s).");

        $service = new EmailParserService();
        $totalFound = 0;
        $totalErrors = 0;

        foreach ($tokens as $token) {
            try {
                $user = $token->user;

                if (!$user || $user->is_deleted) {
                    $this->warn("User {$token->user_id} not found or deleted. Skipping.");
                    continue;
                }

                $clientId = (int) $user->parent_id;
                $this->line("Processing user: {$user->email} (ID: {$user->id}, Client: {$clientId})");

                if ($isDryRun) {
                    $this->info("  [DRY RUN] Would scan inbox for {$token->gmail_email}");
                    continue;
                }

                $count = $service->scanInbox($user->id, $clientId);

                if ($count > 0) {
                    $this->info("  Found {$count} new PDF attachment(s) for {$token->gmail_email}");
                } else {
                    $this->line("  No new PDFs for {$token->gmail_email}");
                }

                $totalFound += $count;

            } catch (\Throwable $e) {
                $this->error("  Error processing user {$token->user_id}: {$e->getMessage()}");

                Log::error('[EmailParserScan] Command error', [
                    'user_id' => $token->user_id,
                    'error'   => $e->getMessage(),
                ]);

                $totalErrors++;
            }
        }

        $this->newLine();
        $this->info("Email parser scan completed.");
        $this->info("Total new attachments queued: {$totalFound}");

        if ($totalErrors > 0) {
            $this->warn("Total errors: {$totalErrors}");
        }

        return $totalErrors > 0 ? 1 : 0;
    }
}
