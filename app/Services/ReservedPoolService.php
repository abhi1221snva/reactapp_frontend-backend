<?php

namespace App\Services;

use App\Model\Master\Client;
use App\Model\Master\RegistrationProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ReservedPoolService
 *
 * Manages the pre-provisioned client pool used for instant (fast-path) registration.
 *
 * - claimSlot()        — Atomically claim a reserved client+user for a new registration
 * - getPoolSize()      — Count available reserved slots
 * - needsReplenish()   — Check if pool is below minimum threshold
 * - createReservedSlot() — Provision a new reserved client+user+DB for the pool
 */
class ReservedPoolService
{
    /** Minimum reserved slots to keep available. */
    const MIN_POOL_SIZE = 3;

    /**
     * Attempt to claim a reserved client slot atomically.
     *
     * Uses SELECT … FOR UPDATE to prevent race conditions when
     * multiple registrations complete simultaneously.
     *
     * @return array{client_id: int, user_id: int}|null  Null if no reserved slot available
     */
    public function claimSlot(object $prospect, string $e164Phone): ?array
    {
        $result = null;

        try {
            DB::transaction(function () use ($prospect, $e164Phone, &$result) {
                // Lock + fetch the first reserved client
                $reservedClient = DB::table('clients')
                    ->where('reserved', 1)
                    ->where('is_deleted', 0)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->first();

                if (!$reservedClient) {
                    return; // No slot available → result stays null
                }

                // Lock + fetch the associated reserved user
                $reservedUser = DB::table('users')
                    ->where('base_parent_id', $reservedClient->id)
                    ->where('reserved', 1)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->first();

                if (!$reservedUser) {
                    Log::error('ReservedPoolService::claimSlot — reserved client has no reserved user', [
                        'client_id' => $reservedClient->id,
                    ]);
                    return;
                }

                $now = Carbon::now();

                // Parse name
                $nameParts = explode(' ', trim($prospect->name ?? ''), 2);
                $firstName = $nameParts[0] ?? '';
                $lastName  = $nameParts[1] ?? '';

                // Strip country code from E.164 for mobile storage
                $mobileOnly = ltrim($e164Phone, '+');
                if (strlen($mobileOnly) > 10) {
                    $mobileOnly = substr($mobileOnly, -10);
                }

                // Update client record — claim it
                DB::table('clients')->where('id', $reservedClient->id)->update([
                    'company_name' => $prospect->company_name,
                    'reserved'     => 0,
                    'updated_at'   => $now,
                ]);

                // Update user record — claim it
                DB::table('users')->where('id', $reservedUser->id)->update([
                    'first_name'        => $firstName,
                    'last_name'         => $lastName,
                    'email'             => $prospect->email,
                    'mobile'            => $mobileOnly,
                    'company_name'      => $prospect->company_name,
                    'password'          => $prospect->password, // already bcrypt-hashed
                    'role'              => 6,  // Owner role
                    'user_level'        => 6,  // Owner level
                    'reserved'          => 0,
                    'phone_verified_at' => $now,
                    'email_verified_at' => $now,
                    'updated_at'        => $now,
                ]);

                // Ensure permissions row exists
                DB::table('permissions')->upsert([
                    'user_id'    => $reservedUser->id,
                    'client_id'  => $reservedClient->id,
                    'role'       => 6,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], ['user_id', 'client_id'], ['role', 'updated_at']);

                $result = [
                    'client_id' => $reservedClient->id,
                    'user_id'   => $reservedUser->id,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('ReservedPoolService::claimSlot transaction failed', [
                'error' => $e->getMessage(),
            ]);
            $result = null;
        }

        return $result;
    }

    /**
     * Get the current number of available reserved slots.
     */
    public function getPoolSize(): int
    {
        return DB::table('clients')
            ->where('reserved', 1)
            ->where('is_deleted', 0)
            ->count();
    }

    /**
     * Check if the pool needs replenishment.
     */
    public function needsReplenish(): bool
    {
        return $this->getPoolSize() < self::MIN_POOL_SIZE;
    }

    /**
     * Create a new reserved client slot (client + user + DB + migrations).
     *
     * This is a heavy operation (~10-30s) and should run in a queued job.
     *
     * @return int  The new client ID
     */
    public function createReservedSlot(): int
    {
        $now = Carbon::now();

        // 1. Create client record
        $clientId = DB::table('clients')->insertGetId([
            'company_name' => 'Reserved Client',
            'reserved'     => 1,
            'stage'        => Client::RECORD_SAVED,
            'is_deleted'   => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        Log::info("ReservedPoolService: created reserved client record", ['client_id' => $clientId]);

        // 2. Create mysql_connection record
        DB::table('mysql_connection')->insert([
            'client_id' => $clientId,
            'db_name'   => 'client_' . $clientId,
            'db_user'   => env('NEW_CLIENT_USERNAME', env('DB_USERNAME', 'root')),
            'password'  => env('NEW_CLIENT_PASSWORD', env('DB_PASSWORD', '')),
            'ip'        => env('NEW_CLIENT_HOST', '127.0.0.1'),
        ]);

        // 3. Create the database
        $dbName = 'client_' . $clientId;
        $dbUser = env('NEW_CLIENT_USERNAME', env('DB_USERNAME', 'root'));
        $dbHost = env('NEW_CLIENT_HOST', '127.0.0.1');

        DB::connection('master')->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");

        // Only GRANT if using a non-root DB user (root already has full access)
        if ($dbUser !== 'root') {
            DB::connection('master')->statement(
                "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'{$dbHost}'"
            );
            DB::connection('master')->statement("FLUSH PRIVILEGES");
        }

        // 4. Refresh DB config and run migrations
        \Illuminate\Support\Facades\Artisan::call('make:database:config');
        \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--database' => "mysql_{$clientId}",
            '--path'     => 'database/migrations/client',
            '--force'    => true,
        ]);

        // 5. Provision storage, settings, CRM data (includes all seeding)
        $provisionSvc = new TenantProvisionService();
        $provisionSvc->provisionStorage($clientId);
        $provisionSvc->provisionDefaultSettings($clientId, 'Reserved Client');
        $provisionSvc->provisionDefaultCrmData($clientId);

        // 7. Create reserved user
        $placeholderEmail = 'reserved_' . $clientId . '_' . Str::random(8) . '@placeholder.local';
        $userId = DB::table('users')->insertGetId([
            'parent_id'          => $clientId,
            'base_parent_id'     => $clientId,
            'first_name'         => null,
            'last_name'          => null,
            'email'              => $placeholderEmail,
            'password'           => Hash::make(Str::random(32)),
            'role'               => 1,
            'user_level'         => 1,
            'reserved'           => 1,
            'is_deleted'         => 0,
            'pusher_uuid'        => (string) Str::uuid(),
            'extension'          => '',
            'asterisk_server_id' => 0,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        // 8. Update client stage
        DB::table('clients')->where('id', $clientId)->update([
            'stage'      => Client::FULLY_PROVISIONED,
            'updated_at' => $now,
        ]);

        Log::info("ReservedPoolService: reserved slot fully provisioned", [
            'client_id' => $clientId,
            'user_id'   => $userId,
        ]);

        return $clientId;
    }
}
