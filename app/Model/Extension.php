<?php

namespace App\Model;

use App\Exceptions\RenderableException;
use App\Jobs\ExtensionNotificationJob;
use App\Model\Client\ExtensionGroupMap;
use App\Services\RolesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use App\Model\Master\Client;
use Illuminate\Support\Facades\Http;


class Extension extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'users';

    public function listExtensions(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $orderBy = $request->has('orderBy') ? $request->get('orderBy') : "users.extension";
        if ($request->auth->level >= 7) {
            $sql = "SELECT users.*, user_extensions.ipaddr, user_extensions.fullcontact FROM users left join user_extensions on user_extensions.name=users.extension WHERE users.id IN (SELECT user_id FROM permissions WHERE client_id = $clientId) AND users.is_deleted = 0 AND users.status = 0 order by $orderBy";
        } elseif ($request->auth->level >= 5) {
            $extenstions = ExtensionGroupMap::on("mysql_$clientId")
                ->whereIn("group_id", $request->auth->groups)
                ->where("is_deleted", "=", "0")
                ->get()->pluck("extension")->all();

            $sql = "SELECT users.*, user_extensions.ipaddr, user_extensions.fullcontact FROM users left join user_extensions on user_extensions.name=users.extension WHERE users.id IN (SELECT user_id FROM permissions WHERE client_id = $clientId) AND users.extension IN (" . implode(",", $extenstions) . ") AND users.is_deleted = 0 AND users.status = 0 order by $orderBy";
        } else {
            $sql = "SELECT users.*,user_extensions.ipaddr , user_extensions.fullcontact FROM users left join user_extensions on user_extensions.name=users.extension WHERE users.id=" . $request->auth->id . " AND users.is_deleted = 0 AND users.status = 0";
        }
        var_dump($sql);
        die();
        $record = DB::connection('master')->select($sql);
        return (array)$record;
    }


    /*
     *Fetch extension list
     *@param integer $id
     *@return array
     */
public function extensionDetailold(Request $request, int $extension_id = null)
{
            $parentId = $request->auth->parent_id;

    $data['parent_id'] = $request->auth->parent_id;
    // ✅ Fetch DID list for this client (cli and voip_provider)
    $didList = Dids::on('mysql_' . $parentId)
        ->where([
            ['sms_email', '=', $extension_id],
            ['sms', '=', 1],
        ])
        ->get(['cli', 'voip_provider']);

    if ($extension_id) {
        // ----------------- SINGLE EXTENSION DETAIL -----------------
        $user = User::findOrFail($extension_id);
        $response = $user->toArray();
        $extension = $response['extension'];

        if (strlen($extension) == 4) {
            $extension = $request->auth->parent_id . $extension;
        }

        // Fetch extension group
        $extensionGroupSql = "SELECT group_id FROM extension_group_map WHERE extension = :extension AND is_deleted = :is_deleted";
        $extensionGroup = DB::connection('mysql_' . $request->auth->parent_id)->select($extensionGroupSql, [
            'extension' => $extension,
            'is_deleted' => 0
        ]);
        $response['group'] = $extensionGroup;

        // Fetch server list
        $serverSql = "SELECT asterisk_server.id, host AS ip_address, detail, domain, title_name 
                      FROM client_server 
                      LEFT JOIN asterisk_server ON asterisk_server.id = client_server.ip_address 
                      WHERE client_server.client_id = :parent_id";
        $serverList = DB::connection('master')->select($serverSql, [
            'parent_id' => $request->auth->parent_id
        ]);
        $response['serverList'] = $serverList;
        $response['didList'] = $didList;

        return [
            'success' => true,
            'message' => 'Extension detail.',
            'data' => $response,
            'total_rows' => 1
        ];
    } else {
        // ----------------- LIST WITH SEARCH -----------------
        $response = [];
        $parentId = $request->auth->parent_id;
        $status = 0;
        $isDeleted = 0;
        $totalRows = 0;
        $search = trim($request->input('search', '')); // ✅ search input (optional)

        $bindings = [$parentId, $isDeleted, $status, $parentId];
        $searchSql = '';

        // ✅ Add search conditions if search text provided
        if (!empty($search)) {
            $searchSql = " AND (
                users.first_name LIKE ?
                OR users.last_name LIKE ?
                OR users.email LIKE ?
                OR users.extension LIKE ?
            )";

            $bindings[] = "%$search%";
            $bindings[] = "%$search%";
            $bindings[] = "%$search%";
            $bindings[] = "%$search%";
        }


        if ($request->auth->level > 5) {
            $orderBy = $request->get('orderBy', 'users.extension');

            // Count query
            $countSql = "SELECT COUNT(*) as total FROM users 
                         WHERE id IN (
                             SELECT user_id FROM permissions WHERE client_id = ?
                         ) 
                         AND is_deleted = ? 
                         AND status = ? 
                         AND base_parent_id = ?
                         AND user_level < 9
                         $searchSql";

            $countResult = DB::connection('master')->selectOne($countSql, $bindings);
            $totalRows = $countResult->total ?? 0;

            // Data query
            $sql = "SELECT users.*, user_extensions.ipaddr, user_extensions.fullcontact, user_extensions.secret
                    FROM users
                    LEFT JOIN user_extensions ON user_extensions.name = users.extension
                    WHERE users.id IN (
                        SELECT user_id FROM permissions WHERE client_id = ?
                    )
                    AND users.is_deleted = ?
                    AND users.status = ?
                    AND users.base_parent_id = ?
                    AND users.user_level < 9
                    $searchSql
                    ORDER BY $orderBy";

            // Add limit if present
            if ($request->has(['start', 'limit'])) {
                $start = (int)$request->input('start');
                $limit = (int)$request->input('limit');
                $sql .= " LIMIT ?, ?";
                $bindings[] = $start;
                $bindings[] = $limit;
            }
        } else {
            $totalRows = 1;

            // Non-admin user (single result)
            $sql = "SELECT users.*, user_extensions.ipaddr, user_extensions.fullcontact, user_extensions.secret
                    FROM users
                    LEFT JOIN user_extensions ON user_extensions.name = users.extension
                    WHERE users.parent_id = ?
                    AND users.id = ?
                    AND users.is_deleted = ?
                    AND users.status = ?
                    AND users.base_parent_id = ?
                    AND users.user_level < 9
                    $searchSql
                    ORDER BY users.extension";

            $bindings = [$parentId, $request->auth->id, $isDeleted, $status, $parentId];

            if (!empty($search)) {
                $bindings[] = "%$search%";
                $bindings[] = "%$search%";
                $bindings[] = "%$search%";
            }
        }

        $record = DB::connection('master')->select($sql, $bindings);
        foreach ($record as $res) {
            if ($res->id == $request->auth->id) {
                $response[0] = $res;
            } else {
                $response[] = $res;
            }
        }

        if (!empty($response)) {
            return [
                'success' => true,
                'total_rows' => $totalRows,
                'message' => 'Extension detail.',
                'data' => $response
            ];
        }

        return [
            'success' => false,
            'total_rows' => 0,
            'message' => 'Extension not found',
            'data' => [],
        ];
    }
}


public function extensionDetail(Request $request, int $extension_id = null)
{
    $parentId = $request->auth->parent_id;

    // ----------------- DID LIST -----------------
    $didList = Dids::on('mysql_' . $parentId)
        ->where([
            ['sms_email', '=', $extension_id],
            ['sms', '=', 1],
        ])
        ->get(['cli', 'voip_provider']);

    // ================= SINGLE EXTENSION =================
    if ($extension_id) {

        $user = User::findOrFail($extension_id);
        $response = $user->toArray();
        $extension = $response['extension'];

        if (strlen($extension) == 4) {
            $extension = $parentId . $extension;
        }

        $response['group'] = DB::connection('mysql_' . $parentId)->select(
            "SELECT group_id 
             FROM extension_group_map 
             WHERE extension = :extension AND is_deleted = :is_deleted",
            [
                'extension'  => $extension,
                'is_deleted' => 0
            ]
        );

        $response['serverList'] = DB::connection('master')->select(
            "SELECT asterisk_server.id, host AS ip_address, detail, domain, title_name
             FROM client_server
             LEFT JOIN asterisk_server ON asterisk_server.id = client_server.ip_address
             WHERE client_server.client_id = :parent_id",
            ['parent_id' => $parentId]
        );

        $response['didList'] = $didList;

        return [
            'success'    => true,
            'message'    => 'Extension detail.',
            'total_rows' => 1,
            'data'       => $response
        ];
    }

    // ================= LIST WITH SEARCH =================
    $response   = [];
    $status     = 0;
    $isDeleted  = 0;
    $totalRows  = 0;
    $search     = trim($request->input('search', ''));

    // ---------- SEARCH ----------
    $searchSql = '';
    $searchBindings = [];

    if ($search !== '') {
        $searchSql = " AND (
            users.first_name LIKE ?
            OR users.last_name LIKE ?
            OR users.email LIKE ?
            OR users.extension LIKE ?
        )";

        $searchBindings = array_fill(0, 4, "%{$search}%");
    }

    // ================= ADMIN =================
 // ================= ADMIN =================
