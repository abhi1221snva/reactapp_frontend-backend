<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * LeadVisibilityService
 *
 * Centralised lead access control. Replaces the old binary `if ($level <= 1)` pattern
 * with a configurable, multi-dimensional visibility system:
 *
 *   1. assigned_to = me             (always)
 *   2. created_by = me              (if enable_creator_visibility)
 *   3. multi-assignee pivot lookup  (if enable_multi_assignee_visibility)
 *   4. same extension-group peers   (if enable_team_visibility)
 *   5. manager → subordinate chain  (if enable_hierarchy_visibility)
 *
 * Users at or above `non_admin_min_level` bypass all filters (full access).
 *
 * Works with both `crm_leads` and `crm_lead_data` tables (both have id, assigned_to, created_by).
 */
class LeadVisibilityService
{
    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Does the user have unfiltered (admin-level) access to all leads?
     */
    public function hasFullAccess(object $auth, int $clientId): bool
    {
        $settings = $this->getSettings($clientId);
        return (int) $auth->user_level >= $settings['non_admin_min_level'];
    }

    /**
     * Build a raw SQL WHERE fragment for lead list queries.
     *
     * Returns null when the user has full access (caller should skip filtering).
     * Otherwise returns ['condition' => '(...)', 'bindings' => [...]].
     *
     * The returned condition is a parenthesised OR expression suitable for
     * appending as: `AND {$scope['condition']}` to an existing WHERE clause.
     */
    public function buildVisibilityScope(object $auth, int $clientId): ?array
    {
        if ($this->hasFullAccess($auth, $clientId)) {
            return null;
        }

        $settings = $this->getSettings($clientId);
        $userId   = (int) $auth->id;
        $orParts  = [];
        $bindings = [];

        // 1. Always: assigned to me
        $orParts[]  = 'assigned_to = ?';
        $bindings[] = $userId;

        // 2. Creator visibility
        if ($settings['enable_creator_visibility']) {
            $orParts[]  = 'created_by = ?';
            $bindings[] = $userId;
        }

        // 3. Multi-assignee visibility (pivot table lookup)
        if ($settings['enable_multi_assignee_visibility']) {
            $orParts[]  = 'id IN (SELECT lead_id FROM crm_lead_assignees WHERE user_id = ? AND is_active = 1)';
            $bindings[] = $userId;
        }

        // 4. Team visibility (same extension group → peer user IDs)
        if ($settings['enable_team_visibility']) {
            $groupIds = $auth->groups ?? [];
            if (!empty($groupIds)) {
                $teamUserIds = $this->resolveTeamUserIds($clientId, (array) $groupIds);
                if (!empty($teamUserIds)) {
                    $ph = implode(',', array_fill(0, count($teamUserIds), '?'));
                    $orParts[] = "assigned_to IN ({$ph})";
                    array_push($bindings, ...$teamUserIds);
                    $orParts[] = "created_by IN ({$ph})";
                    array_push($bindings, ...$teamUserIds);
                }
            }
        }

        // 5. Hierarchy visibility (manager → subordinate chain)
        if ($settings['enable_hierarchy_visibility']) {
            $subordinateIds = $this->resolveSubordinateIds($clientId, $userId);
            if (!empty($subordinateIds)) {
                $ph = implode(',', array_fill(0, count($subordinateIds), '?'));
                $orParts[] = "assigned_to IN ({$ph})";
                array_push($bindings, ...$subordinateIds);
                $orParts[] = "created_by IN ({$ph})";
                array_push($bindings, ...$subordinateIds);
            }
        }

        return [
            'condition' => '(' . implode(' OR ', $orParts) . ')',
            'bindings'  => $bindings,
        ];
    }

    /**
     * Apply visibility scope to an Eloquent / Query Builder instance.
     *
     * Use for controllers that build queries with the query builder
     * (CrmSearchController, CrmdashboardController, CrmPipelineController).
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyVisibilityScope($query, object $auth, int $clientId, string $tableAlias = ''): void
    {
        $scope = $this->buildVisibilityScope($auth, $clientId);

        if ($scope === null) {
            return; // full access — no filter
        }

        // If a table alias is provided (e.g. 'cl'), prefix column names
        if ($tableAlias) {
            $scope['condition'] = preg_replace(
                '/\b(assigned_to|created_by|id)\b(?!\s*FROM)/',
                "{$tableAlias}.$1",
                $scope['condition']
            );
        }

        $query->whereRaw($scope['condition'], $scope['bindings']);
    }

    /**
     * Point check: can the authenticated user access a specific lead?
     *
     * Used for IDOR protection on show/update/delete and sub-resource endpoints.
     */
    public function canAccessLead(object $auth, int $clientId, object $lead): bool
    {
        if ($this->hasFullAccess($auth, $clientId)) {
            return true;
        }

        $settings = $this->getSettings($clientId);
        $userId   = (int) $auth->id;
        $leadId   = (int) $lead->id;

        // 1. Assigned to me
        if ((int) ($lead->assigned_to ?? 0) === $userId) {
            return true;
        }

        // 2. Created by me
        if ($settings['enable_creator_visibility'] && (int) ($lead->created_by ?? 0) === $userId) {
            return true;
        }

        // 3. Multi-assignee
        if ($settings['enable_multi_assignee_visibility']) {
            try {
                $exists = DB::connection("mysql_{$clientId}")
                    ->table('crm_lead_assignees')
                    ->where('lead_id', $leadId)
                    ->where('user_id', $userId)
                    ->where('is_active', 1)
                    ->exists();
                if ($exists) return true;
            } catch (\Throwable $e) {
                // Table may not exist yet — skip
            }
        }

        // 4. Team visibility
        if ($settings['enable_team_visibility']) {
            $groupIds = $auth->groups ?? [];
            if (!empty($groupIds)) {
                $teamUserIds = $this->resolveTeamUserIds($clientId, (array) $groupIds);
                $assignedTo = (int) ($lead->assigned_to ?? 0);
                $createdBy  = (int) ($lead->created_by ?? 0);
                if (in_array($assignedTo, $teamUserIds) || in_array($createdBy, $teamUserIds)) {
                    return true;
                }
            }
        }

        // 5. Hierarchy visibility
        if ($settings['enable_hierarchy_visibility']) {
            $subordinateIds = $this->resolveSubordinateIds($clientId, $userId);
            $assignedTo = (int) ($lead->assigned_to ?? 0);
            $createdBy  = (int) ($lead->created_by ?? 0);
            if (in_array($assignedTo, $subordinateIds) || in_array($createdBy, $subordinateIds)) {
                return true;
            }
        }

        return false;
    }

