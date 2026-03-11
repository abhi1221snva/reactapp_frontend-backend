<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builds and executes lead list queries against the new crm_leads + crm_lead_values tables.
 * System-column filters (lead_status, lead_type, etc.) apply directly to crm_leads.
 * Dynamic field filters (first_name, email, etc.) resolve via crm_lead_values.
 */
class LeadQueryService
{
    /**
     * Build positional (?) filter conditions for crm_leads system columns.
     *
     * @return array{conditions: string[], bindings: array}
     */
    public function buildFilters(Request $request, string $clientId): array
    {
        $conditions = [];
        $bindings   = [];

        foreach (['lead_status', 'lead_type', 'assigned_to'] as $col) {
            if ($request->filled($col)) {
                $val = $request->input($col);
                if (is_array($val)) {
                    $ph = implode(',', array_fill(0, count($val), '?'));
                    $conditions[] = "{$col} IN ({$ph})";
                    array_push($bindings, ...$val);
                } else {
                    $conditions[] = "{$col} = ?";
                    $bindings[]   = $val;
                }
            }
        }

        if ($request->filled('crm_id')) {
            $conditions[] = 'id = ?';
            $bindings[]   = $request->input('crm_id');
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $conditions[] = 'updated_at BETWEEN ? AND ?';
            $bindings[]   = date('Y-m-d 00:00:00', strtotime($request->input('start_date')));
            $bindings[]   = date('Y-m-d 23:59:59', strtotime($request->input('end_date')));
        }

        return ['conditions' => $conditions, 'bindings' => $bindings];
    }

    /**
     * Resolve dynamic field filters via crm_lead_values.
     * Returns null if no EAV filter is needed (caller must not restrict by lead IDs).
     * Returns an int[] of matching lead IDs (may be empty — meaning no leads match).
     *
     * @param  string $conn  e.g. "mysql_42"
     * @return int[]|null
     */
    public function buildEavFilter(Request $request, string $conn): ?array
    {
        $fieldFilters = [];

        // General full-text search across all EAV values
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            return DB::connection($conn)
                ->table('crm_lead_values')
                ->where('field_value', 'LIKE', $term)
                ->distinct()
                ->pluck('lead_id')
                ->map(fn($id) => (int) $id)
                ->toArray();
        }

        // Per-field filters (AND logic — lead must match all)
        $fieldMap = [
            'first_name'   => ['LIKE', '%{value}%'],
            'last_name'    => ['LIKE', '%{value}%'],
            'email'        => ['LIKE', '%{value}%'],
            'company_name' => ['LIKE', '%{value}%'],
            'phone_number' => ['LIKE', '{value}%'],
        ];

        $filteredIds = null;

        foreach ($fieldMap as $key => [$op, $pattern]) {
            if (!$request->filled($key)) {
                continue;
            }

            $term = str_replace('{value}', $request->input($key), $pattern);

            $ids = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('field_key', $key)
                ->where('field_value', $op, $term)
                ->distinct()
                ->pluck('lead_id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $filteredIds = $filteredIds === null
                ? $ids
                : array_values(array_intersect($filteredIds, $ids));
        }

        // Also handle dba (arbitrary field key lookup)
        if ($request->filled('dba')) {
            $dbaKey = DB::connection($conn)
                ->table('crm_labels')
                ->whereRaw('LOWER(label_name) = ?', ['dba'])
                ->value('field_key');

            if ($dbaKey) {
                $term = '%' . $request->input('dba') . '%';
                $ids  = DB::connection($conn)
                    ->table('crm_lead_values')
                    ->where('field_key', $dbaKey)
                    ->where('field_value', 'LIKE', $term)
                    ->distinct()
                    ->pluck('lead_id')
                    ->map(fn($id) => (int) $id)
                    ->toArray();

                $filteredIds = $filteredIds === null
                    ? $ids
                    : array_values(array_intersect($filteredIds, $ids));
            }
        }

        return $filteredIds;
    }

    /**
     * Build a LIMIT clause from request pagination parameters.
     */
    public function buildLimitClause(Request $request): string
    {
        if ($request->has('lower_limit') && $request->has('upper_limit')
            && is_numeric($request->input('lower_limit'))
            && is_numeric($request->input('upper_limit'))) {
            return ' LIMIT ' . (int) $request->input('lower_limit') . ', ' . (int) $request->input('upper_limit');
        }

        if ($request->has('start') && $request->has('limit')) {
            return ' LIMIT ' . (int) $request->input('start') . ', ' . (int) $request->input('limit');
        }

        return '';
    }

    /**
     * Execute a lead list query against crm_leads with optional EAV filter.
     *
     * @param  string   $conn          Database connection name
     * @param  string[] $conditions    WHERE clause fragments (for system columns)
     * @param  array    $bindings      Positional bindings for $conditions
     * @param  string   $limitClause   e.g. " LIMIT 0, 20"
     * @param  bool     $subLeadsOnly  When true, adds lead_parent_id != 0
     * @param  string   $orderBy       Column to sort by (default: created_at)
     * @param  int[]|null $eavLeadIds  Optional list of lead IDs from EAV filter
     * @return array{records: array, count: int}
     */
    public function fetchLeads(
        string  $conn,
        array   $conditions,
        array   $bindings,
        string  $limitClause,
        bool    $subLeadsOnly = false,
        string  $orderBy = 'created_at',
        ?array  $eavLeadIds = null
    ): array {
        // If EAV filter returned an empty set — no results possible
        if ($eavLeadIds !== null && empty($eavLeadIds)) {
            return ['records' => [], 'count' => 0];
        }

        if ($eavLeadIds !== null) {
            $ph = implode(',', array_fill(0, count($eavLeadIds), '?'));
            $conditions[] = "id IN ({$ph})";
            array_push($bindings, ...$eavLeadIds);
        }

        $baseWhere = $subLeadsOnly
            ? 'is_deleted = 0 AND lead_parent_id != 0'
            : 'is_deleted = 0';

        $filter   = !empty($conditions) ? ' AND ' . implode(' AND ', $conditions) : '';
        $sql      = "SELECT * FROM crm_leads WHERE {$baseWhere}{$filter} ORDER BY {$orderBy} DESC{$limitClause}";
        $countSql = "SELECT COUNT(*) as count FROM crm_leads WHERE {$baseWhere}{$filter}";

        $records  = DB::connection($conn)->select($sql, $bindings);
        $countRow = DB::connection($conn)->selectOne($countSql, $bindings);
        $count    = $countRow ? (int) ((array) $countRow)['count'] : 0;

        return ['records' => $records, 'count' => $count];
    }
}
