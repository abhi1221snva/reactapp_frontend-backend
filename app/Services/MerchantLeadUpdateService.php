<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Client\CrmLeadLog;
use App\Models\Client\CrmLeadNote;
use App\Models\Client\CrmNotification;
use App\Services\SystemChannelService;
use App\Services\LeadChangeTracker;

/**
 * Handles merchant-initiated lead updates with full audit trail.
 *
 * Responsibilities:
 *  1. Resolve a lead from a token (cross-tenant search)
 *  2. Detect field-level changes
 *  3. Persist updates to crm_lead_values (EAV) and crm_leads system cols
 *  4. Write crm_lead_logs per changed field
 *  5. Write crm_lead_notes per changed field
 *  6. Write a crm_notifications row for admin visibility
 */
class MerchantLeadUpdateService
{
    /**
     * Fields the merchant is allowed to edit.
     * Anything not in this list is silently ignored.
     */
    private const ALLOWED_FIELDS = [
        // Common personal fields stored in EAV
        'first_name', 'last_name', 'email', 'phone', 'mobile',
        'business_name', 'dba', 'business_type', 'industry',
        'address', 'city', 'state', 'zip', 'country',
        'annual_revenue', 'monthly_revenue', 'credit_score',
        'time_in_business', 'num_employees',
        'bank_name', 'routing_number', 'account_number',
        'ssn_last4', 'ein',
        // Generic extras
        'notes', 'comments', 'additional_info',
    ];

