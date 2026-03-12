<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Migrates existing lead data to the new pure-EAV architecture.
 *
 * Source tables  (old):  crm_label, crm_lead_data, crm_lead_field_values
 * Target tables  (new):  crm_labels, crm_leads, crm_lead_values
 *
 * Usage:
 *   php artisan leads:migrate-to-eav              # all clients
 *   php artisan leads:migrate-to-eav --client=42  # single client
 */
class MigrateLeadDataToEav extends Command
{
    protected $signature   = 'leads:migrate-to-eav {--client= : Migrate a single client ID}';
    protected $description = 'Migrate crm_lead_data → crm_leads + crm_lead_values (pure EAV)';

    /** Map old data_type values → new field_type values. */
    private const TYPE_MAP = [
        'select_option'  => 'dropdown',
        'select'         => 'dropdown',
        'text_area'      => 'textarea',
        'phone'          => 'phone_number',
    ];

    /** crm_lead_data columns that map directly to crm_leads system columns. */
    private const SYSTEM_COLS = [
        'lead_status', 'lead_type', 'assigned_to', 'created_by', 'updated_by',
        'lead_source_id', 'lead_parent_id', 'unique_token', 'unique_url', 'score',
        'is_deleted', 'group_id', 'opener_id', 'closer_id', 'is_copied', 'copy_lead_id',
        'created_at', 'updated_at', 'deleted_at',
    ];

    public function handle(): int
    {
        $clientIdFilter = $this->option('client');

        if ($clientIdFilter) {
            $clients = Client::where('id', $clientIdFilter)->get();
            if ($clients->isEmpty()) {
                $this->error("Client {$clientIdFilter} not found.");
                return 1;
            }
        } else {
            $clients = Client::all();
        }

        foreach ($clients as $client) {
            $this->migrateClient((string) $client->id);
        }

        $this->info('Migration complete.');
        return 0;
    }

