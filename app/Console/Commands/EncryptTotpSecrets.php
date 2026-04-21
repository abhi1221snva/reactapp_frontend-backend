<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptTotpSecrets extends Command
{
    protected $signature = 'encrypt:totp-secrets {--dry-run : Show counts without modifying data}';
    protected $description = 'One-time migration: encrypt existing plaintext google2fa_secret values in users table';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $users = DB::connection('master')
            ->table('users')
            ->whereNotNull('google2fa_secret')
            ->where('google2fa_secret', '!=', '')
            ->get(['id', 'google2fa_secret']);

        $migrated = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($users as $user) {
            // Already-encrypted values start with "eyJ" (base64 JSON envelope)
            if (str_starts_with($user->google2fa_secret, 'eyJ')) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $migrated++;
                continue;
            }

            try {
                DB::connection('master')
                    ->table('users')
                    ->where('id', $user->id)
                    ->update([
                        'google2fa_secret' => Crypt::encryptString($user->google2fa_secret),
                    ]);
                $migrated++;
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Failed to encrypt user #{$user->id}: {$e->getMessage()}");
            }
        }

        $this->info("Encrypted: {$migrated}, Skipped (already encrypted): {$skipped}, Errors: {$errors}");

        if ($dryRun) {
            $this->warn('Dry run — no changes made.');
        }

        return 0;
    }
}
