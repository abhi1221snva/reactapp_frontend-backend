<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Creates visibility tables and backfills crm_lead_assignees from existing data.
 *
 * Usage:
 *   php artisan leads:migrate-visibility              # all clients
 *   php artisan leads:migrate-visibility --client=3   # single client
 */
class MigrateLeadVisibility extends Command
{
    protected $signature   = 'leads:migrate-visibility {--client= : Migrate a single client ID}';
    protected $description = 'Create lead visibility tables and backfill assignees for client DBs';

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
            $this->migrateClient((int) $client->id);
        }

        $this->info('Lead visibility migration complete.');
        return 0;
    }

    private function migrateClient(int $clientId): void
    {
        $conn = "mysql_{$clientId}";

        try {
            DB::connection($conn)->getPdo();
        } catch (\Throwable $e) {
            $this->warn("Client {$clientId}: cannot connect — skipping. ({$e->getMessage()})");
            return;
        }

        $this->info("Client {$clientId}: migrating...");

        // 1. Create crm_lead_assignees
        if (!Schema::connection($conn)->hasTable('crm_lead_assignees')) {
            DB::connection($conn)->statement("
                CREATE TABLE crm_lead_assignees (
                    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    lead_id      BIGINT UNSIGNED NOT NULL,
                    user_id      BIGINT UNSIGNED NOT NULL,
                    role         VARCHAR(30) NOT NULL DEFAULT 'assignee',
                    assigned_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    assigned_by  BIGINT UNSIGNED NULL,
                    is_active    TINYINT(1) NOT NULL DEFAULT 1,
                    UNIQUE KEY uq_lead_user_role (lead_id, user_id, role),
                    INDEX idx_user_active (user_id, is_active),
                    INDEX idx_lead_active (lead_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->line("  -> crm_lead_assignees created");
        }

        // 2. Create crm_visibility_settings
        if (!Schema::connection($conn)->hasTable('crm_visibility_settings')) {
            DB::connection($conn)->statement("
                CREATE TABLE crm_visibility_settings (
                    id                               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    enable_team_visibility           TINYINT(1) NOT NULL DEFAULT 0,
                    enable_hierarchy_visibility      TINYINT(1) NOT NULL DEFAULT 0,
                    enable_creator_visibility        TINYINT(1) NOT NULL DEFAULT 1,
                    enable_multi_assignee_visibility TINYINT(1) NOT NULL DEFAULT 1,
                    non_admin_min_level              TINYINT UNSIGNED NOT NULL DEFAULT 7,
                    updated_at                       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    updated_by                       BIGINT UNSIGNED NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            DB::connection($conn)->table('crm_visibility_settings')->insert([
                'enable_team_visibility'           => 0,
                'enable_hierarchy_visibility'      => 0,
                'enable_creator_visibility'        => 1,
                'enable_multi_assignee_visibility' => 1,
                'non_admin_min_level'              => 7,
            ]);
            $this->line("  -> crm_visibility_settings created and seeded");
        }

        // 3. Add index on created_by
        $this->addIndexIfMissing($conn, 'crm_leads', 'created_by', 'idx_created_by');
        $this->addIndexIfMissing($conn, 'crm_lead_data', 'created_by', 'idx_ld_created_by');

        // 4. Backfill crm_lead_assignees from existing data
        $this->backfillAssignees($conn, $clientId);

        $this->info("Client {$clientId}: done.");
    }

    private function addIndexIfMissing(string $conn, string $table, string $column, string $indexName): void
    {
        if (!Schema::connection($conn)->hasTable($table)) return;
        if (!Schema::connection($conn)->hasColumn($table, $column)) return;

        $existing = DB::connection($conn)->select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );

        if (empty($existing)) {
            DB::connection($conn)->statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`{$column}`)");
            $this->line("  -> {$table}.{$indexName} added");
        }
    }

    private function backfillAssignees(string $conn, int $clientId): void
    {
        // Determine source table
        $table = Schema::connection($conn)->hasTable('crm_leads') ? 'crm_leads' : 'crm_lead_data';

        if (!Schema::connection($conn)->hasTable($table)) {
            $this->warn("  -> no {$table} table — skipping backfill");
            return;
        }

        $count = DB::connection($conn)->table('crm_lead_assignees')->count();
        if ($count > 0) {
            $this->line("  -> crm_lead_assignees already has {$count} rows — skipping backfill");
            return;
        }

        // Backfill assigned_to → role='assignee'
        $inserted = DB::connection($conn)->statement("
            INSERT IGNORE INTO crm_lead_assignees (lead_id, user_id, role, is_active)
            SELECT id, assigned_to, 'assignee', 1
            FROM `{$table}`
            WHERE assigned_to IS NOT NULL AND assigned_to > 0 AND is_deleted = 0
        ");
        $assigneeCount = DB::connection($conn)->table('crm_lead_assignees')->where('role', 'assignee')->count();
        $this->line("  -> backfilled {$assigneeCount} assignee rows from {$table}");

        // Backfill opener_id (JSON or plain int)
        if (Schema::connection($conn)->hasColumn($table, 'opener_id')) {
            $this->backfillJsonColumn($conn, $table, 'opener_id', 'opener');
        }

        // Backfill closer_id (JSON or plain int)
        if (Schema::connection($conn)->hasColumn($table, 'closer_id')) {
            $this->backfillJsonColumn($conn, $table, 'closer_id', 'closer');
        }
    }

    private function backfillJsonColumn(string $conn, string $table, string $column, string $role): void
    {
        $rows = DB::connection($conn)->table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, '!=', 'null')
            ->where('is_deleted', 0)
            ->select(['id', $column])
            ->cursor();

        $batch = [];
        $total = 0;

        foreach ($rows as $row) {
            $raw = $row->{$column};
            $ids = [];

            // Try JSON decode first
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = array_filter(array_map('intval', $decoded), fn($v) => $v > 0);
            } elseif (is_numeric($raw) && (int) $raw > 0) {
                $ids = [(int) $raw];
            }

            foreach ($ids as $userId) {
                $batch[] = [
                    'lead_id'   => $row->id,
                    'user_id'   => $userId,
                    'role'      => $role,
                    'is_active' => 1,
                ];
            }

            if (count($batch) >= 500) {
                $this->insertBatchIgnore($conn, $batch);
                $total += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->insertBatchIgnore($conn, $batch);
            $total += count($batch);
        }

        if ($total > 0) {
            $this->line("  -> backfilled {$total} {$role} rows from {$table}.{$column}");
        }
    }

    private function insertBatchIgnore(string $conn, array $rows): void
    {
        if (empty($rows)) return;

        foreach ($rows as $row) {
            try {
                DB::connection($conn)->table('crm_lead_assignees')->insertOrIgnore($row);
            } catch (\Throwable $e) {
                // Skip duplicates silently
            }
        }
    }
}