    // ── Settings & Resolution ───────────────────────────────────────────────

    /**
     * Load visibility settings for a client (cached 5 min).
     *
     * If the crm_visibility_settings table hasn't been created yet (migration
     * not run), falls back to non_admin_min_level=2 which preserves the old
     * behaviour: level ≤ 1 restricted, level ≥ 2 full access.
     */
    public function getSettings(int $clientId): array
    {
        return CacheService::tenantRemember($clientId, 'visibility_settings', CacheService::TTL_MEDIUM, function () use ($clientId) {
            try {
                $row = DB::connection("mysql_{$clientId}")
                    ->table('crm_visibility_settings')
                    ->first();
            } catch (\Throwable $e) {
                // Table doesn't exist — return backward-compat defaults
                $row = null;
            }

            if (!$row) {
                // Backward compat: level <= 1 restricted, level >= 2 full access
                return [
                    'enable_team_visibility'           => false,
                    'enable_hierarchy_visibility'      => false,
                    'enable_creator_visibility'        => false,
                    'enable_multi_assignee_visibility' => false,
                    'non_admin_min_level'              => 2,
                ];
            }

            return [
                'enable_team_visibility'           => (bool) $row->enable_team_visibility,
                'enable_hierarchy_visibility'      => (bool) $row->enable_hierarchy_visibility,
                'enable_creator_visibility'        => (bool) $row->enable_creator_visibility,
                'enable_multi_assignee_visibility' => (bool) $row->enable_multi_assignee_visibility,
                'non_admin_min_level'              => (int) $row->non_admin_min_level,
            ];
        });
    }

    /**
     * Resolve user IDs that share the same extension group(s) (cached 60s).
     *
     * Flow: group_ids → extension_group_map (extensions) → master.users (user IDs).
     *
     * @return int[]
     */
    public function resolveTeamUserIds(int $clientId, array $groupIds): array
    {
        if (empty($groupIds)) return [];

        $key = 'team_users_' . implode('_', $groupIds);

        return CacheService::tenantRemember($clientId, $key, CacheService::TTL_SHORT, function () use ($clientId, $groupIds) {
            try {
                $extensions = DB::connection("mysql_{$clientId}")
                    ->table('extension_group_map')
                    ->whereIn('group_id', $groupIds)
                    ->where('is_deleted', 0)
                    ->pluck('extension')
                    ->toArray();
            } catch (\Throwable $e) {
                return [];
            }

            if (empty($extensions)) return [];

            return DB::connection('master')
                ->table('users')
                ->where('parent_id', $clientId)
                ->where('is_deleted', 0)
                ->where(function ($q) use ($extensions) {
                    $q->whereIn('extension', $extensions)
                      ->orWhereIn('alt_extension', $extensions);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        });
    }

    /**
     * Resolve all subordinate user IDs via recursive CTE (cached 5 min).
     *
     * Uses MySQL 8 WITH RECURSIVE to walk the user_hierarchy tree downward
     * from the given manager.
     *
     * @return int[]
     */
    public function resolveSubordinateIds(int $clientId, int $managerId): array
    {
        $key = "subordinates_{$managerId}";

        return CacheService::tenantRemember($clientId, $key, CacheService::TTL_MEDIUM, function () use ($clientId, $managerId) {
            try {
                $rows = DB::connection('master')->select("
                    WITH RECURSIVE subordinates AS (
                        SELECT user_id
                        FROM user_hierarchy
                        WHERE manager_id = ? AND client_id = ?
                        UNION
                        SELECT uh.user_id
                        FROM user_hierarchy uh
                        INNER JOIN subordinates s ON uh.manager_id = s.user_id
                        WHERE uh.client_id = ?
                    )
                    SELECT user_id FROM subordinates
                ", [$managerId, $clientId, $clientId]);
            } catch (\Throwable $e) {
                return [];
            }

            return array_map(fn ($r) => (int) $r->user_id, $rows);
        });
    }
}
