<?php

namespace App\Jobs;

use App\Model\Master\Client;
use App\Model\Master\RegistrationLog;
use App\Model\Master\RegistrationProgress;
use App\Model\User;
use App\Services\ClientService;
use App\Services\ReservedPoolService;
use App\Services\TenantProvisionService;
use App\Services\TrialPackageService;
use App\Services\WelcomeEmailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ProvisionClientJob
 *
 * Slow-path provisioning: creates a brand-new client + user + DB from scratch
 * when no reserved slot is available. Updates RegistrationProgress at each stage
 * so the frontend can show real-time progress.
 *
 * Dispatched on the 'clients' connection (database driver).
 */
class ProvisionClientJob extends Job
{
    public $tries   = 3;
    public $timeout = 300; // 5 minutes max

    private int    $progressId;
    private int    $registrationId;
    private string $name;
    private string $email;
    private string $companyName;
    private string $hashedPassword;
    private string $e164Phone;

    public function __construct(
        int    $progressId,
        int    $registrationId,
        string $name,
        string $email,
        string $companyName,
        string $hashedPassword,
        string $e164Phone
    ) {
        $this->progressId     = $progressId;
        $this->registrationId = $registrationId;
        $this->name           = $name;
        $this->email          = $email;
        $this->companyName    = $companyName;
        $this->hashedPassword = $hashedPassword;
        $this->e164Phone      = $e164Phone;
    }

