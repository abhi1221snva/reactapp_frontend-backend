<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Handles EAV (Entity-Attribute-Value) dynamic field operations for leads.
 * Uses crm_labels (field definitions) and crm_lead_values (field values).
 */
class LeadEavService
{
    /**
     * Return active crm_labels for the client.
     * Used by LeadController::validateLeadInfo() — returns empty array (no extra validation needed).
     */
    public function getLabels(string $clientId): array
    {
        return []; // Pure EAV — dynamic fields don't require Lumen validation rules
    }

    /**
     * Extract only system-column values that belong in crm_leads.
     * Used by LeadController::formatLeadInfo() for update payload.
     */
    public function formatLeadFields(array $input, string $clientId): array
    {
        $systemCols = [
            'lead_status', 'lead_type', 'assigned_to', 'created_by', 'updated_by',
            'lead_source_id', 'lead_parent_id', 'score', 'is_deleted',
            'group_id', 'opener_id', 'closer_id', 'is_copied', 'copy_lead_id',
        ];

        $out = [];
        foreach ($systemCols as $col) {
            if (array_key_exists($col, $input)) {
                $out[$col] = $input[$col];
            }
        }

        return $out;
    }

    /**
     * Core lead fields that are always saveable regardless of crm_labels config.
     * These represent the fundamental lead attributes every lead form should support.
     */
    private const CORE_FIELDS = [
        'first_name', 'last_name', 'email', 'phone_number',
        'company_name', 'address', 'city', 'state', 'zip',
        'loan_amount', 'temperature',
    ];

    /**
     * Upsert dynamic field values and return detected changes.
     *
     * Loads current EAV values first, diffs against input, performs same
     * upsert/delete logic as save(), then returns only changed fields.
     *
     * @return array  [field_key => ['old' => string|null, 'new' => string|null], ...]
     */
    public function saveWithDiff(string $clientId, int $leadId, array $input): array
    {
        $changes = [];
        try {
            $conn = "mysql_{$clientId}";

            // Load active field keys from crm_labels
            $configuredKeys = DB::connection($conn)
                ->table('crm_labels')
                ->where('status', true)
                ->pluck('field_key')
                ->toArray();

            $fieldKeys = array_unique(array_merge(self::CORE_FIELDS, $configuredKeys));

            // Load current EAV values for this lead
            $currentValues = [];
            $rows = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->pluck('field_value', 'field_key')
                ->toArray();
            $currentValues = $rows;

            $now = Carbon::now();

            foreach ($fieldKeys as $fieldKey) {
                if (!array_key_exists($fieldKey, $input)) {
                    continue;
                }

                $val    = $input[$fieldKey];
                $oldVal = $currentValues[$fieldKey] ?? null;
                $newVal = ($val === null || $val === '') ? null : trim((string) $val);

                // Detect change
                if ((string) ($oldVal ?? '') !== (string) ($newVal ?? '')) {
                    $changes[$fieldKey] = ['old' => $oldVal, 'new' => $newVal];
                }

                // Perform upsert / delete (same logic as save())
                if ($newVal === null) {
                    DB::connection($conn)
                        ->table('crm_lead_values')
                        ->where('lead_id', $leadId)
                        ->where('field_key', $fieldKey)
                        ->delete();
                    continue;
                }

                DB::connection($conn)
                    ->table('crm_lead_values')
                    ->upsert(
                        [
                            'lead_id'     => $leadId,
                            'field_key'   => $fieldKey,
                            'field_value' => $newVal,
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ],
                        ['lead_id', 'field_key'],
                        ['field_value', 'updated_at']
                    );
            }
        } catch (\Throwable $e) {
            // Non-fatal — EAV failures must not break lead saves
        }

        return $changes;
    }

    /**
     * Upsert all dynamic field values for a lead into crm_lead_values.
     * Skips system-column keys. Empty/null values delete the existing row.
     */
    public function save(string $clientId, int $leadId, array $input): void
    {
        try {
            // Load active field keys from crm_labels
            $configuredKeys = DB::connection("mysql_{$clientId}")
                ->table('crm_labels')
                ->where('status', true)
                ->pluck('field_key')
                ->toArray();

            // Always include core fields in addition to configured crm_labels fields
            $fieldKeys = array_unique(array_merge(self::CORE_FIELDS, $configuredKeys));

            $now = Carbon::now();

            foreach ($fieldKeys as $fieldKey) {
                if (!array_key_exists($fieldKey, $input)) {
                    continue;
                }

                $val = $input[$fieldKey];

                if ($val === null || $val === '') {
                    DB::connection("mysql_{$clientId}")
                        ->table('crm_lead_values')
                        ->where('lead_id', $leadId)
                        ->where('field_key', $fieldKey)
                        ->delete();
                    continue;
                }

                DB::connection("mysql_{$clientId}")
                    ->table('crm_lead_values')
                    ->upsert(
                        [
                            'lead_id'     => $leadId,
                            'field_key'   => $fieldKey,
                            'field_value' => trim((string) $val),
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ],
                        ['lead_id', 'field_key'],
                        ['field_value', 'updated_at']
                    );
            }
        } catch (\Throwable $e) {
            // Non-fatal — EAV failures must not break lead saves
        }
    }

    /**
     * Load EAV field values for a set of lead IDs.
     *
     * @param  string $clientId
     * @param  int[]  $leadIds
     * @return array  [leadId => [field_key => field_value]]
     */
    public function load(string $clientId, array $leadIds): array
    {
        if (empty($leadIds)) {
            return [];
        }

        $rows = DB::connection("mysql_{$clientId}")
            ->table('crm_lead_values')
            ->whereIn('lead_id', $leadIds)
            ->get(['lead_id', 'field_key', 'field_value']);

        $map = [];
        foreach ($rows as $row) {
            $map[$row->lead_id][$row->field_key] = $row->field_value;
        }

        return $map;
    }
}