if ($request->auth->level > 5) {

    $orderBy = $request->get('orderBy', 'users.extension');

    // Prevent SQL injection in orderBy
    $allowedOrderColumns = [
        'users.extension',
        'users.first_name',
        'users.email',
        'users.created_at'
    ];

    if (!in_array($orderBy, $allowedOrderColumns)) {
        $orderBy = 'users.extension';
    }

    // ---------- COUNT ----------
    $countSql = "
        SELECT COUNT(*) AS total
        FROM users
        WHERE users.base_parent_id = ?
        AND users.is_deleted = 0
        AND (
            users.status = 0
            OR users.id = ?
        )
        AND (
            users.user_level < 9
            OR users.id = ?
        )
        $searchSql
    ";

    $countBindings = [
        $parentId,
        $request->auth->id,
        $request->auth->id
    ];

    $countBindings = array_merge($countBindings, $searchBindings);

    $countResult = DB::connection('master')->selectOne(
        $countSql,
        $countBindings
    );

    $totalRows = $countResult->total ?? 0;

    // ---------- DATA ----------
    $sql = "
        SELECT users.*, user_extensions.ipaddr, user_extensions.fullcontact, user_extensions.secret
        FROM users
        LEFT JOIN user_extensions ON user_extensions.name = users.extension
        WHERE users.base_parent_id = ?
        AND users.is_deleted = 0
        AND (
            users.status = 0
            OR users.id = ?
        )
        AND (
            users.user_level < 9
            OR users.id = ?
        )
        $searchSql
        ORDER BY {$orderBy}
    ";

    $dataBindings = [
        $parentId,
        $request->auth->id,
        $request->auth->id
    ];

    $dataBindings = array_merge($dataBindings, $searchBindings);

    if ($request->has(['start', 'limit'])) {
        $sql .= " LIMIT ?, ?";
        $dataBindings[] = (int) $request->input('start');
        $dataBindings[] = (int) $request->input('limit');
    }
}
    // ================= NON-ADMIN =================
    else {

        $totalRows = 1;

        $sql = "
            SELECT users.*, user_extensions.ipaddr, user_extensions.fullcontact, user_extensions.secret
            FROM users
            LEFT JOIN user_extensions ON user_extensions.name = users.extension
            WHERE users.parent_id = ?
              AND users.id = ?
              AND users.is_deleted = ?
              AND (
                    users.status = ?
                    OR users.id = ?
                )
              AND users.base_parent_id = ?
                AND (
                    users.user_level < 9
                    OR users.id = ?
                )
              $searchSql
            ORDER BY users.extension
        ";

        $dataBindings = [
            $parentId,
            $request->auth->id,
            $isDeleted,
            $status,
            $request->auth->id,
            $parentId,
            $request->auth->id,

        ];

        $dataBindings = array_merge($dataBindings, $searchBindings);
    }

    // ================= EXECUTE =================
    $records = DB::connection('master')->select($sql, $dataBindings);
$response = $records;
    // foreach ($records as $row) {
    //     if ($row->id == $request->auth->id) {
    //         $response[0] = $row;
    //     } else {
    //         $response[] = $row;
    //     }
    // }

    return !empty($response)
        ? [
            'success'    => true,
            'total_rows' => $totalRows,
            'message'    => 'Extension detail.',
            'data'       => $response
        ]
        : [
            'success'    => false,
            'total_rows' => 0,
            'message'    => 'Extension not found',
            'data'       => []
        ];
}

  

    /*
     *Add Extension
     *@param object $request
     *@return array
     */


    // public function extensionDetailList(Request $request, int $extension_id = null)
    // {
    //     $data['parent_id'] = $request->auth->parent_id;
    //     if ($extension_id) {
    //         $user = User::findOrFail($extension_id);
    //         $response = $user->toArray();
    //         $extension = $response['extension'];
    //         if (strlen($extension) == 4) {
    //             $extension = $request->auth->parent_id . $extension;
    //         }

    //         //$extensionGroupSql= "SELECT eg.id, eg.title FROM extension_group_map as egm LEFT JOIN extension_group as eg ON eg.id = egm.group_id WHERE egm.extension = :extension AND egm.is_deleted = :is_deleted";
    //         $extensionGroupSql = "SELECT group_id FROM extension_group_map as egm  WHERE egm.extension = :extension AND egm.is_deleted = :is_deleted";
    //         $extensionGroup = DB::connection('mysql_' . $request->auth->parent_id)->select($extensionGroupSql, array('extension' => $extension, 'is_deleted' => '0'));
    //         $extensionGroupResponse = (array)$extensionGroup;
    //         $response['group'] = $extensionGroupResponse;

    //         // fetch server allotment list
    //         //$serverSql= "SELECT id,ip_address,detail FROM client_server WHERE client_id = :parent_id ";
    //         $serverSql = "SELECT asterisk_server.id,host as ip_address,detail,domain,title_name FROM client_server Left join asterisk_server on asterisk_server.id = client_server.ip_address WHERE client_server.client_id = :parent_id";
    //         $serverList = DB::connection('master')->select($serverSql, array('parent_id' => $request->auth->parent_id));
    //         $serverListResponse = (array)$serverList;
    //         $response['serverList'] = $serverListResponse;

    //         $packageName = $user->getAssignedUserPackage();
    //         if (!empty($packageName)) {
    //             $response['assignedPackageKey'] = $packageName->package_key;
    //             $response['assignedPackage'] = ucfirst($packageName->name) . ' - ' . date('Y-m-d', strtotime($packageName->start_time)) . ' to ' . date('Y-m-d', strtotime($packageName->end_time));
    //         } else {
    //             $response['assignedPackageKey'] = null;
    //             $response['assignedPackage'] = null;
    //         }
    //     } else {

    //         $data['status'] = 0;
    //         $data['is_deleted'] = 0;
    //         #if admin or above
    //         if ($request->auth->level >= 7) {
    //             $orderBy = $request->has('orderBy') ? $request->get('orderBy') : "users.extension";
    //             $sql = "SELECT users.*,user_extensions.ipaddr , user_extensions.fullcontact,user_extensions.secret FROM " . $this->table . " left join user_extensions on user_extensions.name=users.extension WHERE users.id IN (SELECT user_id FROM permissions WHERE client_id = :parent_id) AND  users.is_deleted = :is_deleted AND users.status = :status order by $orderBy";
    //         } else {
    //             $data['id'] = $request->auth->id;
    //             $sql = "SELECT users.*,user_extensions.ipaddr , user_extensions.fullcontact,user_extensions.secret FROM " . $this->table . " left join user_extensions on user_extensions.name=users.extension WHERE users.parent_id = :parent_id AND users.id=:id AND  users.is_deleted = :is_deleted AND users.status = :status order by users.extension";
    //         }
    //         $record = DB::connection('master')->select($sql, $data);
    //         $response = (array)$record;
    //              // Apply pagination if present
    //     if ($request->has(['start', 'limit'])) {
    //         $start = (int)$request->input('start');
    //         $limit = (int)$request->input('limit');
    //         $response = array_slice($response, $start, $limit, true); // paginate array
    //     }
    //     }


    //     if (!empty($response)) {
    //         return array(
    //             'success' => true,
    //             'message' => 'Extension detail.',
    //             'data' => $response
    //         );
    //     }
    //     return array(
    //         'success' => false,
    //         'message' => 'Extension not found',
    //         'data' => array()
    //     );
    // }

    // public function extensionDetailList(Request $request, int $extension_id = null)
    // {
    //     $data['parent_id'] = $request->auth->parent_id;

    //     if ($extension_id) {
    //         $user = User::findOrFail($extension_id);
    //         $response = $user->toArray();
    //         $extension = $response['extension'];

    //         if (strlen($extension) == 4) {
    //             $extension = $request->auth->parent_id . $extension;
    //         }

    //         $extensionGroupSql = "SELECT group_id FROM extension_group_map as egm  
    //                           WHERE egm.extension = :extension AND egm.is_deleted = :is_deleted";
    //         $extensionGroup = DB::connection('mysql_' . $request->auth->parent_id)->select(
    //             $extensionGroupSql,
    //             ['extension' => $extension, 'is_deleted' => '0']
    //         );
    //         $response['group'] = (array) $extensionGroup;

    //         $serverSql = "SELECT asterisk_server.id,host as ip_address,detail,domain,title_name 
    //                   FROM client_server 
    //                   LEFT JOIN asterisk_server ON asterisk_server.id = client_server.ip_address 
    //                   WHERE client_server.client_id = :parent_id";
    //         $serverList = DB::connection('master')->select($serverSql, ['parent_id' => $request->auth->parent_id]);
    //         $response['serverList'] = (array) $serverList;

    //         $packageName = $user->getAssignedUserPackage();
    //         $response['assignedPackageKey'] = $packageName->package_key ?? null;
    //         $response['assignedPackage'] = $packageName
    //             ? ucfirst($packageName->name) . ' - ' . date('Y-m-d', strtotime($packageName->start_time)) . ' to ' . date('Y-m-d', strtotime($packageName->end_time))
    //             : null;
    //     } else {
    //         $data['status'] = 0;
    //         $data['is_deleted'] = 0;

    //         $where = "users.is_deleted = :is_deleted AND users.status = :status";
    //         $bindings = $data;

    //         if ($request->auth->level >= 7) {
    //             $bindings['parent_id'] = $request->auth->parent_id;
    //             $where .= " AND users.id IN (SELECT user_id FROM permissions WHERE client_id = :parent_id)";
    //         } else {
    //             $bindings['id'] = $request->auth->id;
    //             $where .= " AND users.parent_id = :parent_id AND users.id = :id";
    //         }

    //         // Search filter
    //         if ($request->filled('search')) {
    //             $search = '%' . $request->input('search') . '%';
    //             $where .= " AND (users.extension LIKE :search_ext OR users.first_name LIKE :search_name)";
    //             $bindings['search_ext'] = $search;
    //             $bindings['search_name'] = $search;
    //         }

    //         $orderBy = $request->get('orderBy', 'users.extension');

    //         $sql = "SELECT users.*, user_extensions.ipaddr, user_extensions.fullcontact, user_extensions.secret 
    //     FROM users 
    //     LEFT JOIN user_extensions ON user_extensions.fullname = users.extension 
    //     WHERE $where 
    //     ORDER BY $orderBy";

    //         $record = DB::connection('master')->select($sql, $bindings);

    //         $total = count($record); // Total before pagination

    //         // Apply pagination
    //         if ($request->has(['start', 'limit'])) {
    //             $start = (int) $request->input('start');
    //             $limit = (int) $request->input('limit');
    //             $record = array_slice($record, $start, $limit, true);
    //         }

    //         $response = [
    //             'data' => $record,
    //             'total' => $total
    //         ];
    //     }

    //     if (!empty($response)) {
    //         return [
    //             'success' => true,
    //             'message' => 'Extension detail.',
    //             'data' => $response['data'] ?? $response,
    //             'total' => $response['total'] ?? null
    //         ];
    //     }

    //     return [
    //         'success' => false,
    //         'message' => 'Extension not found',
    //         'data' => [],
    //         'total' => 0
    //     ];
    // }