    public function handle(): void
    {
        $progress = RegistrationProgress::find($this->progressId);
        if (!$progress) {
            Log::error('ProvisionClientJob: progress record not found', ['id' => $this->progressId]);
            return;
        }

        try {
            // ── Stage 1: Create client + user records ────────────────────────
            $progress->advanceTo(RegistrationProgress::STAGE_CREATING_RECORD);

            $now = Carbon::now();
            $nameParts = explode(' ', trim($this->name), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName  = $nameParts[1] ?? '';

            $mobileOnly = ltrim($this->e164Phone, '+');
            if (strlen($mobileOnly) > 10) {
                $mobileOnly = substr($mobileOnly, -10);
            }

            // Create client record
            $clientId = DB::table('clients')->insertGetId([
                'company_name' => $this->companyName,
                'reserved'     => 0,
                'stage'        => Client::RECORD_SAVED,
                'is_deleted'   => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            // Create user record
            $userId = DB::table('users')->insertGetId([
                'parent_id'          => $clientId,
                'base_parent_id'     => $clientId,
                'first_name'         => $firstName,
                'last_name'          => $lastName,
                'email'              => $this->email,
                'mobile'             => $mobileOnly,
                'company_name'       => $this->companyName,
                'password'           => $this->hashedPassword,
                'role'               => 6,
                'user_level'         => 6,
                'reserved'           => 0,
                'is_deleted'         => 0,
                'pusher_uuid'        => (string) Str::uuid(),
                'extension'          => '',
                'asterisk_server_id' => 0,
                'phone_verified_at'  => $now,
                'email_verified_at'  => $now,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            // Create permissions row
            DB::table('permissions')->upsert([
                'user_id'    => $userId,
                'client_id'  => $clientId,
                'role'       => 6,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['user_id', 'client_id'], ['role', 'updated_at']);

            // Create mysql_connection record
            DB::table('mysql_connection')->insert([
                'client_id' => $clientId,
                'db_name'   => 'client_' . $clientId,
                'db_user'   => env('NEW_CLIENT_USERNAME', env('DB_USERNAME', 'root')),
                'password'  => env('NEW_CLIENT_PASSWORD', env('DB_PASSWORD', '')),
                'ip'        => env('NEW_CLIENT_HOST', '127.0.0.1'),
            ]);

            DB::table('clients')->where('id', $clientId)->update([
                'stage' => Client::SAVE_CONNECTION,
            ]);

            RegistrationLog::log(
                RegistrationLog::STEP_USER_CREATED,
                $this->email, $this->e164Phone,
                ['registration_id' => $this->registrationId],
                ['user_id' => $userId, 'client_id' => $clientId],
                RegistrationLog::STATUS_SUCCESS,
                $this->registrationId
            );

            // ── Stage 2: Create database + run migrations ────────────────────
            $progress->advanceTo(RegistrationProgress::STAGE_CREATING_DATABASE);

            $dbName = 'client_' . $clientId;
            $dbUser = env('NEW_CLIENT_USERNAME', env('DB_USERNAME', 'root'));
            $dbHost = env('NEW_CLIENT_HOST', '127.0.0.1');

            DB::connection('master')->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
            DB::connection('master')->statement(
                "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'{$dbHost}'"
            );
            DB::connection('master')->statement("FLUSH PRIVILEGES");

            Artisan::call('make:database:config');
            Artisan::call('migrate', [
                '--database' => "mysql_{$clientId}",
                '--path'     => 'database/migrations/client',
                '--force'    => true,
            ]);

            // Run legacy seeders
            Artisan::call('db:seed', ['--class' => 'NotificationSeeder']);
            Artisan::call('db:seed', ['--class' => 'CrmLabels']);
            Artisan::call('db:seed', ['--class' => 'LabelTableSeeder']);
            Artisan::call('db:seed', ['--class' => 'DispositionTableSeeder']);
            Artisan::call('db:seed', ['--class' => 'DefaultApiTableSeeder']);
            Artisan::call('db:seed', ['--class' => 'CampaignTypesSeeder']);

            DB::table('clients')->where('id', $clientId)->update([
                'stage' => Client::MIGRATE_SEED,
            ]);

            RegistrationLog::log(
                RegistrationLog::STEP_CLIENT_DB_CREATED,
                $this->email, $this->e164Phone,
                ['client_id' => $clientId],
                ['db_name' => $dbName],
                RegistrationLog::STATUS_SUCCESS,
                $this->registrationId
            );

            // ── Stage 3: Seed default data + provision storage ───────────────
            $progress->advanceTo(RegistrationProgress::STAGE_SEEDING_DATA);

            $provisionSvc = new TenantProvisionService();
            $provisionSvc->provisionStorage($clientId);
            $provisionSvc->provisionDefaultSettings($clientId, $this->companyName);
            $provisionSvc->provisionDefaultCrmData($clientId);

            DB::table('clients')->where('id', $clientId)->update([
                'stage' => Client::FULLY_PROVISIONED,
            ]);

            // ── Stage 4: Assign trial package ────────────────────────────────
            $progress->advanceTo(RegistrationProgress::STAGE_ASSIGNING_TRIAL);

            $trialSvc = new TrialPackageService();
            $trialSvc->assignTrial($clientId, $userId);

            // ── Stage 5: Send welcome email ──────────────────────────────────
            $progress->advanceTo(RegistrationProgress::STAGE_SENDING_WELCOME);

            try {
                $welcomeService = new WelcomeEmailService();
                $welcomeService->sendWelcome(
                    email:    $this->email,
                    name:     $this->name,
                    loginUrl: env('PORTAL_NAME', '#'),
                    password: null
                );
            } catch (\Throwable $e) {
                Log::error('ProvisionClientJob: welcome email failed', [
                    'email' => $this->email,
                    'error' => $e->getMessage(),
                ]);
                // Non-fatal — continue
            }

            // ── Stage 6: Grant super-admins permission ───────────────────────
            foreach (User::getAllSuperAdmins() as $adminId) {
                $user = User::find($adminId);
                if (!empty($user) && $user->is_deleted == 0) {
                    $user->addPermission($clientId, 6);
                }
            }

            ClientService::clearCache();

            try {
                Artisan::call('cache:clear');
            } catch (\Throwable $e) {
                // Non-fatal
            }

            // ── Done ─────────────────────────────────────────────────────────
            $progress->markCompleted($clientId, $userId);

            RegistrationLog::log(
                RegistrationLog::STEP_COMPLETED,
                $this->email, $this->e164Phone,
                ['registration_id' => $this->registrationId],
                ['user_id' => $userId, 'client_id' => $clientId, 'path' => 'slow'],
                RegistrationLog::STATUS_SUCCESS,
                $this->registrationId
            );

            Log::info('ProvisionClientJob: completed successfully', [
                'client_id' => $clientId,
                'user_id'   => $userId,
            ]);

        } catch (\Throwable $e) {
            Log::error('ProvisionClientJob: failed', [
                'progress_id' => $this->progressId,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            $progress->retry_count = ($progress->retry_count ?? 0) + 1;
            $progress->markFailed($e->getMessage());

            RegistrationLog::log(
                RegistrationLog::STEP_COMPLETED,
                $this->email, $this->e164Phone,
                ['registration_id' => $this->registrationId],
                ['error' => $e->getMessage(), 'path' => 'slow'],
                RegistrationLog::STATUS_FAILURE,
                $this->registrationId
            );

            // Re-throw so the queue can retry
            throw $e;
        }
    }
}
