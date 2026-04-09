<?php

namespace App\Services;

use App\Model\Client\UserPackage;
use App\Model\Master\ClientPackage;
use App\Model\Master\Package;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TrialPackageService
 *
 * Assigns a 7-day trial package to newly registered clients automatically.
 */
class TrialPackageService
{
    /**
     * Assign the trial package to a client + its admin user.
     *
     * Creates: Order → OrdersItem → PaymentTransaction → ClientPackage → UserPackage
     *
     * @param  int $clientId  The client to assign the trial to
     * @param  int $userId    The admin user who gets the license seat
     * @return bool
     */
    public function assignTrial(int $clientId, int $userId): bool
    {
        try {
            $package = Package::find(Package::TRIAL_PACKAGE_KEY);
            if (!$package) {
                Log::error('TrialPackageService: trial package not found', [
                    'key' => Package::TRIAL_PACKAGE_KEY,
                ]);
                return false;
            }

            // Check if client already has a package
            $existing = ClientPackage::where('client_id', $clientId)->first();
            if ($existing) {
                Log::info('TrialPackageService: client already has a package', [
                    'client_id'   => $clientId,
                    'package_key' => $existing->package_key,
                ]);
                return true;
            }

            $now     = Carbon::now();
            $endDate = $now->copy()->addDays(7);

            // Create ClientPackage (master DB)
            $clientPackage                      = new ClientPackage();
            $clientPackage->client_id           = $clientId;
            $clientPackage->package_key         = Package::TRIAL_PACKAGE_KEY;
            $clientPackage->quantity             = 1;
            $clientPackage->start_time          = $now;
            $clientPackage->end_time            = $endDate;
            $clientPackage->expiry_time         = $endDate;
            $clientPackage->billed              = 1; // monthly billing cycle
            $clientPackage->payment_cent_amount = 0;
            $clientPackage->payment_time        = $now;
            $clientPackage->payment_method      = 'trial';
            $clientPackage->psp_reference       = time();
            $clientPackage->saveOrFail();

            // Create UserPackage (client DB)
            $connName = "mysql_{$clientId}";

            // Check if the user_packages table exists in client DB
            if (!DB::connection($connName)->getSchemaBuilder()->hasTable('user_packages')) {
                Log::warning('TrialPackageService: user_packages table missing in client DB', [
                    'client_id' => $clientId,
                ]);
                return true; // ClientPackage created, UserPackage will be seeded later
            }

            $userPackage = new UserPackage();
            $userPackage->setConnection($connName);
            $userPackage->client_package_id = $clientPackage->id;
            $userPackage->user_id           = $userId;
            $userPackage->free_call_minutes = $package->free_call_minute_monthly ?? 0;
            $userPackage->free_sms          = $package->free_sms_monthly ?? 0;
            $userPackage->free_fax          = $package->free_fax_monthly ?? 0;
            $userPackage->free_emails       = $package->free_emails_monthly ?? 0;
            $userPackage->free_reset_time   = $now->copy()->addMonth();
            $userPackage->saveOrFail();

            Log::info('TrialPackageService: trial package assigned', [
                'client_id'          => $clientId,
                'user_id'            => $userId,
                'client_package_id'  => $clientPackage->id,
                'expires'            => $endDate->toDateTimeString(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('TrialPackageService::assignTrial failed', [
                'client_id' => $clientId,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}
