<?php

namespace App\Services;

use App\Models\Client\CrmLeadRecord;
use App\Models\Client\LeadChangeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Centralized lead change tracking service.
 *
 * Records field-level changes to lead_change_logs, writes enriched
 * crm_lead_activity entries, and notifies the assigned user.
 */
class LeadChangeTracker
{
    /** @var array<string, array<string, string>>  clientId => [field_key => label_name] */
    private static array $labelCache = [];

    /**
     * Record a pre-computed diff for a single lead.
     *
     * @param  string      $clientId
     * @param  int         $leadId
     * @param  array       $changes      [field_key => ['old' => ..., 'new' => ...], ...]
     * @param  string      $source       crm_ui|affiliate_form|merchant_portal|api|system|bulk_operation
     * @param  int|null    $userId
     * @param  string      $userType     agent|admin|merchant|system|affiliate
     * @param  string|null $ip
     * @param  bool        $skipActivity When true, skip writing crm_lead_activity (caller already does it)
     * @return string      The batch_id UUID
     */
    public static function recordDiff(
        string  $clientId,
        int     $leadId,
        array   $changes,
        string  $source       = 'crm_ui',
        ?int    $userId       = null,
        string  $userType     = 'agent',
        ?string $ip           = null,
        bool    $skipActivity = false
    ): string {
        $batchId = (string) Str::uuid();

        if (empty($changes)) {
            return $batchId;
        }

        try {
            $conn = "mysql_{$clientId}";

            // Resolve human-readable labels
            $labels       = self::resolveLabels($clientId, array_keys($changes));
            $richChanges  = [];
            foreach ($changes as $key => $diff) {
                $richChanges[$key] = [
                    'old'   => $diff['old'] ?? null,
                    'new'   => $diff['new'] ?? null,
                    'label' => $labels[$key] ?? ucwords(str_replace('_', ' ', $key)),
                ];
            }

            // Resolve user IDs to names for user-reference fields
            $richChanges = self::resolveUserFields($richChanges);

            $summary = self::buildSummary($richChanges, $source);

            // Insert into lead_change_logs
            DB::connection($conn)->table('lead_change_logs')->insert([
                'lead_id'    => $leadId,
                'batch_id'   => $batchId,
                'source'     => $source,
                'user_id'    => $userId,
                'user_type'  => $userType,
                'changes'    => json_encode($richChanges),
                'ip_address' => $ip,
                'summary'    => $summary,
                'created_at' => now(),
            ]);

            // Write crm_lead_activity
            if (!$skipActivity) {
                ActivityService::log(
                    $clientId,
                    $leadId,
                    'field_update',
                    $summary,
                    null,
                    [
                        'batch_id'       => $batchId,
                        'changed_fields' => $richChanges,
                        'source'         => $source,
                    ],
                    $userId ?? 0,
                    'api'
                );
            }

            // Notify assigned user
            self::notifyAssignee($clientId, $leadId, $changes, $source, $userId, $batchId);
        } catch (\Throwable $e) {
            Log::error('LeadChangeTracker::recordDiff failed', [
                'client_id' => $clientId,
                'lead_id'   => $leadId,
                'error'     => $e->getMessage(),
            ]);
        }

        return $batchId;
    }

    /**
     * Record a lead creation event (all fields are new, old = null).
     */
    public static function recordCreation(
        string  $clientId,
        int     $leadId,
        array   $allFields,
        string  $source   = 'crm_ui',
        ?int    $userId   = null,
        string  $userType = 'agent'
    ): string {
        $changes = [];
        foreach ($allFields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $changes[$key] = ['old' => null, 'new' => (string) $value];
        }

        return self::recordDiff($clientId, $leadId, $changes, $source, $userId, $userType, null, true);
    }