    /**
     * System-column fields that live in crm_leads, not crm_lead_values.
     */
    private const SYSTEM_FIELDS = [
        'lead_status', 'lead_type', 'assigned_to',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve a lead record by its merchant token.
     * Searches across all active client databases.
     *
     * @return array{0: object, 1: int}  [$lead (stdClass), $clientId]
     * @throws \RuntimeException  404 if token not found / expired
     */
    public function resolveByToken(string $token): array
    {
        $token = trim($token);

        if (empty($token)) {
            throw new \RuntimeException('Invalid token.', 400);
        }

        $clients = DB::table('clients')
            ->where('is_deleted', 0)
            ->pluck('id');

        foreach ($clients as $clientId) {
            $conn = "mysql_{$clientId}";
            try {
                if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_leads')) {
                    continue;
                }

                $lead = DB::connection($conn)
                    ->table('crm_leads')
                    ->where(function ($q) use ($token) {
                        $q->where('lead_token', $token)
                          ->orWhere('unique_token', $token);
                    })
                    ->whereNull('deleted_at')
                    ->first();

                if ($lead) {
                    return [$lead, (int) $clientId];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        throw new \RuntimeException('Application not found or link has expired.', 404);
    }

    /**
     * Apply a merchant update: persist changes, log, note, notify.
     *
     * @param  object   $lead        Lead stdClass from resolveByToken()
     * @param  int      $clientId    Tenant ID
     * @param  array    $payload     Raw request data (unfiltered)
     * @param  int|null $merchantId  Merchant DB ID for attribution
     * @param  string   $ip          Client IP for the audit log
     * @return array{changed_fields: string[], skipped_fields: string[]}
     */
    public function applyUpdate(
        object $lead,
        int    $clientId,
        array  $payload,
        ?int   $merchantId,
        string $ip = ''
    ): array {
        $conn   = "mysql_{$clientId}";
        $leadId = (int) $lead->id;

        // ── 1. Filter to allowed fields only ──────────────────────────────────
        $allowed = array_merge(self::ALLOWED_FIELDS, self::SYSTEM_FIELDS);
        $updates = array_intersect_key($payload, array_flip($allowed));

        if (empty($updates)) {
            return ['changed_fields' => [], 'skipped_fields' => array_keys($payload)];
        }

        // ── 2. Load current values for diff ───────────────────────────────────
        $currentEav = $this->loadCurrentEavValues($conn, $leadId);

        // ── 3. Detect actual changes ──────────────────────────────────────────
        $changes       = [];
        $skippedFields = [];

        foreach ($updates as $field => $newValue) {
            $newValue = ($newValue === '') ? null : $newValue;
            $oldValue = $currentEav[$field] ?? null;

            if ((string) $oldValue === (string) $newValue) {
                $skippedFields[] = $field; // no change — skip
                continue;
            }

            $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
        }

        if (empty($changes)) {
            return ['changed_fields' => [], 'skipped_fields' => array_keys($updates)];
        }

        // ── 4. Persist changes ────────────────────────────────────────────────
        DB::connection($conn)->transaction(function () use (
            $conn, $leadId, $changes, $merchantId, $ip, $clientId
        ) {
            $now = now();

            // 4a. Update EAV values
            $eavFields    = array_diff_key($changes, array_flip(self::SYSTEM_FIELDS));
            $systemFields = array_intersect_key($changes, array_flip(self::SYSTEM_FIELDS));

            foreach ($eavFields as $field => $diff) {
                DB::connection($conn)
                    ->table('crm_lead_values')
                    ->updateOrInsert(
                        ['lead_id' => $leadId, 'field_key' => $field],
                        ['field_value' => $diff['new'], 'updated_at' => $now, 'created_at' => $now]
                    );
            }

            // 4b. Update system columns on crm_leads
            if (!empty($systemFields)) {
                $sysUpdate = ['updated_at' => $now];
                foreach ($systemFields as $field => $diff) {
                    $sysUpdate[$field] = $diff['new'];
                }
                DB::connection($conn)
                    ->table('crm_leads')
                    ->where('id', $leadId)
                    ->update($sysUpdate);
            }

            // 4c. Also sync to legacy crm_lead_data if table exists
            if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
                $legacyUpdate = ['updated_at' => $now];
                foreach ($changes as $field => $diff) {
                    $legacyUpdate[$field] = $diff['new'];
                }
                try {
                    DB::connection($conn)
                        ->table('crm_lead_data')
                        ->where('id', $leadId)
                        ->update($legacyUpdate);
                } catch (\Throwable) {
                    // Legacy table schema may not have all fields — ignore
                }
            }

            // ── 5. Write audit logs (crm_lead_logs) ──────────────────────────
            $logRows = [];
            foreach ($changes as $field => $diff) {
                $logRows[] = [
                    'lead_id'    => $leadId,
                    'field_name' => $field,
                    'old_value'  => $diff['old'],
                    'new_value'  => $diff['new'],
                    'updated_by' => $merchantId,
                    'user_type'  => 'merchant',
                    'ip_address' => $ip,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_logs')) {
                DB::connection($conn)->table('crm_lead_logs')->insert($logRows);
            }

            // ── 6. Write auto-notes (crm_lead_notes) ─────────────────────────
            $noteRows = [];
            foreach ($changes as $field => $diff) {
                $label    = ucwords(str_replace('_', ' ', $field));
                $oldDisp  = $diff['old'] ?? '(empty)';
                $newDisp  = $diff['new'] ?? '(empty)';
                $noteRows[] = [
                    'lead_id'    => $leadId,
                    'note'       => "Merchant updated {$label} from \"{$oldDisp}\" to \"{$newDisp}\"",
                    'note_type'  => 'merchant_update',
                    'created_by' => $merchantId,
                    'user_type'  => 'merchant',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_notes')) {
                DB::connection($conn)->table('crm_lead_notes')->insert($noteRows);
            }

            // ── 6b. Write per-field entries to crm_lead_activity (Activity tab) ─
            if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_activity')) {
                foreach ($changes as $field => $diff) {
                    $label   = ucwords(str_replace('_', ' ', $field));
                    $oldDisp = $diff['old'] ?? '(empty)';
                    $newDisp = $diff['new'] ?? '(empty)';
                    DB::connection($conn)->table('crm_lead_activity')->insert([
                        'lead_id'       => $leadId,
                        'user_id'       => null,
                        'activity_type' => 'system',
                        'subject'       => "Merchant updated {$label}: \"{$oldDisp}\" → \"{$newDisp}\"",
                        'body'          => null,
                        'meta'          => json_encode([
                            'field'       => $field,
                            'old_value'   => $diff['old'],
                            'new_value'   => $diff['new'],
                            'merchant_id' => $merchantId,
                            'ip'          => $ip,
                            'source'      => 'merchant_portal',
                        ]),
                        'source_type'   => 'api',
                        'is_pinned'     => 0,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                }
            }

            // ── 7. Write notification for admin ───────────────────────────────
            $changedLabels = implode(', ', array_map(
                fn($f) => ucwords(str_replace('_', ' ', $f)),
                array_keys($changes)
            ));

            DB::connection($conn)->table('crm_notifications')->insert([
                'lead_id'           => $leadId,
                'recipient_user_id' => null, // broadcast to all admins
                'type'              => 'merchant_update',
                'title'             => 'Lead updated by merchant',
                'message'           => "Lead #{$leadId} was updated by the merchant. Fields changed: {$changedLabels}.",
                'is_read'           => false,
                'meta'              => json_encode([
                    'merchant_id'   => $merchantId,
                    'changed_count' => count($changes),
                    'fields'        => array_keys($changes),
                ]),
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        });

        // Record in lead_change_logs (skipActivity=true — this service already writes activity)
        if (!empty($changes)) {
            LeadChangeTracker::recordDiff(
                (string)$clientId, (int)$lead->id, $changes,
                'merchant_portal', $merchantId, 'merchant', $ip ?? null, true
            );
        }

        // Broadcast to #Merchant system channel
        if (!empty($changes)) {
            $changedLabels = implode(', ', array_map(
                fn($f) => ucwords(str_replace('_', ' ', $f)),
                array_keys($changes)
            ));
            $leadId = (int) $lead->id;
            SystemChannelService::broadcast(
                $clientId,
                'merchant',
                "🔄 Merchant updated Lead #{$leadId} — Fields: {$changedLabels}",
                ['lead_id' => $leadId, 'merchant_id' => $merchantId, 'fields' => array_keys($changes), 'event' => 'merchant_update']
            );
        }

        return [
            'changed_fields' => array_keys($changes),
            'skipped_fields' => $skippedFields,
        ];
    }

    /**
     * Return the current EAV values for a lead as a flat key→value map.
     */
    public function getLeadData(object $lead, int $clientId): array
    {
        $conn   = "mysql_{$clientId}";
        $leadId = (int) $lead->id;

        $eav = DB::connection($conn)
            ->table('crm_lead_values')
            ->where('lead_id', $leadId)
            ->pluck('field_value', 'field_key')
            ->toArray();

        return array_merge(
            [
                'id'          => $leadId,
                'lead_status' => $lead->lead_status ?? null,
                'lead_type'   => $lead->lead_type ?? null,
            ],
            $eav
        );
    }

    /**
     * Return the allowed editable field list (for frontend display).
     */
    public function getAllowedFields(): array
    {
        return self::ALLOWED_FIELDS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function loadCurrentEavValues(string $conn, int $leadId): array
    {
        $eav = DB::connection($conn)
            ->table('crm_lead_values')
            ->where('lead_id', $leadId)
            ->pluck('field_value', 'field_key')
            ->toArray();

        // Also pull system columns
        $lead = DB::connection($conn)
            ->table('crm_leads')
            ->where('id', $leadId)
            ->first();

        $system = [];
        foreach (self::SYSTEM_FIELDS as $f) {
            $system[$f] = $lead->{$f} ?? null;
        }

        return array_merge($system, $eav);
    }
}
