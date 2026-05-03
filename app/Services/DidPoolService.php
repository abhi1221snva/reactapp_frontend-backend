<?php

namespace App\Services;

use App\Model\Master\DidPool;
use App\Model\Master\DidPoolAudit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DID Pool lifecycle management.
 *
 * Handles assignment, release, cooldown, blocking, and admin operations
 * for the central DID inventory. All mutating methods use SELECT...FOR UPDATE
 * locking to guarantee concurrency safety under high-volume signup.
 */
class DidPoolService
{
    const COOLDOWN_HOURS = 24;

    // ═══════════════════════════════════════════════════════════════════════
    //  Auto-assign (signup)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Atomically claim one free DID from the pool for a new client.
     *
     * Uses SELECT ... FOR UPDATE (FIFO by id) so two concurrent signups
     * can never receive the same number.
     *
     * @param  int    $clientId
     * @param  string $assignmentType  'trial' | 'manual'
     * @return string|null  Assigned phone number, or null if pool is empty
     */
    public function assignDidToClient(int $clientId, string $assignmentType = 'trial'): ?string
    {
        $phoneNumber = null;

        try {
            DB::connection('master')->transaction(function () use ($clientId, $assignmentType, &$phoneNumber) {
                $now = Carbon::now();

                // FIFO: pick oldest free DID past any cooldown
                $did = DB::connection('master')->table('did_pool')
                    ->where('status', DidPool::STATUS_FREE)
                    ->where(function ($q) use ($now) {
                        $q->whereNull('cooldown_until')
                          ->orWhere('cooldown_until', '<', $now);
                    })
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->first();

                if (!$did) {
                    return; // Pool empty — caller handles gracefully
                }

                // Mark as assigned in master pool
                DB::connection('master')->table('did_pool')
                    ->where('id', $did->id)
                    ->update([
                        'status'             => DidPool::STATUS_ASSIGNED,
                        'assigned_client_id' => $clientId,
                        'assignment_type'    => $assignmentType,
                        'assigned_at'        => $now,
                        'released_at'        => null,
                        'cooldown_until'     => null,
                        'updated_at'         => $now,
                    ]);

                // Insert into client's did table (throws on failure → rollback)
                $this->insertIntoClientDid($clientId, $did->phone_number, $did->area_code);

                // Audit trail
                DidPoolAudit::record(
                    $did->id, $did->phone_number, 'assigned',
                    $did->status, DidPool::STATUS_ASSIGNED,
                    $clientId, null, 'system',
                    ['assignment_type' => $assignmentType]
                );

                $phoneNumber = $did->phone_number;
            });
        } catch (\Throwable $e) {
            Log::error('DidPoolService::assignDidToClient failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
            $phoneNumber = null;
        }

        if ($phoneNumber) {
            Log::info('DidPoolService: DID assigned', [
                'client_id' => $clientId, 'did' => $phoneNumber, 'type' => $assignmentType,
            ]);
        } else {
            Log::warning('DidPoolService: no DID available', ['client_id' => $clientId]);
        }

        return $phoneNumber;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Release (trial expiry / admin)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Release all pool DIDs assigned to a client. Sets 24h cooldown.
     *
     * @return int  Number of DIDs released
     */
    public function releaseClientDids(int $clientId, string $triggeredBy = 'scheduler', ?int $adminUserId = null): int
    {
        $releasedCount = 0;

        try {
            DB::connection('master')->transaction(function () use ($clientId, $triggeredBy, $adminUserId, &$releasedCount) {
                $now         = Carbon::now();
                $cooldownEnd = $now->copy()->addHours(self::COOLDOWN_HOURS);

                $dids = DB::connection('master')->table('did_pool')
                    ->where('assigned_client_id', $clientId)
                    ->where('status', DidPool::STATUS_ASSIGNED)
                    ->lockForUpdate()
                    ->get();

                foreach ($dids as $did) {
                    DB::connection('master')->table('did_pool')
                        ->where('id', $did->id)
                        ->update([
                            'status'             => DidPool::STATUS_COOLDOWN,
                            'assigned_client_id' => null,
                            'assignment_type'    => null,
                            'released_at'        => $now,
                            'cooldown_until'     => $cooldownEnd,
                            'updated_at'         => $now,
                        ]);

                    DidPoolAudit::record(
                        $did->id, $did->phone_number, 'released',
                        DidPool::STATUS_ASSIGNED, DidPool::STATUS_COOLDOWN,
                        $clientId, $adminUserId, $triggeredBy,
                        ['cooldown_until' => $cooldownEnd->toDateTimeString()]
                    );

                    $releasedCount++;
                }

                // Remove from client did table
                $this->removeFromClientDid($clientId, $dids->pluck('phone_number')->toArray());
            });
        } catch (\Throwable $e) {
            Log::error('DidPoolService::releaseClientDids failed', [
                'client_id' => $clientId, 'error' => $e->getMessage(),
            ]);
        }

        if ($releasedCount > 0) {
            Log::info('DidPoolService: DIDs released', [
                'client_id' => $clientId, 'count' => $releasedCount,
            ]);
        }

        return $releasedCount;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Cooldown clearing (scheduled)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Move DIDs from 'cooldown' to 'free' once cooldown_until has passed.
     */
    public function clearExpiredCooldowns(): int
    {
        $now   = Carbon::now();
        $count = 0;

        $dids = DB::connection('master')->table('did_pool')
            ->where('status', DidPool::STATUS_COOLDOWN)
            ->where('cooldown_until', '<', $now)
            ->get();

        foreach ($dids as $did) {
            DB::connection('master')->table('did_pool')
                ->where('id', $did->id)
                ->where('status', DidPool::STATUS_COOLDOWN)
                ->update([
                    'status'         => DidPool::STATUS_FREE,
                    'cooldown_until' => null,
                    'updated_at'     => $now,
                ]);

            DidPoolAudit::record(
                $did->id, $did->phone_number, 'cooldown_cleared',
                DidPool::STATUS_COOLDOWN, DidPool::STATUS_FREE,
                null, null, 'scheduler'
            );

            $count++;
        }

        return $count;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Admin: Manual assign
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Assign a specific DID to a client. If currently assigned elsewhere,
     * releases from the previous client first — all in one transaction.
     */
    public function adminAssign(int $didPoolId, int $targetClientId, int $adminUserId): bool
    {
        $success = false;

        DB::connection('master')->transaction(function () use ($didPoolId, $targetClientId, $adminUserId, &$success) {
            $now = Carbon::now();

            $did = DB::connection('master')->table('did_pool')
                ->where('id', $didPoolId)
                ->lockForUpdate()
                ->first();

            if (!$did) {
                throw new \RuntimeException('DID not found in pool.');
            }

            if ($did->status === DidPool::STATUS_BLOCKED) {
                throw new \RuntimeException('Cannot assign a blocked DID. Unblock it first.');
            }

            // If assigned to another client, release it first
            if ($did->status === DidPool::STATUS_ASSIGNED && $did->assigned_client_id) {
                $prevClient = $did->assigned_client_id;
                $this->removeFromClientDid($prevClient, [$did->phone_number]);

                DidPoolAudit::record(
                    $did->id, $did->phone_number, 'released',
                    DidPool::STATUS_ASSIGNED, DidPool::STATUS_FREE,
                    $prevClient, $adminUserId, 'admin',
                    ['reason' => 'Reassigned to client ' . $targetClientId]
                );
            }

            // Assign to target
            DB::connection('master')->table('did_pool')
                ->where('id', $did->id)
                ->update([
                    'status'             => DidPool::STATUS_ASSIGNED,
                    'assigned_client_id' => $targetClientId,
                    'assignment_type'    => DidPool::TYPE_MANUAL,
                    'assigned_at'        => $now,
                    'released_at'        => null,
                    'cooldown_until'     => null,
                    'updated_at'         => $now,
                ]);

            $this->insertIntoClientDid($targetClientId, $did->phone_number, $did->area_code);

            DidPoolAudit::record(
                $did->id, $did->phone_number, 'assigned',
                $did->status, DidPool::STATUS_ASSIGNED,
                $targetClientId, $adminUserId, 'admin',
                ['assignment_type' => 'manual']
            );

            $success = true;
        });

        return $success;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Admin: Manual release
    // ═══════════════════════════════════════════════════════════════════════

    public function adminRelease(int $didPoolId, int $adminUserId): bool
    {
        $success = false;

        DB::connection('master')->transaction(function () use ($didPoolId, $adminUserId, &$success) {
            $now         = Carbon::now();
            $cooldownEnd = $now->copy()->addHours(self::COOLDOWN_HOURS);

            $did = DB::connection('master')->table('did_pool')
                ->where('id', $didPoolId)
                ->where('status', DidPool::STATUS_ASSIGNED)
                ->lockForUpdate()
                ->first();

            if (!$did) {
                throw new \RuntimeException('DID is not currently assigned.');
            }

            $clientId = $did->assigned_client_id;

            DB::connection('master')->table('did_pool')
                ->where('id', $did->id)
                ->update([
                    'status'             => DidPool::STATUS_COOLDOWN,
                    'assigned_client_id' => null,
                    'assignment_type'    => null,
                    'released_at'        => $now,
                    'cooldown_until'     => $cooldownEnd,
                    'updated_at'         => $now,
                ]);

            if ($clientId) {
                $this->removeFromClientDid($clientId, [$did->phone_number]);
            }

            DidPoolAudit::record(
                $did->id, $did->phone_number, 'released',
                DidPool::STATUS_ASSIGNED, DidPool::STATUS_COOLDOWN,
                $clientId, $adminUserId, 'admin',
                ['cooldown_until' => $cooldownEnd->toDateTimeString()]
            );

            $success = true;
        });

        return $success;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Admin: Block / Unblock
    // ═══════════════════════════════════════════════════════════════════════

    public function blockDid(int $didPoolId, int $adminUserId, string $reason = ''): bool
    {
        $success = false;

        DB::connection('master')->transaction(function () use ($didPoolId, $adminUserId, $reason, &$success) {
            $did = DB::connection('master')->table('did_pool')
                ->where('id', $didPoolId)
                ->lockForUpdate()
                ->first();

            if (!$did) {
                throw new \RuntimeException('DID not found.');
            }

            if ($did->status === DidPool::STATUS_ASSIGNED) {
                throw new \RuntimeException('Cannot block an assigned DID. Release it first.');
            }

            $fromStatus = $did->status;

            DB::connection('master')->table('did_pool')
                ->where('id', $did->id)
                ->update([
                    'status'         => DidPool::STATUS_BLOCKED,
                    'blocked_reason' => $reason ?: null,
                    'blocked_by'     => $adminUserId,
                    'updated_at'     => now(),
                ]);

            DidPoolAudit::record(
                $did->id, $did->phone_number, 'blocked',
                $fromStatus, DidPool::STATUS_BLOCKED,
                null, $adminUserId, 'admin',
                $reason ? ['reason' => $reason] : null
            );

            $success = true;
        });

        return $success;
    }

    public function unblockDid(int $didPoolId, int $adminUserId): bool
    {
        $success = false;

        DB::connection('master')->transaction(function () use ($didPoolId, $adminUserId, &$success) {
            $did = DB::connection('master')->table('did_pool')
                ->where('id', $didPoolId)
                ->where('status', DidPool::STATUS_BLOCKED)
                ->lockForUpdate()
                ->first();

            if (!$did) {
                throw new \RuntimeException('DID is not blocked.');
            }

            DB::connection('master')->table('did_pool')
                ->where('id', $did->id)
                ->update([
                    'status'         => DidPool::STATUS_FREE,
                    'blocked_reason' => null,
                    'blocked_by'     => null,
                    'updated_at'     => now(),
                ]);

            DidPoolAudit::record(
                $did->id, $did->phone_number, 'unblocked',
                DidPool::STATUS_BLOCKED, DidPool::STATUS_FREE,
                null, $adminUserId, 'admin'
            );

            $success = true;
        });

        return $success;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Admin: Add / Import
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Add a single DID to the pool.
     *
     * @return object  The inserted DID record
     */
    public function addDid(array $data): object
    {
        $phone = $this->normalizePhoneNumber($data['phone_number'] ?? '');
        if (!$phone) {
            throw new \InvalidArgumentException('Invalid phone number format.');
        }

        $now      = Carbon::now();
        $areaCode = $data['area_code'] ?? $this->extractAreaCode($phone);
        $caps     = $data['capabilities'] ?? ['voice' => true, 'sms' => true];

        $id = DB::connection('master')->table('did_pool')->insertGetId([
            'phone_number' => $phone,
            'status'       => DidPool::STATUS_FREE,
            'provider'     => $data['provider'] ?? null,
            'provider_sid' => $data['provider_sid'] ?? null,
            'area_code'    => $areaCode,
            'country_code' => $data['country_code'] ?? 'US',
            'number_type'  => $data['number_type'] ?? 'local',
            'capabilities' => is_string($caps) ? $caps : json_encode($caps),
            'notes'        => $data['notes'] ?? null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        DidPoolAudit::record(
            $id, $phone, 'imported',
            null, DidPool::STATUS_FREE,
            null, $data['admin_user_id'] ?? null, 'admin'
        );

        return DB::connection('master')->table('did_pool')->where('id', $id)->first();
    }

    /**
     * Bulk import. Skips duplicates, returns summary.
     *
     * @return array{imported: int, skipped: int, errors: array}
     */
    public function bulkImport(array $numbers, array $defaults = [], ?int $adminUserId = null): array
    {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($numbers as $raw) {
            $phone = is_array($raw) ? ($raw['phone_number'] ?? '') : $raw;
            $phone = $this->normalizePhoneNumber($phone);

            if (!$phone) {
                $errors[] = ['number' => $raw, 'reason' => 'Invalid phone number format'];
                continue;
            }

            $exists = DB::connection('master')->table('did_pool')
                ->where('phone_number', $phone)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            try {
                $this->addDid(array_merge($defaults, [
                    'phone_number'  => $phone,
                    'admin_user_id' => $adminUserId,
                    'area_code'     => is_array($raw) ? ($raw['area_code'] ?? null) : null,
                    'provider'      => is_array($raw)
                        ? ($raw['provider'] ?? $defaults['provider'] ?? null)
                        : ($defaults['provider'] ?? null),
                ]));
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = ['number' => $phone, 'reason' => $e->getMessage()];
            }
        }

        return compact('imported', 'skipped', 'errors');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Stats / Queries
    // ═══════════════════════════════════════════════════════════════════════

    public function getStats(): array
    {
        $row = DB::connection('master')->table('did_pool')
            ->selectRaw("
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN status = 'free' AND (cooldown_until IS NULL OR cooldown_until < NOW()) THEN 1 ELSE 0 END), 0) as available,
                COALESCE(SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END), 0) as assigned,
                COALESCE(SUM(CASE WHEN status = 'cooldown' THEN 1 ELSE 0 END), 0) as cooldown,
                COALESCE(SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END), 0) as blocked,
                COALESCE(SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END), 0) as reserved
            ")
            ->first();

        return (array) $row;
    }

    public function clientHasPoolDid(int $clientId): bool
    {
        return DB::connection('master')->table('did_pool')
            ->where('assigned_client_id', $clientId)
            ->where('status', DidPool::STATUS_ASSIGNED)
            ->exists();
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Private: Client DID table helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Insert pool DID into the client's `did` table AND the master `did` table.
     *
     * Sets CNAM to the admin user's first name and routes to
     * their extension by default (dest_type=1).
     *
     * Throws on failure so the master transaction can rollback.
     */
    private function insertIntoClientDid(int $clientId, string $phoneNumber, ?string $areaCode = null): void
    {
        $conn = "mysql_{$clientId}";

        try {
            // Client did.cli uses 10-digit format (no +1 prefix)
            $cli = $this->toCliFormat($phoneNumber);

            // Look up the admin user (role=1) for this client to get first_name + extension
            $adminUser = DB::connection('master')->table('users')
                ->where('parent_id', $clientId)
                ->where('role', 1)
                ->where('is_deleted', 0)
                ->orderBy('id', 'asc')
                ->first(['id', 'first_name', 'extension']);

            $cnam      = $adminUser->first_name ?? null;
            $extension = ($adminUser && !empty($adminUser->extension) && $adminUser->extension !== '0')
                ? $adminUser->extension
                : null;

            // dest_type 1 = extension routing
            $destType  = $extension ? '1' : 'none';

            // ── Insert into client did table ────────────────────────────
            $existsClient = DB::connection($conn)->table('did')
                ->where('cli', $cli)
                ->exists();

            if (!$existsClient) {
                DB::connection($conn)->table('did')->insert([
                    'cli'         => $cli,
                    'cnam'        => $cnam,
                    'area_code'   => $areaCode ?? substr($cli, 0, 3),
                    'extension'   => $extension,
                    'dest_type'   => $destType,
                    'default_did' => 'N',
                    'operator'    => 'pool',
                ]);
            }

            // ── Insert into master did table ────────────────────────────
            $existsMaster = DB::connection('master')->table('did')
                ->where('parent_id', $clientId)
                ->where('cli', $phoneNumber)
                ->exists();

            if (!$existsMaster) {
                DB::connection('master')->table('did')->insert([
                    'parent_id'    => $clientId,
                    'cli'          => $phoneNumber,
                    'user_id'      => $adminUser->id ?? null,
                    'area_code'    => $areaCode ?? substr($cli, 0, 3),
                    'country_code' => '+1',
                    'provider'     => '',
                    'voip_provider' => 'pool',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('DidPoolService::insertIntoClientDid failed', [
                'client_id' => $clientId, 'phone' => $phoneNumber, 'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to rollback master transaction
        }
    }

    /**
     * Remove pool DIDs from the client's `did` table AND master `did` table.
     * Non-fatal during release (logs but doesn't re-throw).
     */
    private function removeFromClientDid(int $clientId, array $phoneNumbers): void
    {
        if (empty($phoneNumbers)) return;

        $conn = "mysql_{$clientId}";

        try {
            $clis = array_map([$this, 'toCliFormat'], $phoneNumbers);

            DB::connection($conn)->table('did')
                ->whereIn('cli', $clis)
                ->where('operator', 'pool')
                ->delete();

            // Also remove from master did table
            DB::connection('master')->table('did')
                ->where('parent_id', $clientId)
                ->whereIn('cli', $phoneNumbers)
                ->where('voip_provider', 'pool')
                ->delete();
        } catch (\Throwable $e) {
            Log::error('DidPoolService::removeFromClientDid failed', [
                'client_id' => $clientId, 'error' => $e->getMessage(),
            ]);
            // Non-fatal — client DB may not exist (already deleted)
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Private: Phone number utilities
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Normalize to E.164 format (+12125551234).
     */
    private function normalizePhoneNumber(string $number): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $number);

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }
        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return '+' . $digits;
        }

        return null;
    }

    /**
     * Convert E.164 to 10-digit CLI format for client did table.
     */
    private function toCliFormat(string $phoneNumber): string
    {
        $cli = ltrim($phoneNumber, '+');
        if (strlen($cli) > 10 && str_starts_with($cli, '1')) {
            $cli = substr($cli, 1);
        }
        return $cli;
    }

    private function extractAreaCode(string $phoneNumber): ?string
    {
        $cli = $this->toCliFormat($phoneNumber);
        return strlen($cli) >= 3 ? substr($cli, 0, 3) : null;
    }
}