    /**
     * Batch record for bulk operations — single bulk INSERT for efficiency.
     *
     * @param  string $clientId
     * @param  array  $leadChanges  [leadId => [field_key => ['old'=>..., 'new'=>...]], ...]
     * @param  string $source
     * @param  int|null $userId
     * @param  string   $userType
     * @return string   Shared batch_id
     */
    public static function recordBulk(
        string  $clientId,
        array   $leadChanges,
        string  $source   = 'bulk_operation',
        ?int    $userId   = null,
        string  $userType = 'agent'
    ): string {
        $batchId = (string) Str::uuid();

        if (empty($leadChanges)) {
            return $batchId;
        }

        try {
            $conn = "mysql_{$clientId}";
            $now  = now();

            // Collect all field keys for label resolution
            $allKeys = [];
            foreach ($leadChanges as $changes) {
                $allKeys = array_merge($allKeys, array_keys($changes));
            }
            $labels = self::resolveLabels($clientId, array_unique($allKeys));

            $logRows      = [];
            $activityRows = [];

            foreach ($leadChanges as $leadId => $changes) {
                if (empty($changes)) {
                    continue;
                }

                $richChanges = [];
                foreach ($changes as $key => $diff) {
                    $richChanges[$key] = [
                        'old'   => $diff['old'] ?? null,
                        'new'   => $diff['new'] ?? null,
                        'label' => $labels[$key] ?? ucwords(str_replace('_', ' ', $key)),
                    ];
                }

                $richChanges = self::resolveUserFields($richChanges);

                $summary = self::buildSummary($richChanges, $source);

                $logRows[] = [
                    'lead_id'    => $leadId,
                    'batch_id'   => $batchId,
                    'source'     => $source,
                    'user_id'    => $userId,
                    'user_type'  => $userType,
                    'changes'    => json_encode($richChanges),
                    'ip_address' => null,
                    'summary'    => $summary,
                    'created_at' => $now,
                ];

                $activityRows[] = [
                    'lead_id'       => $leadId,
                    'user_id'       => $userId,
                    'activity_type' => 'field_update',
                    'subject'       => $summary,
                    'body'          => null,
                    'meta'          => json_encode([
                        'batch_id'       => $batchId,
                        'changed_fields' => $richChanges,
                        'source'         => $source,
                    ]),
                    'source_type'   => 'api',
                    'source_id'     => null,
                    'is_pinned'     => 0,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }

            // Bulk insert in chunks of 100
            foreach (array_chunk($logRows, 100) as $chunk) {
                DB::connection($conn)->table('lead_change_logs')->insert($chunk);
            }
            foreach (array_chunk($activityRows, 100) as $chunk) {
                DB::connection($conn)->table('crm_lead_activity')->insert($chunk);
            }

            // Aggregate notifications per assigned user
            self::notifyBulkAssignees($clientId, $leadChanges, $source, $userId, $batchId);
        } catch (\Throwable $e) {
            Log::error('LeadChangeTracker::recordBulk failed', [
                'client_id' => $clientId,
                'count'     => count($leadChanges),
                'error'     => $e->getMessage(),
            ]);
        }

        return $batchId;
    }

    /**
     * Resolve human-readable labels for field keys from crm_labels.
     */
    private static function resolveLabels(string $clientId, array $fieldKeys): array
    {
        if (!isset(self::$labelCache[$clientId])) {
            try {
                self::$labelCache[$clientId] = DB::connection("mysql_{$clientId}")
                    ->table('crm_labels')
                    ->pluck('label_name', 'field_key')
                    ->toArray();
            } catch (\Throwable $e) {
                self::$labelCache[$clientId] = [];
            }
        }

        $result = [];
        foreach ($fieldKeys as $key) {
            $result[$key] = self::$labelCache[$clientId][$key]
                ?? ucwords(str_replace('_', ' ', $key));
        }

        return $result;
    }

    /** Fields whose values are user IDs that should be displayed as names. */
    private const USER_ID_FIELDS = ['assigned_to', 'created_by', 'updated_by'];

    /**
     * Replace raw user IDs with "FirstName LastName" for user-reference fields.
     */
    private static function resolveUserFields(array $richChanges): array
    {
        // Collect all user IDs that need resolving
        $ids = [];
        foreach (self::USER_ID_FIELDS as $field) {
            if (!isset($richChanges[$field])) continue;
            foreach (['old', 'new'] as $side) {
                $val = $richChanges[$field][$side] ?? null;
                if ($val !== null && $val !== '' && $val !== '0' && is_numeric($val)) {
                    $ids[] = (int) $val;
                }
            }
        }

        if (empty($ids)) {
            return $richChanges;
        }

        try {
            $users = DB::connection('master')->table('users')
                ->whereIn('id', array_unique($ids))
                ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id')
                ->toArray();
        } catch (\Throwable $e) {
            return $richChanges; // non-fatal — keep raw IDs
        }

        foreach (self::USER_ID_FIELDS as $field) {
            if (!isset($richChanges[$field])) continue;
            foreach (['old', 'new'] as $side) {
                $val = $richChanges[$field][$side] ?? null;
                if ($val !== null && $val !== '' && is_numeric($val)) {
                    $intVal = (int) $val;
                    if ($intVal === 0) {
                        $richChanges[$field][$side] = 'Unassigned';
                    } elseif (isset($users[$intVal])) {
                        $richChanges[$field][$side] = trim($users[$intVal]);
                    }
                }
            }
        }

        return $richChanges;
    }

    /**
     * Build a human-readable summary line.
     */
    private static function buildSummary(array $richChanges, string $source): string
    {
        $count  = count($richChanges);
        $labels = array_map(fn($c) => $c['label'] ?? '?', $richChanges);
        $preview = implode(', ', array_slice(array_values($labels), 0, 3));

        if ($count > 3) {
            $preview .= ' +' . ($count - 3) . ' more';
        }

        $sourceLabel = ucwords(str_replace('_', ' ', $source));

        return "{$count} field(s) updated via {$sourceLabel}: {$preview}";
    }

    /**
     * Notify the assigned user when their lead is updated by someone else.
     */
    private static function notifyAssignee(
        string  $clientId,
        int     $leadId,
        array   $changes,
        string  $source,
        ?int    $userId,
        string  $batchId
    ): void {
        try {
            $conn = "mysql_{$clientId}";
            $assignedTo = DB::connection($conn)
                ->table('crm_leads')
                ->where('id', $leadId)
                ->value('assigned_to');

            if (!$assignedTo || $assignedTo == $userId) {
                return;
            }

            $fieldLabels = implode(', ', array_map(
                fn($k) => ucwords(str_replace('_', ' ', $k)),
                array_slice(array_keys($changes), 0, 5)
            ));
            $sourceLabel = ucwords(str_replace('_', ' ', $source));

            DB::connection($conn)->table('crm_notifications')->insert([
                'lead_id'           => $leadId,
                'recipient_user_id' => $assignedTo,
                'type'              => 'lead_update',
                'title'             => "Lead #{$leadId} updated",
                'message'           => "Fields changed via {$sourceLabel}: {$fieldLabels}.",
                'is_read'           => false,
                'meta'              => json_encode([
                    'batch_id' => $batchId,
                    'source'   => $source,
                    'lead_id'  => $leadId,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Notification failures are non-fatal
        }
    }

    /**
     * Aggregate notifications per assigned user for bulk operations.
     */
    private static function notifyBulkAssignees(
        string $clientId,
        array  $leadChanges,
        string $source,
        ?int   $userId,
        string $batchId
    ): void {
        try {
            $conn    = "mysql_{$clientId}";
            $leadIds = array_keys($leadChanges);

            // Get assigned_to for all affected leads in one query
            $assignments = DB::connection($conn)
                ->table('crm_leads')
                ->whereIn('id', $leadIds)
                ->whereNotNull('assigned_to')
                ->pluck('assigned_to', 'id')
                ->toArray();

            // Group leads by assigned user
            $userLeads = [];
            foreach ($assignments as $leadId => $assignedTo) {
                if ($assignedTo == $userId) {
                    continue; // skip self-notification
                }
                $userLeads[$assignedTo][] = $leadId;
            }

            $now         = now();
            $sourceLabel = ucwords(str_replace('_', ' ', $source));
            $rows        = [];

            foreach ($userLeads as $assignedTo => $leads) {
                $count = count($leads);
                $rows[] = [
                    'lead_id'           => $leads[0],
                    'recipient_user_id' => $assignedTo,
                    'type'              => 'lead_update',
                    'title'             => "{$count} lead(s) updated",
                    'message'           => "{$count} of your leads were updated via {$sourceLabel}.",
                    'is_read'           => false,
                    'meta'              => json_encode([
                        'batch_id'  => $batchId,
                        'source'    => $source,
                        'lead_ids'  => $leads,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($rows)) {
                DB::connection($conn)->table('crm_notifications')->insert($rows);
            }
        } catch (\Throwable $e) {
            // Notification failures are non-fatal
        }
    }
}
