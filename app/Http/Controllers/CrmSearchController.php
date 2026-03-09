<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmSearchController extends Controller
{
    /**
     * POST /crm/leads/search
     * Advanced multi-field search with pagination.
     * Agents only see their own leads.
     */
    public function search(Request $request)
    {
        try {
            $clientId  = $request->auth->parent_id;
            $userId    = $request->auth->id;
            $userLevel = $request->auth->user_level ?? 0;

            $filters   = $request->input('filters', []);
            $perPage   = min((int)$request->input('per_page', 25), 200);
            $page      = max((int)$request->input('page', 1), 1);
            $sortBy    = $request->input('sort_by', 'updated_at');
            $sortDir   = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

            // Whitelist sort columns
            $allowedSort = ['id', 'first_name', 'last_name', 'company_name', 'created_at', 'updated_at', 'lead_status', 'assigned_to'];
            if (!in_array($sortBy, $allowedSort)) {
                $sortBy = 'updated_at';
            }

            $columns = $request->input('columns', [
                'id', 'first_name', 'last_name', 'phone_number', 'email',
                'company_name', 'lead_status', 'assigned_to', 'created_at', 'updated_at',
                'created_by', 'updated_by',
            ]);

            // Safe column whitelist
            $allowedCols = array_merge(
                ['id', 'first_name', 'last_name', 'email', 'phone_number', 'company_name',
                 'lead_status', 'lead_type', 'assigned_to', 'created_at', 'updated_at',
                 'city', 'state', 'country', 'address', 'dob', 'gender', 'lead_source_id',
                 'created_by', 'updated_by'],
                array_map(fn($i) => "option_$i", range(1, 750))
            );
            $safeColumns = array_values(array_intersect($columns, $allowedCols));
            if (empty($safeColumns)) {
                $safeColumns = ['id', 'first_name', 'last_name', 'phone_number', 'email', 'lead_status'];
            }

            $query = DB::connection("mysql_$clientId")
                ->table('crm_lead_data')
                ->where('is_deleted', 0);

            // Agent restriction
            if ($userLevel <= 1) {
                $query->where('assigned_to', $userId);
            }

            // ── Standard filters ──────────────────────────────────────────
            if (!empty($filters['lead_status'])) {
                $query->whereIn('lead_status', (array)$filters['lead_status']);
            }
            if (!empty($filters['assigned_to'])) {
                $query->whereIn('assigned_to', (array)$filters['assigned_to']);
            }
            if (!empty($filters['lead_type'])) {
                $query->where('lead_type', $filters['lead_type']);
            }
            if (!empty($filters['created_from'])) {
                $query->whereDate('created_at', '>=', $filters['created_from']);
            }
            if (!empty($filters['created_to'])) {
                $query->whereDate('created_at', '<=', $filters['created_to']);
            }
            if (!empty($filters['updated_from'])) {
                $query->whereDate('updated_at', '>=', $filters['updated_from']);
            }
            if (!empty($filters['updated_to'])) {
                $query->whereDate('updated_at', '<=', $filters['updated_to']);
            }

            // Specific field filters
            if (!empty($filters['company_name'])) {
                $query->where('company_name', 'like', "%{$filters['company_name']}%");
            }
            if (!empty($filters['phone_number'])) {
                $query->where('phone_number', 'like', "%{$filters['phone_number']}%");
            }
            if (!empty($filters['email'])) {
                $query->where('email', 'like', "%{$filters['email']}%");
            }
            if (!empty($filters['industry_type'])) {
                $query->where('industry_type', $filters['industry_type']);
            }

            // Global search across key fields
            if (!empty($filters['search'])) {
                $s = $filters['search'];
                $query->where(function ($q) use ($s) {
                    $q->where('first_name', 'like', "%{$s}%")
                      ->orWhere('last_name',    'like', "%{$s}%")
                      ->orWhere('email',         'like', "%{$s}%")
                      ->orWhere('phone_number',  'like', "%{$s}%")
                      ->orWhere('company_name',  'like', "%{$s}%");
                });
            }

            // ── Dynamic field filters ─────────────────────────────────────
            if (!empty($filters['dynamic_fields'])) {
                // Validate column names against crm_label to prevent injection
                $validColumns = DB::connection("mysql_$clientId")
                    ->table('crm_label')
                    ->where('is_deleted', 0)
                    ->where('status', '1')
                    ->pluck('column_name')
                    ->filter()
                    ->flip()
                    ->toArray();

                foreach ((array)$filters['dynamic_fields'] as $df) {
                    $col      = $df['column']   ?? '';
                    $operator = $df['operator'] ?? 'contains';
                    $val      = $df['value']    ?? '';

                    // Only allow validated column names
                    if (!isset($validColumns[$col])) {
                        continue;
                    }

                    switch ($operator) {
                        case 'equals':       $query->where($col, $val); break;
                        case 'not_equals':   $query->where($col, '!=', $val); break;
                        case 'contains':     $query->where($col, 'like', "%{$val}%"); break;
                        case 'starts_with':  $query->where($col, 'like', "{$val}%"); break;
                        case 'ends_with':    $query->where($col, 'like', "%{$val}"); break;
                        case 'gte':          $query->where($col, '>=', $val); break;
                        case 'lte':          $query->where($col, '<=', $val); break;
                        case 'is_empty':     $query->where(fn($q) => $q->whereNull($col)->orWhere($col, '')); break;
                        case 'is_not_empty': $query->where(fn($q) => $q->whereNotNull($col)->where($col, '!=',' ')); break;
                    }
                }
            }

            $total   = $query->count();
            $records = (clone $query)
                ->select($safeColumns)
                ->orderBy($sortBy, $sortDir)
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            // Resolve user names for created_by / updated_by (batch lookup from master users table)
            try {
                $userIds = collect($records)
                    ->flatMap(fn($r) => [(int)($r->created_by ?? 0), (int)($r->updated_by ?? 0)])
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                if (!empty($userIds)) {
                    $users = \DB::table('users')
                        ->whereIn('id', $userIds)
                        ->select('id', 'first_name', 'last_name')
                        ->get()
                        ->keyBy('id');

                    $records = $records->map(function ($row) use ($users) {
                        $arr = (array)$row;
                        if (!empty($arr['created_by']) && isset($users[$arr['created_by']])) {
                            $u = $users[$arr['created_by']];
                            $arr['created_by_name'] = trim($u->first_name . ' ' . $u->last_name);
                        }
                        if (!empty($arr['updated_by']) && isset($users[$arr['updated_by']])) {
                            $u = $users[$arr['updated_by']];
                            $arr['updated_by_name'] = trim($u->first_name . ' ' . $u->last_name);
                        }
                        return $arr;
                    });
                }
            } catch (\Throwable $e) {}

            $lastPage = (int)ceil($total / $perPage);

            return $this->successResponse("Search Results", [
                'records'    => $records,
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'from'         => ($page - 1) * $perPage + 1,
                    'to'           => min($page * $perPage, $total),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Search failed", [$e->getMessage()], $e, 500);
        }
    }
}