public function extensionDetailList(Request $request, int $extension_id = null)
{
    $data['parent_id'] = $request->auth->parent_id;

    if ($extension_id) {
        $user = User::findOrFail($extension_id);
        $response = $user->toArray();
        $extension = $response['extension'];

        if (strlen($extension) == 4) {
            $extension = $request->auth->parent_id . $extension;
        }

        $extensionGroupSql = "SELECT group_id FROM extension_group_map as egm  
                              WHERE egm.extension = :extension AND egm.is_deleted = :is_deleted";
        $extensionGroup = DB::connection('mysql_' . $request->auth->parent_id)->select(
            $extensionGroupSql,
            ['extension' => $extension, 'is_deleted' => '0']
        );
        $response['group'] = (array) $extensionGroup;

        $serverSql = "SELECT asterisk_server.id,host as ip_address,detail,domain,title_name 
                      FROM client_server 
                      LEFT JOIN asterisk_server ON asterisk_server.id = client_server.ip_address 
                      WHERE client_server.client_id = :parent_id";
        $serverList = DB::connection('master')->select($serverSql, ['parent_id' => $request->auth->parent_id]);
        $response['serverList'] = (array) $serverList;

        $packageName = $user->getAssignedUserPackage();
        $response['assignedPackageKey'] = $packageName->package_key ?? null;
        $response['assignedPackage'] = $packageName
            ? ucfirst($packageName->name) . ' - ' . date('Y-m-d', strtotime($packageName->start_time)) . ' to ' . date('Y-m-d', strtotime($packageName->end_time))
            : null;
    } else {
        //$data['status'] = 0;
        $data['is_deleted'] = 0;

        //$where = "users.is_deleted = :is_deleted AND users.status = :status";
      $where = "users.is_deleted = :is_deleted AND users.status IN (0,1)";

        $bindings = $data;

        if ($request->auth->level >= 7) {
            $bindings['parent_id'] = $request->auth->parent_id;
            $where .= " AND users.id IN (SELECT user_id FROM permissions WHERE client_id = :parent_id)";
        } else {
            $bindings['id'] = $request->auth->id;
            $where .= " AND users.parent_id = :parent_id AND users.id = :id";
        }

        // --- Generic search filter ---
        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $where .= " 
                AND (
                    users.extension LIKE :search_ext 
                    OR users.first_name LIKE :search_name 
                    OR users.last_name LIKE :search_last 
                    OR users.email LIKE :search_email
                    OR CONCAT(users.first_name, ' ', users.last_name) LIKE :search_full
                )";
            $bindings['search_ext']  = $search;
            $bindings['search_name'] = $search;
            $bindings['search_last'] = $search;
            $bindings['search_email'] = $search;
            $bindings['search_full'] = $search;
        }

        // --- Individual filters ---
        if ($request->filled('first_name')) {
            $where .= " AND users.first_name LIKE :first_name";
            $bindings['first_name'] = '%' . $request->input('first_name') . '%';
        }

        if ($request->filled('last_name')) {
            $where .= " AND users.last_name LIKE :last_name";
            $bindings['last_name'] = '%' . $request->input('last_name') . '%';
        }

        if ($request->filled('extension')) {
            $where .= " AND users.extension LIKE :extension";
            $bindings['extension'] = '%' . $request->input('extension') . '%';
        }
          if ($request->filled('email')) {
            $where .= " AND users.email LIKE :email";
            $bindings['email'] = '%' . $request->input('email') . '%';
        }

        // --- Safe order by ---
        $allowedOrderBy = [
            'users.id'         => 'users.id',
            'users.created_at' => 'users.created_at',
            'users.first_name' => 'users.first_name',
            'users.last_name'  => 'users.last_name',
            'users.extension'  => 'CAST(users.extension AS UNSIGNED)', // numeric sort
        ];

        $orderByKey = $request->get('orderBy', 'users.id');
        $orderBy = $allowedOrderBy[$orderByKey] ?? 'users.id';

        // --- total count ---
        $countSql = "SELECT COUNT(*) AS total FROM users WHERE $where";
        $totalRow = DB::connection('master')->selectOne($countSql, $bindings);
        $total = (int) ($totalRow->total ?? 0);

        // --- main query ---
        $sqlBase = "SELECT users.*, user_extensions.ipaddr, user_extensions.fullcontact, user_extensions.secret
                    FROM users
                    LEFT JOIN user_extensions ON user_extensions.fullname = users.extension
                    WHERE $where
                    ORDER BY $orderBy DESC";

        if ($request->has(['start','limit'])) {
            $start = max(0, (int) $request->input('start'));
            $limit = max(1, (int) $request->input('limit'));
            $sql = $sqlBase . " LIMIT {$limit} OFFSET {$start}";
            $record = DB::connection('master')->select($sql, $bindings);
        } else {
            $record = DB::connection('master')->select($sqlBase, $bindings);
        }

        $response = [
            'data'  => $record,
            'total' => $total
        ];
    }

    if (!empty($response)) {
        return [
            'success' => true,
            'message' => 'Extension detail.',
            'total'   => $response['total'] ?? null,
            'data'    => $response['data'] ?? $response
        ];
    }

    return [
        'success' => false,
        'message' => 'Extension not found',
        'data'    => [],
        'total'   => 0
    ];
}


    function move_to_first_in_array($array, $key)
    {
        return [$key => $array[$key]] + $array;
    }


    public function extensionDetailListCRM(Request $request, int $extension_id = null)
    {
        $data['parent_id'] = $request->auth->parent_id;
        if ($extension_id) {
            $user = User::findOrFail($extension_id);
            $response = $user->toArray();
            $extension = $response['extension'];
            if (strlen($extension) == 4) {
                $extension = $request->auth->parent_id . $extension;
            }

            //$extensionGroupSql= "SELECT eg.id, eg.title FROM extension_group_map as egm LEFT JOIN extension_group as eg ON eg.id = egm.group_id WHERE egm.extension = :extension AND egm.is_deleted = :is_deleted";
            $extensionGroupSql = "SELECT group_id FROM extension_group_map as egm  WHERE egm.extension = :extension ";
            $extensionGroup = DB::connection('mysql_' . $request->auth->parent_id)->select($extensionGroupSql, array('extension' => $extension));
            $extensionGroupResponse = (array)$extensionGroup;
            $response['group'] = $extensionGroupResponse;

            // fetch server allotment list
            //$serverSql= "SELECT id,ip_address,detail FROM client_server WHERE client_id = :parent_id ";
            $serverSql = "SELECT asterisk_server.id,host as ip_address,detail,domain,title_name FROM client_server Left join asterisk_server on asterisk_server.id = client_server.ip_address WHERE client_server.client_id = :parent_id";
            $serverList = DB::connection('master')->select($serverSql, array('parent_id' => $request->auth->parent_id));
            $serverListResponse = (array)$serverList;
            $response['serverList'] = $serverListResponse;

            $packageName = $user->getAssignedUserPackage();
            if (!empty($packageName)) {
                $response['assignedPackageKey'] = $packageName->package_key;
                $response['assignedPackage'] = ucfirst($packageName->name) . ' - ' . date('Y-m-d', strtotime($packageName->start_time)) . ' to ' . date('Y-m-d', strtotime($packageName->end_time));
            } else {
                $response['assignedPackageKey'] = null;
                $response['assignedPackage'] = null;
            }
        } else {

            $data['status'] = 0;
            #if admin or above
            if ($request->auth->level >= 7) {
                $orderBy = $request->has('orderBy') ? $request->get('orderBy') : "users.extension";
                $sql = "SELECT users.*,user_extensions.ipaddr , user_extensions.fullcontact,user_extensions.secret FROM " . $this->table . " left join user_extensions on user_extensions.name=users.extension WHERE users.id IN (SELECT user_id FROM permissions WHERE client_id = :parent_id) AND   users.status = :status order by $orderBy";
            } else {
                $data['id'] = $request->auth->id;
                $sql = "SELECT users.*,user_extensions.ipaddr , user_extensions.fullcontact,user_extensions.secret FROM " . $this->table . " left join user_extensions on user_extensions.name=users.extension WHERE users.parent_id = :parent_id AND users.id=:id AND   users.status = :status order by users.extension";
            }
            $record = DB::connection('master')->select($sql, $data);
            $response = (array)$record;
        }

        if (!empty($response)) {
            return array(
                'success' => true,
                'message' => 'Extension detail.',
                'data' => $response
            );
        }
        return array(
            'success' => false,
            'message' => 'Extension not found',
            'data' => array()
        );
    }


    public function addExtension($request)
    {
        try {
            $data = array();
            $dialPadArray = array(
                "a" => '2',
                "b" => '2',
                "c" => '2',
                "d" => '3',
                "e" => '3',
                "f" => '3',
                "g" => '4',
                "h" => '4',
                "i" => '4',
                "j" => '5',
                "k" => '5',
                "l" => '5',
                "m" => '6',
                "n" => '6',
                "o" => '6',
                "p" => '7',
                "q" => '7',
                "r" => '7',
                "s" => '7',
                "t" => '8',
                "u" => '8',
                "v" => '8',
                "w" => '0',
                "x" => '9',
                "y" => '9',
                "z" => '9'
            );

            if (
                $request->has('first_name') && !empty($request->input('first_name')) &&
                $request->has('email') && !empty($request->input('email')) &&
                $request->has('id') && !empty($request->input('id')) &&
                $request->has('password') && !empty($request->input('password'))
            ) {


                $data['first_name'] = $request->input('first_name');
                $first_names = strtolower($data['first_name']);
                $result = substr($first_names, 0, 3);
                $array = str_split($result);

                foreach ($array as $char) {
                    if (array_key_exists($char, $dialPadArray)) {
                        $dialpad[] = $dialPadArray[$char];
                    }
                }

                $dialpadNumber = implode(',', $dialpad);
                $finaldialPad = str_replace(',', '', $dialpadNumber);

                //last name for dialpad_lastname

                $data['last_name'] = $request->input('last_name');
                $last_names = strtolower($data['last_name']);
                $result = substr($last_names, 0, 3);
                $array = str_split($result);
                foreach ($array as $char) {
                    if (array_key_exists($char, $dialPadArray)) {
                        $dialpadLastName[] = $dialPadArray[$char];
                    }
                }

                $dialpadNumberLatName = implode(',', $dialpadLastName);
                $finaldialPadLastName = str_replace(',', '', $dialpadNumberLatName);


                $data['dialpad'] = $finaldialPad;
                $data['dialpad_lastname'] = $finaldialPadLastName;

                $data['last_name'] = !empty($request->input('last_name')) ? $request->input('last_name') : '';
                $data['email'] = $request->input('email');
                $data['mobile'] = !empty($request->input('mobile')) ? $request->input('mobile') : '';
                $data['password'] = Hash::make($request->input('password'));
                $data['follow_me'] = !empty($request->input('follow_me')) ? $request->input('follow_me') : '';
                $data['call_forward'] = !empty($request->input('call_forward')) ? $request->input('call_forward') : '';
                $data['voicemail'] = !empty($request->input('voicemail')) ? $request->input('voicemail') : '';
                $data['vm_pin'] = !empty($request->input('vm_pin')) ? $request->input('vm_pin') : '';
                $data['voicemail_send_to_email'] = !empty($request->input('voicemail_send_to_email')) ? $request->input('voicemail_send_to_email') : '';
                $data['parent_id'] = $request->auth->parent_id;
                $data['role'] = 2;
                $role = RolesService::getById($data['role']);
                $data['user_level'] = $role["level"];
                $data['extension'] = $request->input('extension_id');
                $data['alt_extension'] = !empty($request->input('alt_extension')) ? $request->input('alt_extension') : '';

                //Fetch company details and Asterisk server id
                $sql = "SELECT * FROM " . $this->table . " WHERE id = :id";
                $adminDetail = DB::connection('master')->selectOne($sql, array('id' => $request->input('id')));
                if (!empty($adminDetail)) {
                    $admin = (array)$adminDetail;
                    $data['company_name'] = $admin['company_name'];
                    $data['address_1'] = $admin['address_1'];
                    $data['address_2'] = $admin['address_2'];
                    $data['asterisk_server_id'] = $admin['asterisk_server_id'];
                }
                //Fetch extension
                /* $sql = "SELECT max(extension) as ext FROM ".$this->table." WHERE parent_id = :id";
                 $newExtension =  DB::connection('master')->selectOne($sql, array('id' => $request->input('id')));
                 if(!empty($newExtension))
                 {
                     $newExt = (array)$newExtension;
                     $data['extension'] = $newExt['ext'] + 1;
                 }*/

                // return $data;
                $query = "INSERT INTO " . $this->table . "
                (first_name, last_name, email, mobile, password, follow_me, call_forward, voicemail, vm_pin, voicemail_send_to_email, parent_id, role, company_name, address_1, address_2, asterisk_server_id, extension,dialpad,dialpad_lastname) VALUE
                (:first_name, :last_name, :email, :mobile, :password, :follow_me, :call_forward, :voicemail, :vm_pin, :voicemail_send_to_email, :parent_id, :role, :company_name, :address_1, :address_2, :asterisk_server_id, :extension,:dialpad ,:dialpad_lastname, alt_extension= :alt_extension)";
                $add = DB::connection('master')->update($query, $data);
                if ($add == 1) {
                    $newAdd = DB::connection('master')->selectOne("SELECT * FROM " . $this->table . " ORDER BY id DESC ", array());
                    $newAdd = (array)$newAdd;
                    foreach ($request->input('group_id') as $value) {
                        $sql = "INSERT INTO extension_group_map (extension, group_id) VALUE (:extension, :group_id),(:alt_extension, :same_group_id) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
                        DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('is_deleted' => 0, 'extension' => $data['extension'], 'alt_extension' =>  $data['alt_extension'], 'group_id' => $value, 'same_group_id' => $value));
                    }

                    // Update user_extension table
                    $sql_uext = "SELECT count(*) as row_count FROM user_extensions WHERE username = :username";
                    $record_ustext = DB::connection('master')->selectOne($sql_uext, array('username' => $newAdd["extension"]));
                    $response_ust = (array)$record_ustext;
                    if ($response_ust['row_count'] == 0) {
                        $dt['name'] = $newAdd["extension"];
                        $dt['username'] = $newAdd["extension"];
                        $dt['secret'] = $request->input('password');
                        $dt['context'] = 'user-extensions-phones'; //'default';
                        $dt['host'] = 'dynamic';
                        $dt['nat'] = 'force_rport,comedia';
                        $dt['qualify'] = 'no';
                        $dt['type'] = 'friend';
                        $dt['fullname'] = $request->input('first_name') . ' ' . $request->input('last_name');
                        $insertData = "INSERT INTO user_extensions SET fullname= :fullname, context= :context, name= :name, type= :type , qualify= :qualify , nat= :nat , host= :host, secret= :secret,username= :username";
                        $record_ustextSav = DB::connection('master')->select($insertData, $dt);
                    } else {
                        $dt['name'] = $newAdd["extension"];
                        $dt['username'] = $newAdd["extension"];
                        $dt['fullname'] = $request->input('first_name') . ' ' . $request->input('last_name');
                        $insertData = "UPDATE user_extensions SET name= :name , fullname= :fullname WHERE username= :username ";
                        $record_ustext = DB::connection('master')->select($insertData, $dt);
                    }

                    // $this->configUpdate($request->input('id'));
                    return array(
                        'success' => 'true',
                        'message' => 'Extension added successfully.',
                        'data' => $newAdd
                    );
                }
                return array(
                    'success' => 'false',
                    'message' => 'Extension are not added successfully.'
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Extension not created. Required Details are missing',
                'data' => $data
            );
        } catch (\Throwable $e) {
            Log::error("Extension.addExtension", [
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
        }
    }

    /*
     *Edit Extension
     *@param object $request
     *@return array
     */
    public function editExtension($request)
    {
        try {
            $data = array();
            $updateString = array();
            $isDeleteAction = false;

            if ($request->has('extension_id') && !empty($request->input('extension_id'))) {
                $data = array('id' => $request->input('extension_id'), 'role' => 2);

                if ($request->has('first_name') && !empty($request->input('first_name'))) {
                    array_push($updateString, 'first_name = :first_name');
                    $data['first_name'] = $request->input('first_name');
                }
                if ($request->has('last_name') && !empty($request->input('last_name'))) {
                    array_push($updateString, 'last_name = :last_name');
                    $data['last_name'] = $request->input('last_name');
                }
                if ($request->has('email') && !empty($request->input('email'))) {
                    array_push($updateString, 'email = :email');
                    $data['email'] = $request->input('email');
                }
                if ($request->has('mobile') && !empty($request->input('mobile'))) {
                    array_push($updateString, 'mobile = :mobile');
                    $data['mobile'] = $request->input('mobile');
                }
                if ($request->has('password') && !empty($request->input('password'))) {
                    array_push($updateString, 'password = :password');
                    $data['password'] = Hash::make($request->input('password'));
                }
                if ($request->has('follow_me') && !empty($request->input('follow_me'))) {
                    array_push($updateString, 'follow_me = :follow_me');
                    $data['follow_me'] = $request->input('follow_me');
                }
                if ($request->has('call_forward') && !empty($request->input('call_forward'))) {
                    array_push($updateString, 'call_forward = :call_forward');
                    $data['call_forward'] = $request->input('call_forward');
                }
                if ($request->has('voicemail') && !empty($request->input('voicemail'))) {
                    array_push($updateString, 'voicemail = :voicemail');
                    $data['voicemail'] = $request->input('voicemail');
                }
                if ($request->has('vm_pin') && !empty($request->input('vm_pin')) && is_numeric($request->input('vm_pin'))) {
                    array_push($updateString, 'vm_pin = :vm_pin');
                    $data['vm_pin'] = $request->input('vm_pin');
                }
                if ($request->has('voicemail_send_to_email') && !empty($request->input('voicemail_send_to_email'))) {
                    array_push($updateString, 'voicemail_send_to_email = :voicemail_send_to_email');
                    $data['voicemail_send_to_email'] = $request->input('voicemail_send_to_email');
                }
               if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
                array_push($updateString, 'is_deleted = :is_deleted');
                $data['is_deleted'] = $request->input('is_deleted');

                if ((int)$request->input('is_deleted') === 1) {
                    $isDeleteAction = true;
                }
}

                if (!empty($updateString)) {
                    $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE id = :id AND role = :role";
                    DB::connection('master')->update($query, $data);

                    //fetch extension
                    $sql = "SELECT extension, alt_extension, email FROM " . $this->table . "  WHERE id = :id";
                    $record = DB::connection('master')->selectOne($sql, array('id' => $request->input('extension_id')));
                    $server = (array)$record;
                    if (!empty($server)) {
                        $extension = $server['extension'];
                    }

                    $this->configUpdate($request->input('id'));

                    if (intval($data['is_deleted']) === 1) {
                        //remove extension group map:
                        $query = "UPDATE extension_group_map set is_deleted =:is_deleted WHERE extension = :extension";
                        DB::connection('mysql_' . $request->auth->parent_id)->update($query, ["is_deleted" => 1, "extension" => $extension]);

                        $query = "UPDATE extension_group_map set is_deleted =:is_deleted WHERE extension = :alt_extension";
                        DB::connection('mysql_' . $request->auth->parent_id)->update($query, ["is_deleted" => 1, "alt_extension" => $server['alt_extension']]);

                        //remove from user_extensions:
                        $query = "DELETE FROM user_extensions WHERE name = :name";
                        DB::connection('master')->delete($query, ["name" => $extension]);

                        //remove from permissions:
                        $query = "DELETE FROM permissions WHERE user_id = :id";
                        DB::connection('master')->delete($query, [
                            "id" => $request->input('extension_id')
                        ]);



                        $query_user_package = "UPDATE user_packages set user_id =:null_value WHERE user_id = :id";
                        DB::connection('mysql_' . $request->auth->parent_id)->update($query_user_package, [
                            "null_value" => NULL,
                            "id" => $request->input('extension_id')
                        ]);
                        $is_deleted = '1';
                        //mask email for next use
                        $query = "UPDATE users SET email = :masked , is_deleted = :is_deleted WHERE id = :id";
                        DB::connection('master')->delete($query, [
                            "masked" => "del-" . date("YmdHis") . "-" . $server["email"],
                            "id" => $request->input('extension_id'),
                            "is_deleted" => $is_deleted,
                        ]);

                        $notificationData = [
                            "action" => "Extension deleted",
                            "user" => $data
                        ];
                        dispatch(new ExtensionNotificationJob($request->auth->parent_id, $notificationData))->onConnection("database");
                    }

                   return array(
                        'success' => 'true',
                        'message' => $isDeleteAction
                            ? 'Extension deleted successfully'
                            : 'Extension updated successfully'
                    );

                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Nothing to update.'
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Unable to update Extension. Required Details are missing',
                'data' => $data
            );
        } catch (\Throwable $e) {
            Log::error("Extension.editExtension", [
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
        }
    }

    public function editExtensionSave($request)
    {
        if ($request->has('extension_id') && !empty($request->input('extension_id'))) {
            $updateGroup = true;

            if (($request->call_forward == 1 || $request->twinning == 1) && empty($request->mobile)) {
                return array(
                    'success' => 'false',
                    'message' => 'Enter mobile number'
                );
            }
            //$data = array('id' => $request->input('extension_id'), 'role' => 2);
            $dialPadArray = array(
                "a" => '2',
                "b" => '2',
                "c" => '2',
                "d" => '3',
                "e" => '3',
                "f" => '3',
                "g" => '4',
                "h" => '4',
                "i" => '4',
                "j" => '5',
                "k" => '5',
                "l" => '5',
                "m" => '6',
                "n" => '6',
                "o" => '6',
                "p" => '7',
                "q" => '7',
                "r" => '7',
                "s" => '7',
                "t" => '8',
                "u" => '8',
                "v" => '8',
                "w" => '0',
                "x" => '9',
                "y" => '9',
                "z" => '9'
            );
            $first_names = strtolower($request->input('first_name'));
            $result_fname = substr($first_names, 0, 3);
            $array_fname = str_split($result_fname);
            foreach ($array_fname as $char) {
                if (array_key_exists($char, $dialPadArray)) {
                    $dialpad[] = $dialPadArray[$char];
                }
            }
            $dialpadNumber = implode(',', $dialpad);
            $finaldialPad = str_replace(',', '', $dialpadNumber);

            //last name for dialpad_lastname

            $data['last_name'] = $request->input('last_name');
            $last_names = strtolower($data['last_name']);
            $result = substr($last_names, 0, 3);
            $array = str_split($result);
            foreach ($array as $char) {
                if (array_key_exists($char, $dialPadArray)) {
                    $dialpadLastName[] = $dialPadArray[$char];
                }
            }

            $dialpadNumberLatName = implode(',', $dialpadLastName);
            $finaldialPadLastName = str_replace(',', '', $dialpadNumberLatName);



            $data['dialpad'] = $finaldialPad;
            $data['dialpad_lastname'] = $finaldialPadLastName;
            $data['id'] = $request->input('extension_id');
            $data['first_name'] = $request->input('first_name');
            $data['last_name'] = $request->input('last_name');
            $data['mobile'] = $request->input('mobile');
            $data['country_code'] = $request->input('country_code');

            $data['follow_me'] = $request->input('follow_me');
            $data['call_forward'] = $request->input('call_forward');
            $data['voicemail'] = $request->input('voicemail');
            $data['vm_pin'] = $request->input('vm_pin');
            $data['voicemail_send_to_email'] = $request->input('voicemail_send_to_email');
            $data['twinning'] = $request->input('twinning');
            // $data['asterisk_server_id'] = $request->input('asterisk_server_id');
            $data['cli_setting'] = $request->input('cli_setting');
            $data['cli'] = $request->input('cli');
            $data['cnam'] = $request->input('cnam');
            $data['extension_type'] = $request->input('extension_type');
            $data['sms_setting_id'] = $request->input('sms_setting_id');
            $data['receive_sms_on_email'] = $request->input('receive_sms_on_email');
            $data['receive_sms_on_mobile'] = $request->input('receive_sms_on_mobile');
            $data['ip_filtering'] = $request->input('ip_filtering');
            $data['enable_2fa'] = $request->input('enable_2fa');
            $data['voip_configuration_id'] = $request->input('voip_configuration_id');
            $data['app_status'] = $request->input('app_status');

            $data['timezone'] = $request->input('timezone');





            //$data['group_id']       = $request->input('group_id');
            // $query = "UPDATE ".$this->table." SET first_name = :first_name , last_name = :last_name , mobile = :mobile , follow_me = :follow_me , call_forward= :call_forward , voicemail= :voicemail , vm_pin= :vm_pin , voicemail_send_to_email= :voicemail_send_to_email, twinning= :twinning , asterisk_server_id= :asterisk_server_id , cli_setting= :cli_setting , cli =:cli, cnam =:cnam, dialpad= :dialpad WHERE id= :id ";
                $query = "UPDATE " . $this->table . " SET extension_type = :extension_type , first_name = :first_name , last_name = :last_name , mobile = :mobile ,country_code=:country_code, follow_me = :follow_me , call_forward= :call_forward , voicemail= :voicemail , vm_pin= :vm_pin , voicemail_send_to_email= :voicemail_send_to_email, twinning= :twinning , cli_setting= :cli_setting , cli =:cli, cnam =:cnam, dialpad= :dialpad,dialpad_lastname=:dialpad_lastname,sms_setting_id=:sms_setting_id,receive_sms_on_email=:receive_sms_on_email,receive_sms_on_mobile=:receive_sms_on_mobile,ip_filtering=:ip_filtering,app_status=:app_status,enable_2fa=:enable_2fa,voip_configuration_id=:voip_configuration_id,timezone=:timezone  WHERE id= :id ";
       // Step 1: Find user based on request parameters

if ($request->extension_id) {

    // If extension_id exists → match with id
    $userProfile = User::where('id', $request->extension_id)->first();

} elseif ($request->uuid) {

    // If only uuid exists → match with easify_user_uuid
    $userProfile = User::where('easify_user_uuid', $request->uuid)->first();

} else {

    return [
        'success' => false,
        'message' => 'extension_id or uuid is required'
    ];
}


// Step 2: Check if user exists
if (!$userProfile) {
    return [
        'success' => false,
        'message' => 'User not found'
    ];
}

$authenticatedUser=User::where('id',$request->auth->id)->first();
// Step 3: Get UUID for Easify API
$easify_user_uuid = $authenticatedUser->easify_user_uuid;
Log::info('reached easify_user_uuid',['easify_user_uuid'=>$easify_user_uuid]);

$apiUrl = env('EASIFY_URL') . '/api/users/update';

$payload = [
    "id" => $request->extension_id,
    "email" => $request->email,
    "first_name" => $request->first_name,
    "last_name" => $request->last_name,
    "phone" => $request->mobile,
    "only_validate" => true
];

$validateResponse = Http::withHeaders([
    'X-Application-Token' => env('PHONIFY_APP_TOKEN'),
    'X-Easify-User-Token' => $easify_user_uuid,
    'Content-Type' => 'application/json'
])->post($apiUrl, $payload);

       Log::info('reached easifyresponse',['status' => $validateResponse->status(),
'validateResponse'=>$validateResponse]);

if (!$validateResponse->successful()) {
    return [
        'success' => false,
        'message' => 'Easify validation failed',
        'error' => $validateResponse->body()
    ];
}
            $update = DB::connection('master')->update($query, $data);
                    if ($update) {

                $payload["only_validate"] = false;

                $updateResponse = Http::withHeaders([
                    'X-Application-Token' => env('PHONIFY_APP_TOKEN'),
                    'X-Easify-User-Token' => $easify_user_uuid,
                    'Content-Type' => 'application/json'
                ])->post($apiUrl, $payload);

                Log::info('Easify Update Response', [
                    'status' => $updateResponse->status(),
                       'response' => $updateResponse->json(),

                ]);
                
            }

            if (isset($request->group_id)) {
                if (count($request->group_id) > 0) {
                    $sql = "SELECT extension, alt_extension FROM " . $this->table . "  WHERE id = :id";
                    $record = DB::connection('master')->selectOne($sql, array('id' => $request->extension_id));
                    $server = (array)$record;
                    if (!empty($server)) {
                        $extension = $server['extension'];
                    }
                    if (strlen($extension) == 4) {
                        $extension = $request->auth->parent_id . $extension;
                    }

                    // Update user_extension table
                    $sql_uext = "SELECT count(*) as row_count FROM user_extensions WHERE username = :username";
                    $record_ustext = DB::connection('master')->selectOne($sql_uext, array('username' => $extension));
                    $response_ust = (array)$record_ustext;
                    if ($response_ust['row_count'] == 0) {
                        $dt['name'] = $extension;
                        $dt['username'] = $extension;
                        $dt['secret'] = $request->input('password');
                        $dt['context'] = 'user-extensions-phones'; //'default';
                        $dt['host'] = 'dynamic';
                        $dt['nat'] = 'force_rport,comedia';
                        $dt['qualify'] = 'no';
                        $dt['type'] = 'friend';
                        $dt['fullname'] = $request->input('first_name') . ' ' . $request->input('last_name');
                        $insertData = "INSERT INTO user_extensions SET fullname= :fullname, context= :context, name= :name, type= :type , qualify= :qualify , nat= :nat , host= :host, secret= :secret,username= :username";
                        $record_ustextSav = DB::connection('master')->select($insertData, $dt);
                    } else {
                        $dt['name'] = $extension;
                        $dt['username'] = $extension;
                        $dt['fullname'] = $request->input('first_name') . ' ' . $request->input('last_name');
                        $insertData = "UPDATE user_extensions SET name= :name , fullname= :fullname WHERE username= :username";
                        $record_ustext = DB::connection('master')->select($insertData, $dt);
                    }

                    $query = "UPDATE extension_group_map set is_deleted =:is_deleted WHERE extension = :extension";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->update($query, array('is_deleted' => 1, 'extension' => $extension));

                    $query = "UPDATE extension_group_map set is_deleted =:is_deleted WHERE extension= :alt_extension";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->update($query, array('is_deleted' => 1, 'alt_extension' => $server['alt_extension']));
                    foreach ($request->input('group_id') as $value) {
                        $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id), (:alt_extension, :same_group_id) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
                        $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('is_deleted' => 0, 'extension' => $extension, 'group_id' => $value, 'same_group_id' => $value, 'alt_extension' => $server['alt_extension']));
                    }
                }
            }

            if ($update == true || $updateGroup == true) {
                return array(
                    'success' => 'true',
                    'message' => 'Extension updated successfully',
                    'data' => $request->input('extension_id')
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Nothing to update.'
                );
            }
        }
    }

    public function clientIpList($request)
    {
        $data['id'] = $request->auth->parent_id;
        $query = "SELECT cs.*,host,domain,title_name FROM client_server as cs inner join asterisk_server on cs.ip_address=asterisk_server.id WHERE client_id= :id ";
        $userData = DB::connection('master')->select($query, $data);
        $response = (array)$userData;
        if (!empty($response)) {
            return array(
                'success' => 'true',
                'message' => 'client ip detail.',
                'data' => $response
            );
        }
        return array(
            'success' => 'false',
            'message' => 'client ip not created.',
            'data' => array()
        );
    }


    // public function editExtensionSave(Request $request){
    //     try
    //     {
    //         $data = array();
    //         $updateString = array();
    //         if($request->has('extension_id') && !empty($request->input('extension_id')))
    //         {
    //             $data = array('id' => $request->input('extension_id'), 'role' => 2);
    //             if($request->has('first_name') && !empty($request->input('first_name')))
    //             {
    //                 array_push($updateString, 'first_name = :first_name');
    //                 $data['first_name'] = $request->input('first_name');
    //             }
    //             if($request->has('last_name') && !empty($request->input('last_name')))
    //             {
    //                 array_push($updateString, 'last_name = :last_name');
    //                 $data['last_name'] = $request->input('last_name');
    //             }
    //             if($request->has('email') && !empty($request->input('email')))
    //             {
    //                 array_push($updateString, 'email = :email');
    //                 $data['email'] = $request->input('email');
    //             }
    //             if($request->has('mobile') && !empty($request->input('mobile')))
    //             {
    //                 array_push($updateString, 'mobile = :mobile');
    //                 $data['mobile'] = $request->input('mobile');
    //             }
    //             // if($request->has('password') && !empty($request->input('password')))
    //             // {
    //             //     array_push($updateString, 'password = :password');
    //             //     $data['password'] = Hash::make($request->input('password'));
    //             // }
    //             if($request->has('follow_me') && !empty($request->input('follow_me')))
    //             {
    //                 array_push($updateString, 'follow_me = :follow_me');
    //                 $data['follow_me'] = $request->input('follow_me');
    //             }
    //             if($request->has('call_forward') && !empty($request->input('call_forward')))
    //             {
    //                 array_push($updateString, 'call_forward = :call_forward');
    //                 $data['call_forward'] = $request->input('call_forward');
    //             }
    //             if($request->has('voicemail') && !empty($request->input('voicemail')))
    //             {
    //                 array_push($updateString, 'voicemail = :voicemail');
    //                 $data['voicemail'] = $request->input('voicemail');
    //             }
    //             if($request->has('vm_pin') && !empty($request->input('vm_pin')) && is_numeric($request->input('vm_pin')))
    //             {
    //                 array_push($updateString, 'vm_pin = :vm_pin');
    //                 $data['vm_pin'] = $request->input('vm_pin');
    //             }
    //             if($request->has('voicemail_send_to_email') && !empty($request->input('voicemail_send_to_email')))
    //             {
    //                 array_push($updateString, 'voicemail_send_to_email = :voicemail_send_to_email');
    //                 $data['voicemail_send_to_email'] = $request->input('voicemail_send_to_email');
    //             }
    //             // if($request->has('is_deleted') && is_numeric($request->input('is_deleted')))
    //             // {
    //             //     array_push($updateString, 'is_deleted = :is_deleted');
    //             //     $data['is_deleted'] = $request->input('is_deleted');
    //             // }
    //             if(!empty($updateString))
    //             {
    //                 $query = "UPDATE ".$this->table." set ".implode(" , ", $updateString)." WHERE id = :id AND role = :role";
    //                 DB::connection('master')->update($query, $data);
    //                 //Update extension group map:
    //                 //fetch extension
    //                 $sql = "SELECT extension FROM ".$this->table."  WHERE id = :id";
    //                 $record =  DB::connection('master')->selectOne($sql, array('id' => $request->input('extension_id')));
    //                 $server = (array)$record;
    //                 if(!empty($server))
    //                 {
    //                     $extension = $server['extension'];
    //                 }
    //                 $query = "UPDATE extension_group_map set is_deleted =:is_deleted WHERE extension = :extension";
    //                 DB::connection('mysql_'.$request->auth->parent_id)->update($query, array('is_deleted' => 1, 'extension' => $extension));
    //                 foreach ($request->input('group_id') as $value)
    //                 {
    //                     $sql = "INSERT INTO extension_group_map (extension, group_id) VALUE (:extension, :group_id) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
    //                     DB::connection('mysql_'.$request->auth->parent_id)->insert($sql, array('is_deleted' => 0, 'extension' => $extension, 'group_id' => $value));
    //                 }
    //                 $this->configUpdate($request->input('id'));
    //                 return array(
    //                     'success'=> 'true',
    //                     'message'=> 'Extension updated successfully.'
    //                 );
    //             }
    //             else
    //             {
    //                 return array(
    //                     'success'=> 'false',
    //                     'message'=> 'Nothing to update.'
    //                 );
    //             }
    //             return array(
    //                 'success'=> 'false',
    //                 'message'=> 'Extension are not updated successfully.'
    //             );

    //         }
    //         return array(
    //             'success'=> 'false',
    //             'message'=> 'Unable to update Extension. Required Details are missing',
    //             'data'   => $data
    //         );
    //     }
    //     catch (Exception $e)
    //     {
    //         Log::log($e->getMessage());
    //     }
    //     catch (InvalidArgumentException $e)
    //     {
    //         Log::log($e->getMessage());
    //     }
    // }
    /*
     * Update config file
     * @param numeric $id
     * @return binary
     */
    protected function configUpdate($id)
    {
        //fetch Asterisk server
        $sql = "SELECT asterisk_server_id FROM " . $this->table . "  WHERE id = :id";
        $record = DB::connection('master')->selectOne($sql, array('id' => $id));
        $server = (array)$record;
        if (!empty($server)) {
            $server = $server['asterisk_server_id'];
        } else {
            Log::warning("Extension.configUpdate($id) is missing asterisk_server_id");
            return;
        }

        //Create extension file
        $str = '';
        $sql = "SELECT extension, first_name, last_name, password FROM " . $this->table . " WHERE asterisk_server_id = :asterisk_server_id AND is_deleted = :is_deleted AND status = :status";
        $extensionList = DB::connection('master')->select($sql, array('asterisk_server_id' => $server, 'is_deleted' => 0, 'status' => 1));
        $extensionList = (array)$extensionList;
        foreach ($extensionList as $key => $value) {
            $str .= "\n[" . $value->extension . "]\n";
            $str .= "username=" . $value->extension . "\n";
            $str .= "secret=" . $value->password . "\n";
            $str .= "accountcode=" . $value->extension . "\n";
            $str .= 'callerid="' . $value->first_name . " " . $value->last_name . '"<' . $value->extension . ">\n";
            $str .= "mailbox=" . $value->extension . "\n";
            $str .= "context=default\n";
            $str .= "type=friend\n";
            $str .= "host=dynamic";
        }
        /*$tempFile = "manage_extension_".$id.".conf";
        Storage::disk('local')->put($tempFile, $str);*/
        $filePath = env('asterisk_conf');
        $sql = "SELECT * FROM asterisk_server  WHERE id = :id";
        $record = DB::connection('master')->selectOne($sql, array('id' => $server));
        if (empty($record)) {
            Log::warning("Extension.configUpdate($id) no asterisk_server found with id $server");
            return;
        }

        $serverDetail = (array)$record;
        $hostname = $serverDetail['host'];
        $username = $serverDetail['user'];
        $password = $serverDetail['secret'];

        #$hostname = "147.135.10.204";
        #$username = "root";
        #$password = "EvYTpjFwQwkf";

        /*$sourceFile = "test.txt";
        $targetFile = "/root/test.txt";
        $connection = ssh2_connect($hostname, 22);
        ssh2_auth_password($connection, $username, $password);
        ssh2_scp_send($connection, $sourceFile, $targetFile, 0777);*/
        $ftp = Storage::createSftpDriver([
            'host' => $hostname,
            'username' => $username,
            'password' => $password,
            'timeout' => '30',
        ]);
        $ftp->put($filePath, $str, 'public');
        return;
    }


    public function checkEmail($request)
    {
        try {
            $clientId = $request->auth->parent_id;
            if ($request->has('email')) {
                $data['email'] = $request->input('email');
                $data['is_deleted'] = 0;
                $sql = "SELECT * FROM users  WHERE email = :email and is_deleted = :is_deleted ";
                $record = DB::connection('master')->selectOne($sql, $data);
                $response = (array)$record;
            }

            if (!empty($response)) {
                return array(
                    'success' => 'false',
                    'message' => 'Email Already Exists.',
                    //'data'   => $response
                );
            }
            return array(
                'success' => 'true',
                'message' => 'Email is Available.',
                //'data'   => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

//     public function checkExtension($request)
//     {
//         try {
//             $clientId = $request->auth->parent_id;
//             if ($request->has('extension') && is_numeric($request->input('extension'))) {
//                 $data['extension'] = $clientId . $request->input('extension');
//                 $data['is_deleted'] = 0;
//                 $sql = "SELECT * FROM users  WHERE extension = :extension and is_deleted = :is_deleted ";
//                 $record = DB::connection('master')->selectOne($sql, $data);
//                 $response = (array)$record;
//             }
// Log::info('response',['response'=>$response]);
//             if (!is_null($response)) {
//                 return array(
//                     'success' => 'false',
//                     'message' => 'Extension Already Exists.',
//                     //'data'   => $response
//                 );
//             }
//             return array(
//                 'success' => 'true',
//                 'message' => 'Extension is Available.',
//                 //'data'   => array()
//             );
//         } catch (Exception $e) {
//             Log::log($e->getMessage());
//         } catch (InvalidArgumentException $e) {
//             Log::log($e->getMessage());
//         }
//     }
public function checkExtension($request)
{
    try {
        $clientId = $request->auth->parent_id;
        $response = null;

        if ($request->has('extension') && is_numeric($request->input('extension'))) {
            // 🔹 Adjust based on how you store extensions in DB
            $data['extension'] = $request->input('extension');  
            //$data['extension'] = $clientId . $request->input('extension'); // if stored with parentId prefix
            $data['is_deleted'] = 0;

            $sql = "SELECT * FROM users WHERE extension = :extension AND is_deleted = :is_deleted";
            $record = DB::connection('master')->selectOne($sql, $data);

            $response = $record; // object or null
        }

        Log::info('response', ['response' => $response]);

        if ($response !== null) {
            return [
                'success' => 'false',
                'message' => 'Extension Already Exists.',
            ];
        }

        return [
            'success' => 'true',
            'message' => 'Extension is Available.',
        ];

    } catch (Exception $e) {
        Log::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
    }
}

    // public function checkAltExtension($request)
    // {
    //     try {
    //         $clientId = $request->auth->parent_id;
    //         if ($request->has('alt_extension') && is_numeric($request->input('alt_extension'))) {
    //             $data['alt_extension'] = $clientId . $request->input('alt_extension');
    //             $data['is_deleted'] = 0;
    //             $sql = "SELECT * FROM users  WHERE alt_extension = :alt_extension and is_deleted = :is_deleted ";
    //             $record = DB::connection('master')->selectOne($sql, $data);
    //             $response = (array)$record;
    //         }

    //         if (!empty($response)) {
    //             return array(
    //                 'success' => 'false',
    //                 'message' => 'Alternate Extension Already Exists.',
    //                 //'data'   => $response
    //             );
    //         }
    //         return array(
    //             'success' => 'true',
    //             'message' => 'Alternate Extension is Available.',
    //             //'data'   => array()
    //         );
    //     } catch (Exception $e) {
    //         Log::log($e->getMessage());
    //     } catch (InvalidArgumentException $e) {
    //         Log::log($e->getMessage());
    //     }
    // }
    public function checkAltExtension($request)
    {
        Log::info('Request received in checkAltExtension', [
            'alt_extension' => $request->input('alt_extension')        ]);
        
        try {
            $clientId = $request->auth->parent_id;
            $response = null;
            if ($request->input('alt_extension')) {
                $altExtension = trim((string) $request->input('alt_extension'));
    
                $sql = "SELECT id FROM users WHERE alt_extension = :alt_extension AND is_deleted = 0 LIMIT 1";
                $record = DB::connection('master')->selectOne($sql, ['alt_extension' => $altExtension]);
    
                Log::info('Check Alt Extension Query', [
                    'alt_extension' => $altExtension,
                    'record' => $record
                ]);
    
                if ($record) {
                    return [
                        'success' => 'false',
                        'message' => 'Alternate Extension Already Exists.'
                    ];
                }
            }
    
            return [
                'success' => 'true',
                'message' => 'Alternate Extension is Available.'
            ];
    
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return ['success' => 'false', 'message' => 'Server error occurred.'];
        }
    }
    
    
    public function updateEmail($request)
    {
        try {
            $clientId = $request->auth->parent_id;
            if ($request->has('email')) {
                $data['email'] = $request->input('email');
                $data['id'] = $request->input('user_id');

                $sql = "SELECT * FROM " . $this->table . "  WHERE email = :email";
                $record = DB::connection('master')->selectOne($sql, array('email' => $data['email']));
                $serverData = (array)$record;
                if (empty($serverData)) {
                    $updateData = "UPDATE users SET email= :email WHERE id= :id ";
                    $record = DB::connection('master')->select($updateData, $data);
                    $response = (array)$record;
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Email Already Exists.',
                        //'data'   => $response
                    );
                }
            }
            if (!empty($response)) {
                return array(
                    'success' => 'false',
                    'message' => 'Something Went wrong.',
                    //'data'   => $response
                );
            }

            return array(
                'success' => 'true',
                'message' => 'Email Change Successfully.',
                //'data'   => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    // Add new extension
    public function newExtensionSave(Request $request)
    {
        Log::info('reached', [$request->all()]);

        $intGeneratedAltExtension = '';
        $intGeneratedAppExtension = '';


        // check duplicate extension // or alt_extension= :extension or alt_extension= :oldExtension
        $sqlExtensionCheck = "SELECT count(1) as rowCount FROM " . $this->table . "  WHERE parent_id= :parent_id AND is_deleted=0 AND (extension = :extension or extension= :oldExtension ) ";
        $recordCheck = DB::connection('master')->selectOne($sqlExtensionCheck, array('parent_id' => $request->auth->parent_id, 'oldExtension' => $request->extension, 'extension' => $request->auth->parent_id . $request->extension));
        if ($recordCheck->rowCount > 0) {
            throw new RenderableException(
                "",
                [
                    "extension" => [
                        "Extention " . $request->extension . " already assigned"
                    ]
                ],
                400
            );
        }

        //prepare alt_extension
        $strSql = "SELECT GROUP_CONCAT(extension) as extensions, GROUP_CONCAT(alt_extension) as alt_extensions, GROUP_CONCAT(app_extension) as app_extensions FROM users WHERE parent_id=:parent_id AND is_deleted=0";
        $arrExistingExtensionsResponse = DB::select($strSql, ['parent_id' => $request->auth->parent_id]);
        $arrExistingPrimaryExtensions = explode(",", $arrExistingExtensionsResponse[0]->extensions);
        $arrExistingAltExtensions = explode(",", $arrExistingExtensionsResponse[0]->alt_extensions);
        $arrExistingAppExtensions = explode(",", $arrExistingExtensionsResponse[0]->app_extensions);

        $arrExistingExtensions = array_merge($arrExistingPrimaryExtensions, $arrExistingAltExtensions, $arrExistingAppExtensions);
        $intGeneratedAltExtension = $this->generateExtension($arrExistingExtensions);

        $intGeneratedAppExtension = $this->generateExtension($arrExistingExtensions);


        $updateGroup = true;
        $dialPadArray = array(
            "a" => '2',
            "b" => '2',
            "c" => '2',
            "d" => '3',
            "e" => '3',
            "f" => '3',
            "g" => '4',
            "h" => '4',
            "i" => '4',
            "j" => '5',
            "k" => '5',
            "l" => '5',
            "m" => '6',
            "n" => '6',
            "o" => '6',
            "p" => '7',
            "q" => '7',
            "r" => '7',
            "s" => '7',
            "t" => '8',
            "u" => '8',
            "v" => '8',
            "w" => '0',
            "x" => '9',
            "y" => '9',
            "z" => '9'
        );
        $first_names = strtolower($request->input('first_name'));
        $result_fname = substr($first_names, 0, 3);
        $array_fname = str_split($result_fname);
        foreach ($array_fname as $char) {
            if (array_key_exists($char, $dialPadArray)) {
                $dialpad[] = $dialPadArray[$char];
            }
        }
        $dialpadNumber = implode(',', $dialpad);
        $finaldialPad = str_replace(',', '', $dialpadNumber);

        //last name for dialpad_lastname

        $data['last_name'] = $request->input('last_name');
        $last_names = strtolower($data['last_name']);
        $result = substr($last_names, 0, 3);
        $array = str_split($result);
        foreach ($array as $char) {
            if (array_key_exists($char, $dialPadArray)) {
                $dialpadLastName[] = $dialPadArray[$char];
            }
        }

        $dialpadNumberLatName = implode(',', $dialpadLastName);
        $finaldialPadLastName = str_replace(',', '', $dialpadNumberLatName);





        $data['dialpad'] = $finaldialPad;
        $data['dialpad_lastname'] = $finaldialPadLastName;
        $data['role'] = 2;
        $data['extension'] = $request->auth->parent_id . $request->input('extension');

        $data['alt_extension'] = $request->auth->parent_id . $intGeneratedAltExtension;
        $data['first_name'] = $request->input('first_name');
        $data['last_name'] = $request->input('last_name');
        $data['mobile'] = $request->input('mobile');
        $data['country_code'] = $request->input('country_code');
        $data['follow_me'] = $request->input('follow_me');
        $data['call_forward'] = $request->input('call_forward');
        $data['voicemail'] = $request->input('voicemail');
        $data['vm_pin'] = $request->input('vm_pin');
        $data['voicemail_send_to_email'] = $request->input('voicemail_send_to_email');
        $data['twinning'] = $request->input('twinning');
        $data['asterisk_server_id'] = $request->input('asterisk_server_id');
        $data['email'] = $request->input('email');
        $data['parent_id'] = $request->auth->parent_id;
        $data['base_parent_id'] = $request->auth->parent_id;
        $data['timezone'] = $request->input('timezone');

        $data['cli_setting'] = $request->input('cli_setting');
        $data['cli'] = $request->input('cli');
        $data['cnam'] = $request->input('cnam');
        $data['password'] = $request->input('password');
        $data['extension_type'] = $request->input('extension_type');
        $data['sms_setting_id'] = $request->input('sms_setting_id');
        $data['receive_sms_on_email'] = $request->input('receive_sms_on_email');
        $data['receive_sms_on_mobile'] = $request->input('receive_sms_on_mobile');
        $data['ip_filtering'] = $request->input('ip_filtering');
        $data['enable_2fa'] = $request->input('enable_2fa');
        $data['voip_configuration_id'] = $request->input('voip_configuration_id');
        $data['app_status'] = $request->input('app_status');
        $data['app_extension'] = $request->auth->parent_id . $intGeneratedAppExtension;
        $data['allow_google_authenticator'] = $request->allow_google_authenticator;
        $data['two_factor_authentication'] = $request->two_factor_authentication;
        $data['allow_mobile_login'] = $request->allow_mobile_login;
        $data['easify_user_uuid'] = $request->input('easify_user_uuid');
        $data['user_type'] = $request->input('user_type');
        $data['owner_id'] = $request->input('owner_id');

        //generate affiliate links
        $unique_token = $this->generateCode();
        $affiliate_link = '/' . $data['base_parent_id'] . '/' . $data['extension'] . '/' . $unique_token;
        $data['affiliate_link'] = $affiliate_link;

        $user = User::createAndSave($data);


        $users_package['user_id'] = $user->id;
        // $users_package['client_package_id'] = $request->input('package_id');

        // $insertData = "UPDATE user_packages SET user_id= :user_id WHERE client_package_id=:client_package_id and user_id IS NULL LIMIT 1 ";
        // $record_ustext = DB::connection('mysql_' . $request->auth->parent_id)->select($insertData, $users_package);



        if (isset($request->group_id)) {
            if (count($request->group_id) > 0) {

                $extension = $request->auth->parent_id . $request->input('extension');

                $sql = "SELECT * FROM " . $this->table . "  WHERE extension = :extension";
                $record = DB::connection('master')->selectOne($sql, array('extension' => $extension));
                $serverData = (array)$record;

                if (strlen($extension) == 4) {
                    $extension = $request->auth->parent_id . $extension;
                }

                foreach ($request->input('group_id') as $value) {
                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id), (:alt_extension, :same_group_id), (:app_extension, :same_group_id1) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('is_deleted' => 0, 'extension' => $extension, 'group_id' => $value, 'same_group_id' => $value, 'alt_extension' => $serverData['alt_extension'], 'same_group_id1' => $value, 'app_extension' => $serverData['app_extension']));
                }
            } else {
                $serverData = $user->toArray();
            }
        }

        if ($user->id || $updateGroup == true) {

            /// save user_extension
            $sql_uext = "SELECT count(*) as row_count FROM user_extensions WHERE username = :username";
            $record_ustext = DB::connection('master')->selectOne($sql_uext, array('username' => $extension));
            $response_ust = (array)$record_ustext;
            if ($response_ust['row_count'] == 0) {
                $dt['name'] = $extension;
                $dt['username'] = $extension;
                $dt['secret'] = $request->input('password');
                $dt['context'] = 'user-extensions-phones'; //'default';
                $dt['host'] = 'dynamic';
                $dt['nat'] = 'force_rport,comedia';
                $dt['qualify'] = 'no';
                $dt['type'] = 'friend';
                $dt['fullname'] = $request->input('first_name') . ' ' . $request->input('last_name');
                $insertData = "INSERT INTO user_extensions SET fullname= :fullname, context= :context, name= :name, type= :type , qualify= :qualify , nat= :nat , host= :host, secret= :secret,username= :username";
                $record_ustextSav = DB::connection('master')->select($insertData, $dt);

                $data['alt_extension'] = $request->auth->parent_id . $intGeneratedAltExtension;


                if ($extension != $data['alt_extension']) {

                    $dt['name'] = $data['alt_extension'];
                    $dt['username'] = $data['alt_extension'];
                    $dt['secret'] = $request->input('password');
                    $dt['context'] = 'user-extensions-phones'; //'default';
                    $dt['host'] = 'dynamic';
                    $dt['nat'] = 'force_rport,comedia';
                    $dt['qualify'] = 'no';
                    $dt['type'] = 'friend';
                    $dt['fullname'] = $request->input('first_name') . ' ' . $request->input('last_name');

                    $dt['rtptimeout'] = '7200';
                    $dt['rtpholdtimeout'] = '7200';
                    $dt['sendrpid'] = 'yes';
                    $dt['subscribemwi'] = 'yes';
                    $dt['t38pt_udptl'] = 'no';
                    $dt['transport'] = 'UDP,WS,WSS';
                    $dt['trustrpid'] = 'no';
                    $dt['useclientcode'] = 'no';
                    $dt['usereqphone'] = 'no';
                    $dt['videosupport'] = 'no';
                    $dt['icesupport'] = 'yes';
                    $dt['force_avp'] = 'yes';
                    $dt['dtlsenable'] = 'yes';
                    $dt['dtlsverify'] = 'fingerprint';
                    $dt['dtlscertfile'] = '/etc/asterisk/asterisk.pem';
                    $dt['dtlssetup'] = 'actpass';
                    $dt['rtcp_mux'] = 'yes';
                    $dt['avpf'] = 'yes';
                    $dt['webrtc'] = 'yes';

                    $insertData = "INSERT INTO user_extensions SET fullname= :fullname, context= :context, name= :name, type= :type , qualify= :qualify , nat= :nat , host= :host, secret= :secret,username= :username, rtptimeout= :rtptimeout, rtpholdtimeout= :rtpholdtimeout,sendrpid= :sendrpid,subscribemwi= :subscribemwi,t38pt_udptl= :t38pt_udptl,transport= :transport,trustrpid= :trustrpid,useclientcode= :useclientcode,usereqphone= :usereqphone,videosupport= :videosupport,icesupport= :icesupport,force_avp =:force_avp,dtlsenable=:dtlsenable,dtlsverify=:dtlsverify,dtlscertfile= :dtlscertfile,dtlssetup= :dtlssetup,rtcp_mux= :rtcp_mux,avpf= :avpf,
                webrtc= :webrtc";
                    $record_ustextSav = DB::connection('master')->select($insertData, $dt);
                }

                $data['app_extension'] = $request->auth->parent_id . $intGeneratedAppExtension;


                if ($extension != $data['app_extension']) {

                    $dt['name'] = $data['app_extension'];
                    $dt['username'] = $data['app_extension'];
                    $dt['secret'] = $request->input('password');
                    $dt['context'] = 'user-extensions-phones'; //'default';
                    $dt['host'] = 'dynamic';
                    $dt['nat'] = 'force_rport,comedia';
                    $dt['qualify'] = 'no';
                    $dt['type'] = 'friend';
                    $dt['fullname'] = $request->input('first_name') . ' ' . $request->input('last_name');

                    $dt['rtptimeout'] = '7200';
                    $dt['rtpholdtimeout'] = '7200';
                    $dt['sendrpid'] = 'yes';
                    $dt['subscribemwi'] = 'yes';
                    $dt['t38pt_udptl'] = 'no';
                    $dt['transport'] = 'TLS,WS,WSS,TCP,UDP';
                    $dt['trustrpid'] = 'no';
                    $dt['useclientcode'] = 'no';
                    $dt['usereqphone'] = 'no';
                    $dt['videosupport'] = 'yes';
                    $dt['icesupport'] = 'yes';
                    $dt['force_avp'] = 'no';
                    $dt['dtlsenable'] = 'yes';
                    $dt['dtlsverify'] = 'fingerprint';
                    $dt['dtlscertfile'] = '/etc/asterisk/asterisk.pem';
                    $dt['dtlssetup'] = 'actpass';
                    $dt['rtcp_mux'] = 'no';
                    $dt['avpf'] = 'no';
                    $dt['webrtc'] = 'no';

                    $insertData = "INSERT INTO user_extensions SET fullname= :fullname, context= :context, name= :name, type= :type , qualify= :qualify , nat= :nat , host= :host, secret= :secret,username= :username, rtptimeout= :rtptimeout, rtpholdtimeout= :rtpholdtimeout,sendrpid= :sendrpid,subscribemwi= :subscribemwi,t38pt_udptl= :t38pt_udptl,transport= :transport,trustrpid= :trustrpid,useclientcode= :useclientcode,usereqphone= :usereqphone,videosupport= :videosupport,icesupport= :icesupport,force_avp =:force_avp,dtlsenable=:dtlsenable,dtlsverify=:dtlsverify,dtlscertfile= :dtlscertfile,dtlssetup= :dtlssetup,rtcp_mux= :rtcp_mux,avpf= :avpf,
                webrtc= :webrtc";
                    $record_ustextSav = DB::connection('master')->select($insertData, $dt);
                }
            } else {
                $dt['name'] = $extension;
                $dt['username'] = $extension;
                $dt['fullname'] = $request->input('first_name') . ' ' . $request->input('last_name');
                $insertData = "UPDATE user_extensions SET name= :name , fullname= :fullname WHERE username= :username ";
                $record_ustext = DB::connection('master')->select($insertData, $dt);
            }

            $notificationData = [
                "action" => "Extension added",
                "user" => $user
            ];
            dispatch(new ExtensionNotificationJob($request->auth->parent_id, $notificationData))->onConnection("database");

            return array(
                'success' => 'true',
                'message' => 'Extension added successfully.',
                'data' => $serverData
            );
        } else {
            return array(
                'success' => 'false',
                'message' => 'Nothing to update.'
            );
        }
    }

    // function getExtensionCount($request)
    // {
    //     try {
    //         //$parent_id = $request->auth->base_parent_id;
    //         $parent_id = $request->parentId;
    //         if (is_numeric($parent_id)) {
    //             $data['parent_id'] = $request->parentId;
    //             //$sql = "SELECT count(1) as rowCount FROM " . $this->table . "  WHERE parent_id = :parent_id and is_deleted = 0 ";
    //             $sql = "SELECT count(1) as rowCount
    //             FROM users
    //             LEFT JOIN user_extensions ON user_extensions.name = users.extension
    //             WHERE users.id IN (SELECT user_id FROM permissions WHERE client_id = :parent_id)
    //             AND users.is_deleted = 0";

    //             $record = DB::connection('master')->selectOne($sql, $data);
    //             $response = (array)$record;
    //             $userCount = $response['rowCount'];
    //             if (!empty($userCount)) {
    //                 return array(
    //                     'success' => 'true',
    //                     'message' => 'User count',
    //                     'data' => $userCount
    //                 );
    //             }
    //         } else {
    //             return array(
    //                 'success' => 'false',
    //                 'message' => 'Failed to get count',
    //                 'data' => ''
    //             );
    //         }
    //     } catch (Exception $e) {
    //         Log::log($e->getMessage());
    //     } catch (InvalidArgumentException $e) {
    //         Log::log($e->getMessage());
    //     }
    // }
    function getExtensionCount($request)
    {
        try {
            $parent_id = $request->parentId;

            if (is_numeric($parent_id)) {
                $status = 0;
                $isDeleted = 0;
                $userLevel = 9;

                $sql = "SELECT COUNT(1) as rowCount
                    FROM users
                    LEFT JOIN user_extensions ON user_extensions.name = users.extension
                    WHERE users.id IN (
                        SELECT user_id FROM permissions WHERE client_id = :parent_id
                    )
                    AND users.is_deleted = :is_deleted
                    AND users.status = :status
                    AND users.base_parent_id = :base_parent_id
                    AND users.user_level < :user_level";

                $bindings = [
                    'parent_id' => $parent_id,
                    'is_deleted' => $isDeleted,
                    'status' => $status,
                    'base_parent_id' => $parent_id,
                    'user_level' => $userLevel
                ];

                $record = DB::connection('master')->selectOne($sql, $bindings);
                $userCount = (array)$record;

                return [
                    'success' => 'true',
                    'message' => 'Extension count',
                    'data' => $userCount['rowCount'] ?? 0
                ];
            } else {
                return [
                    'success' => 'false',
                    'message' => 'Invalid parent ID',
                    'data' => 0
                ];
            }
        } catch (\Exception $e) {
            Log::error('getExtensionCount error: ' . $e->getMessage());
            return [
                'success' => 'false',
                'message' => 'Exception occurred',
                'data' => 0
            ];
        }
    }

    /**
     * Get all extensions of client
     * @param type $request
     * @return type
     */
    function getClientExtensions($request)
    {

        if ($request->has('api_key')) {
            $client = Client::where('api_key', $request->api_key)->get()->first();
            $parent_id = $client->id;
            $connection = 'mysql_' . $client->id;
        } else {
            $parent_id = $request->auth->parent_id;
        }



        try {
            if (is_numeric($parent_id)) {
                $data['parent_id'] = $parent_id;
                // $sql = "SELECT CONCAT(users.first_name, ' ', users.last_name) as name, extension,user_level,first_name,last_name FROM " . $this->table .
                //     " WHERE parent_id = :parent_id AND is_deleted = 0 ORDER BY name ASC";
                $sql = "SELECT CONCAT(users.first_name, ' ', users.last_name) as name, users.id,users.parent_id,users.extension, users.user_level, users.first_name
                FROM " . $this->table . "
                LEFT JOIN user_extensions ON user_extensions.name = users.extension
                WHERE users.id IN (SELECT user_id FROM permissions WHERE client_id = :parent_id)
                AND users.is_deleted = 0 and user_level < 11 and parent_id='" . $parent_id . "'
                ORDER BY name ASC";

                $record = DB::connection('master')->select($sql, $data);
                $response = (array)$record;
                return array(
                    'success' => 'true',
                    'message' => 'Cient extensions',
                    'data' => $response
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /**
     * @param $arrExistingExtensions
     * @return int|string
     */
    function generateExtension($arrExistingExtensions)
    {
        $intGeneratedExtension = '';
        $boolUniqueFound = true;

        while ($boolUniqueFound) {
            $intGeneratedExtension = mt_rand(1000, 9999);
            if (!in_array($intGeneratedExtension, $arrExistingExtensions)) {
                $boolUniqueFound = false;
            }
        }
        return $intGeneratedExtension;
    }

    public static function generateCode($length = 35)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}
