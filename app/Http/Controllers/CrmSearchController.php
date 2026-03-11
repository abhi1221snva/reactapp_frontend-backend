<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmSearchController extends Controller
{
    /**
     * POST /crm/leads/search
     * Advanced multi-field search with pagination against the EAV architecture.
     */
    public function search(Request $request)
    {
        try {
            $clientId  = $request->auth->parent_id;
            $userId    = $request->auth->id;
            $userLevel = $request->auth->user_level ?? 0;

            $filters = $request->input('filters', []);
            $perPage = min((int)$request->input('per_page', 25), 200);
            $page    = max((int)$request->input('page', 1), 1);
            $sortBy  = $request->input('sort_by', 'updated_at');
            $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

            $allowedSort = ['id', 'created_at', 'updated_at', 'lead_status', 'assigned_to',
                            'first_name', 'last_name', 'company_name'];
            if (!in_array($sortBy, $allowedSort)) {
                $sortBy = 'updated_at';
            }

            $conn = DB::connection("mysql_$clientId");

            // ── Base conditions on crm_leads ──────────────────────────────
            $query = $conn->table('crm_leads as cl');

            if ($userLevel <= 1) {
                $query->where('cl.assigned_to', $userId);
            }

            // System column filters
            if (!empty($filters['lead_status'])) {
                $query->whereIn('cl.lead_status', (array)$filters['lead_status']);
            }
            if (!empty($filters['assigned_to'])) {
                $query->whereIn('cl.assigned_to', (array)$filters['assigned_to']);
            }
            if (!empty($filters['lead_type'])) {
                $query->where('cl.lead_type', $filters['lead_type']);
            }
            if (!empty($filters['created_from'])) {
                $query->whereDate('cl.created_at', '>=', $filters['created_from']);
            }
            if (!empty($filters['created_to'])) {
                $query->whereDate('cl.created_at', '<=', $filters['created_to']);
            }
            if (!empty($filters['updated_from'])) {
                $query->whereDate('cl.updated_at', '>=', $filters['updated_from']);
            }
            if (!empty($filters['updated_to'])) {
                $query->whereDate('cl.updated_at', '<=', $filters['updated_to']);
            }

            // ── EAV field filters (each as an IN subquery) ────────────────
            $eavFilters = ['email', 'phone_number', 'company_name', 'industry_type', 'first_name', 'last_name'];
            foreach ($eavFilters as $field) {
                if (!empty($filters[$field])) {
                    $val = $filters[$field];
                    $query->whereIn('cl.id', function ($sub) use ($field, $val) {
                        $sub->select('lead_id')
                            ->from('crm_lead_values')
                            ->where('field_key', $field)
                            ->where('field_value', 'like', "%{$val}%");
                    });
                }
            }

            // Global search across key EAV fields
            if (!empty($filters['search'])) {
                $s = $filters['search'];
                $query->whereIn('cl.id', function ($sub) use ($s) {
                    $sub->select('lead_id')
                        ->from('crm_lead_values')
                        ->whereIn('field_key', ['first_name', 'last_name', 'email', 'phone_number', 'company_name'])
                        ->where('field_value', 'like', "%{$s}%");
                });
            }

            // ── Count (before adding sort JOIN) ────────────────────────────
            $total = (clone $query)->count();

            // ── Sorting ────────────────────────────────────────────────────
            $eavSortFields = ['first_name', 'last_name', 'company_name'];
            if (in_array($sortBy, $eavSortFields)) {
                $sortKey = $sortBy;
                $query->leftJoin(
                    DB::raw("(SELECT lead_id, field_value FROM `{$conn->getDatabaseName()}`.crm_lead_values WHERE field_key = '{$sortKey}') AS _sort"),
                    'cl.id', '=', '_sort.lead_id'
                )->select('cl.*')->orderBy('_sort.field_value', $sortDir);
            } else {
                $query->select('cl.*')->orderBy("cl.{$sortBy}", $sortDir);
            }

            // ── Paginate ───────────────────────────────────────────────────
            $leadRows = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

            // ── Hydrate with EAV values ────────────────────────────────────
            $leadIds = $leadRows->pluck('id')->filter()->values()->toArray();
            $eavMap  = [];
            if (!empty($leadIds)) {
                $vals = $conn->table('crm_lead_values')
                    ->whereIn('lead_id', $leadIds)
                    ->get(['lead_id', 'field_key', 'field_value']);
                foreach ($vals as $v) {
                    $eavMap[$v->lead_id][$v->field_key] = $v->field_value;
                }
            }

            $records = $leadRows->map(function ($row) use ($eavMap) {
                $arr = (array)$row;
                $id  = $arr['id'];
                if (!empty($eavMap[$id])) {
                    // Merge EAV data under the record; system columns take precedence
                    $arr = array_merge($eavMap[$id], $arr);
                }
                return $arr;
            });

            // ── Resolve user names for created_by / updated_by ────────────
            try {
                $userIds = collect($records)
                    ->flatMap(fn($r) => [(int)($r['created_by'] ?? 0), (int)($r['updated_by'] ?? 0)])
                    ->filter()->unique()->values()->toArray();

                if (!empty($userIds)) {
                    $users = DB::table('users')
                        ->whereIn('id', $userIds)
                        ->select('id', 'first_name', 'last_name')
                        ->get()->keyBy('id');

                    $records = $records->map(function ($r) use ($users) {
                        if (!empty($r['created_by']) && isset($users[$r['created_by']])) {
                            $u = $users[$r['created_by']];
                            $r['created_by_name'] = trim($u->first_name . ' ' . $u->last_name);
                        }
                        if (!empty($r['updated_by']) && isset($users[$r['updated_by']])) {
                            $u = $users[$r['updated_by']];
                            $r['updated_by_name'] = trim($u->first_name . ' ' . $u->last_name);
                        }
                        return $r;
                    });
                }
            } catch (\Throwable $e) {}

            $lastPage = max(1, (int)ceil($total / $perPage));

            return $this->successResponse("Search Results", [
                'records'    => $records->values(),
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'from'         => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
                    'to'           => min($page * $perPage, $total),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Search failed", [$e->getMessage()], $e, 500);
        }
    }
}
