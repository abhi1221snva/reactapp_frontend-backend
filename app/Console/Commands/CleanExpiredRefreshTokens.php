<?php

namespace App\Console\Commands;

use App\Model\Master\RefreshToken;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanExpiredRefreshTokens extends Command
{
    protected $signature = 'auth:clean-refresh-tokens';
    protected $description = 'Delete expired or revoked refresh tokens for housekeeping';

    public function handle()
    {
        $deleted = RefreshToken::where(function ($q) {
            $q->where('expires_at', '<', Carbon::now())
              ->orWhere(function ($q2) {
                  $q2->where('revoked', true)
                      ->where('updated_at', '<', Carbon::now()->subDay());
              });
        })->delete();

        $this->info("Deleted {$deleted} expired/revoked refresh tokens.");

        return 0;
    }
}