    private function migrateClient(string $clientId): void
    {
        $conn = "mysql_{$clientId}";
        $this->info("── Client {$clientId} ──────────────────────────────────────");

        // Verify new tables exist
        try {
            DB::connection($conn)->table('crm_labels')->count();
            DB::connection($conn)->table('crm_leads')->count();
            DB::connection($conn)->table('crm_lead_values')->count();
        } catch (\Throwable $e) {
            $this->warn("  Skipping client {$clientId}: new tables not yet migrated ({$e->getMessage()})");
            return;
        }

        try {
            $this->migrateCrmLabels($conn);
            $this->migrateLeads($conn);
            $this->info("  Done.");
        } catch (\Throwable $e) {
            $this->error("  ERROR for client {$clientId}: " . $e->getMessage());
            Log::error("MigrateLeadDataToEav: client {$clientId}", ['error' => $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 1 — Copy field definitions from crm_label → crm_labels
    // ──────────────────────────────────────────────────────────────────────────

    private function migrateCrmLabels(string $conn): void
    {
        $this->info("  [1/2] Migrating field definitions…");

        $oldLabels = DB::connection($conn)->table('crm_label')->get();
        $inserted  = 0;
        $skipped   = 0;

        foreach ($oldLabels as $old) {
            $fieldKey = $old->column_name ?? ('option_' . $old->id);

            // Skip if already exists
            $exists = DB::connection($conn)
                ->table('crm_labels')
                ->where('field_key', $fieldKey)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $dataType  = $old->data_type ?? 'text';
            $fieldType = self::TYPE_MAP[$dataType] ?? $dataType;

            // Parse options / values
            $options = null;
            if (!empty($old->values)) {
                $decoded = json_decode($old->values, true);
                $options = is_array($decoded)
                    ? json_encode($decoded)
                    : json_encode(array_values(array_filter(array_map('trim', explode(',', $old->values)))));
            }

            // Skip internal system keys
            if (in_array($fieldKey, ['unique_url', 'unique_token'])) {
                $skipped++;
                continue;
            }

            // Map heading_type → section
            $sectionMap = [
                'owner'    => 'owner',
                'contact'  => 'contact',
                'business' => 'business',
                'address'  => 'address',
            ];
            $section = $sectionMap[$old->heading_type ?? ''] ?? 'general';

            DB::connection($conn)->table('crm_labels')->insert([
                'label_name'    => $old->title,
                'field_key'     => $fieldKey,
                'field_type'    => $fieldType,
                'section'       => $section,
                'options'       => $options,
                'placeholder'   => $old->placeholder ?? null,
                'conditions'    => $old->conditions   ?? null,
                'required'      => (bool)($old->required ?? false),
                'display_order' => (int)($old->display_order ?? 0),
                'status'        => (bool)($old->status ?? true),
                'created_at'    => $old->created_at ?? Carbon::now(),
                'updated_at'    => $old->updated_at ?? Carbon::now(),
            ]);
            $inserted++;
        }

        $this->line("     Labels — inserted: {$inserted}, skipped: {$skipped}");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 2 — Copy leads from crm_lead_data → crm_leads + crm_lead_values
    // ──────────────────────────────────────────────────────────────────────────

    private function migrateLeads(string $conn): void
    {
        $this->info("  [2/2] Migrating lead records (chunk 500)…");

        // Build field-key map from crm_labels
        $fieldKeys = DB::connection($conn)
            ->table('crm_labels')
            ->pluck('field_key')
            ->toArray();

        $hasOldEav = DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_field_values');

        $total   = DB::connection($conn)->table('crm_lead_data')->count();
        $done    = 0;
        $skipped = 0;

        DB::connection($conn)->table('crm_lead_data')->orderBy('id')->chunk(500, function ($rows) use ($conn, $fieldKeys, $hasOldEav, &$done, &$skipped) {
            foreach ($rows as $row) {
                $rowArr = (array) $row;

                // Skip if already migrated
                if (DB::connection($conn)->table('crm_leads')->where('id', $rowArr['id'])->exists()) {
                    $skipped++;
                    continue;
                }

                // Build system-column insert
                $leadInsert = ['id' => $rowArr['id']];
                foreach (self::SYSTEM_COLS as $col) {
                    $leadInsert[$col] = $rowArr[$col] ?? null;
                }

                // Ensure required defaults
                $leadInsert['lead_status']    = $leadInsert['lead_status']    ?? 'new_lead';
                $leadInsert['lead_parent_id'] = $leadInsert['lead_parent_id'] ?? 0;
                $leadInsert['score']          = $leadInsert['score']          ?? 0;
                $leadInsert['is_deleted']     = $leadInsert['is_deleted']     ?? 0;

                DB::connection($conn)->table('crm_leads')->insert($leadInsert);

                // Build EAV values for all dynamic fields
                $eavRows = [];
                $now     = Carbon::now();

                // From crm_lead_data columns that match a field_key
                foreach ($fieldKeys as $fieldKey) {
                    if (!array_key_exists($fieldKey, $rowArr)) {
                        continue;
                    }
                    $val = $rowArr[$fieldKey];
                    if ($val === null || $val === '') {
                        continue;
                    }
                    $eavRows[] = [
                        'lead_id'     => $rowArr['id'],
                        'field_key'   => $fieldKey,
                        'field_value' => (string) $val,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }

                // From crm_lead_field_values (old EAV table, if it exists)
                if ($hasOldEav) {
                    $oldEavRows = DB::connection($conn)
                        ->table('crm_lead_field_values')
                        ->where('lead_id', $rowArr['id'])
                        ->get(['column_name', 'value_text']);

                    foreach ($oldEavRows as $eav) {
                        if (!in_array($eav->column_name, $fieldKeys)) {
                            continue;
                        }
                        if ($eav->value_text === null || $eav->value_text === '') {
                            continue;
                        }
                        // Avoid duplicate field_key for same lead
                        $alreadyAdded = array_filter($eavRows, fn($r) => $r['field_key'] === $eav->column_name);
                        if (empty($alreadyAdded)) {
                            $eavRows[] = [
                                'lead_id'     => $rowArr['id'],
                                'field_key'   => $eav->column_name,
                                'field_value' => (string) $eav->value_text,
                                'created_at'  => $now,
                                'updated_at'  => $now,
                            ];
                        }
                    }
                }

                if (!empty($eavRows)) {
                    foreach (array_chunk($eavRows, 200) as $chunk) {
                        DB::connection($conn)->table('crm_lead_values')->insert($chunk);
                    }
                }

                $done++;
            }
        });

        $this->line("     Leads — migrated: {$done} / {$total}, skipped: {$skipped}");
    }
}
