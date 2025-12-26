<?php

namespace App\Model;

use App\Jobs\ListAddedNotificationJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Model\Client\CampaignList;
use App\Model\Client\LeadTemp;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;


class Lists extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'list';
    protected $column_name = [
        'option_1',
        'option_2',
        'option_3',
        'option_4',
        'option_5',
        'option_6',
        'option_7',
        'option_8',
        'option_9',
        'option_10',
        'option_11',
        'option_12',
        'option_13',
        'option_14',
        'option_15',
        'option_16',
        'option_17',
        'option_18',
        'option_19',
        'option_20',
        'option_21',
        'option_22',
        'option_23',
        'option_24',
        'option_25',
        'option_26',
        'option_27',
        'option_28',
        'option_29',
        'option_30'
    ];

    /*
     * Fetch List
     * @param integer $id
     * @return array
     */

    // public function getListHeader($request)
    // {
    //     try {
    //         $data = array();
    //         $searchStr = array();
    //         if ($request->has('list_data') && is_array($request->input('list_data'))) {
    //             $data['list_id'] = $request->input('list_data');
    //         }
    //         if ($data['list_id'][0] == '0') {
    //             $list = implode(',', $data['list_id']);
    //             $list = "'" . implode("', '", $data['list_id']) . "'";
    //             $data['list_id'] = $list;
    //             $sql = "SELECT list_header.column_name,label.title FROM list_header inner join label on label.id = list_header.label_id  WHERE list_header.list_id NOT IN(" . $list . ") group by label.title";
    //         } else {
    //             $list = implode(',', $data['list_id']);
    //             $list = "'" . implode("', '", $data['list_id']) . "'";
    //             $data['list_id'] = $list;
    //             $sql = "SELECT list_header.column_name,label.title FROM list_header inner join label on label.id = list_header.label_id  WHERE list_header.list_id IN(" . $list . ") group by label.title";
    //         }
    //         $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
    //         $data = (array) $record;
    //         if (!empty($data)) {
    //             return array(
    //                 'success' => 'true',
    //                 'message' => 'List header detail.',
    //                 'data' => $data
    //             );
    //         } else {
    //             return array(
    //                 'success' => 'false',
    //                 'message' => 'List Header not created.',
    //                 'data' => array()
    //             );
    //         }
    //     } catch (Exception $e) {
    //         Log::log($e->getMessage());
    //     } catch (InvalidArgumentException $e) {
    //         Log::log($e->getMessage());
    //     }
    // }
    public function getListHeader($request)
    {
        try {
            $data = array();
    
            if ($request->has('list_data') && is_array($request->input('list_data'))) {
                $data['list_id'] = $request->input('list_data');
            }
    
            // Prepare list ids
            $list = "'" . implode("', '", $data['list_id']) . "'";
    
            if ($data['list_id'][0] == '0') {
                $sql = "
                    SELECT 
                        list_header.column_name,
                        list_header.is_search,
                        label.title
                    FROM list_header
                    INNER JOIN label ON label.id = list_header.label_id
                    WHERE list_header.list_id NOT IN ($list)
                    GROUP BY label.title, list_header.column_name, list_header.is_search
                ";
            } else {
                $sql = "
                    SELECT 
                        list_header.column_name,
                        list_header.is_search,
                        label.title
                    FROM list_header
                    INNER JOIN label ON label.id = list_header.label_id
                    WHERE list_header.list_id IN ($list)
                    GROUP BY label.title, list_header.column_name, list_header.is_search
                ";
            }
    
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array) $record;
    
            if (!empty($data)) {
                return [
                    'success' => 'true',
                    'message' => 'List header detail.',
                    'data'    => $data
                ];
            } else {
                return [
                    'success' => 'false',
                    'message' => 'List Header not created.',
                    'data'    => []
                ];
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::error($e->getMessage());
        }
    }
    
    // public function searchLeads($request)
    // {
    //     try {
    //         $data = array();

    //         if ($request->has('list_data') && is_array($request->input('list_data'))) {
    //             $data['list_id'] = $request->input('list_data');
    //         }
    //         if ($request->has('header_column') && $request->input('header_column')) {
    //             $data['header_column'] = $request->input('header_column');
    //         }

    //         if ($request->has('header_value') && $request->input('header_value')) {
    //             $data['header_value'] = $request->input('header_value');
    //         }

    //         $number = $request->input('header_value'); //'6473621646';


    //         if ($data['list_id'][0] == '0') {

    //             $list = implode(',', $data['list_id']);
    //             $list = "'" . implode("', '", $data['list_id']) . "'";
    //             $data['list_id'] = $list;


    //             $sql = "SELECT * FROM list_data WHERE list_id NOT IN(" . $list . ") and " . $request->input('header_column') . "='" . $number . "'";
    //         } else {
    //             $list = implode(',', $data['list_id']);
    //             $list = "'" . implode("', '", $data['list_id']) . "'";
    //             $data['list_id'] = $list;
    //             $sql = "SELECT * FROM list_data WHERE list_id IN(" . $list . ") and " . $request->input('header_column') . "='" . $number . "'";
    //         }
    //              // Apply pagination if start and limit are provided
    //     if ($request->has('start') && $request->has('limit')) {
    //         $start = (int) $request->input('start');
    //         $limit = (int) $request->input('limit');
    //         $sql .= " LIMIT $limit OFFSET $start";
    //     }
    //         $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
    //         $data = (array) $record;
    //         if (!empty($data)) {
    //             return array(
    //                 'success' => 'true',
    //                 'message' => 'Lead detail.',
    //                 'data' => $data
    //             );
    //         }
    //         return array(
    //             'success' => 'false',
    //             'message' => 'No Leads Found.',
    //             'data' => array()
    //         );
    //     } catch (Exception $e) {
    //         Log::log($e->getMessage());
    //     } catch (InvalidArgumentException $e) {
    //         Log::log($e->getMessage());
    //     }
    // }
    public function searchLeads($request)
{
    try {
        $data = array();

        if ($request->has('list_data') && is_array($request->input('list_data'))) {
            $data['list_id'] = $request->input('list_data');
        }
        if ($request->has('header_column') && $request->input('header_column')) {
            $data['header_column'] = $request->input('header_column');
        }

        if ($request->has('header_value') && $request->input('header_value')) {
            $data['header_value'] = $request->input('header_value');
        }

        $number = $request->input('header_value');

        // Prepare list condition
        if ($data['list_id'][0] == '0') {
            $list = "'" . implode("','", $data['list_id']) . "'";
            $data['list_id'] = $list;

            $baseSql = "FROM list_data WHERE list_id NOT IN($list) AND {$request->input('header_column')} = '$number'";
        } else {
            $list = "'" . implode("','", $data['list_id']) . "'";
            $data['list_id'] = $list;

            $baseSql = "FROM list_data WHERE list_id IN($list) AND {$request->input('header_column')} = '$number'";
        }

        // Get total rows (without pagination)
        $countSql = "SELECT COUNT(*) as total " . $baseSql;
        $countResult = DB::connection('mysql_' . $request->auth->parent_id)->select($countSql);
        $totalRows = $countResult[0]->total ?? 0;

        // Fetch paginated data
        $sql = "SELECT * " . $baseSql;
        if ($request->has('start') && $request->has('limit')) {
            $start = (int) $request->input('start');
            $limit = (int) $request->input('limit');
            $sql .= " LIMIT $limit OFFSET $start";
        }

        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);

        if (!empty($record)) {
            return array(
                'success' => 'true',
                'message' => 'Lead detail.',
                'total_rows'   => $totalRows, // ✅ Added total count
                'data'    => $record
            );
        }

        return array(
            'success' => 'false',
            'message' => 'No Leads Found.',
            'total_rows'   => 0,
            'data'    => array()
        );
    } catch (Exception $e) {
        Log::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
    }
}


//      public function getList($request)
// {
//     $titleSearch = null;
//     if ($request->has('title') && !empty(trim($request->input('title')))) {
//         $titleSearch = trim($request->input('title'));
//     }

//     if (
//         $request->has('list_id') && is_numeric($request->input('list_id')) &&
//         $request->has('campaign_id') && is_numeric($request->input('campaign_id'))
//     ) {
//         $sql = "SELECT
//                     c.title as campaign, l.title as list, cl.campaign_id, cl.list_id, cl.updated_at, l.is_active
//                 FROM
//                     campaign_list as cl
//                 LEFT JOIN list as l ON l.id = cl.list_id
//                 LEFT JOIN campaign as c ON c.id = cl.campaign_id
//                 WHERE cl.is_deleted = :is_deleted 
//                   AND list_id = :list_id 
//                   AND campaign_id = :campaign_id";

//         // Search by title if provided
//         $params = [
//             'is_deleted' => 0,
//             'list_id'    => $request->input('list_id'),
//             'campaign_id'=> $request->input('campaign_id')
//         ];
//         if ($titleSearch) {
//             $sql .= " AND l.title LIKE :title";
//             $params['title'] = '%' . $titleSearch . '%';
//         }

//         $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, $params);
//         $data = (array) $record;

//         $sql = "SELECT * FROM list_header WHERE list_id = :list_id AND is_deleted = :is_deleted";
//         $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, [
//             'is_deleted' => 0,
//             'list_id' => $request->input('list_id')
//         ]);
//         $listHeader = (array) $record;
//         $data['list_header'] = $listHeader;
//         $totalRows = count($listHeader);

//         if ($request->has('start') && $request->has('limit')) {
//             $start = (int)$request->input('start');
//             $limit = (int)$request->input('limit');
//             $length = $limit;
//             $listHeader = array_slice($listHeader, $start, $length);
//             $data['list_header'] = $listHeader;
//         }
//     } else {
//         $sql = "SELECT
//                     c.title as campaign, l.title as list, cl.campaign_id, cl.list_id, cl.updated_at, l.is_active
//                 FROM
//                     campaign_list as cl
//                 LEFT JOIN list as l ON l.id = cl.list_id
//                 LEFT JOIN campaign as c ON c.id = cl.campaign_id
//                 WHERE cl.is_deleted = :is_deleted";

//         $params = ['is_deleted' => 0];
//         if ($titleSearch) {
//             $sql .= " AND l.title LIKE :title";
//             $params['title'] = '%' . $titleSearch . '%';
//         }

//         $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $params);
//         $data = (array) $record;

//         foreach ($data as $key => $id) {
//             $list['list_id'] = $id->list_id;
//             $sql_count_list = "SELECT count(1) as rowCountList FROM list_data WHERE list_id = :list_id";
//             $record_count_list = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_count_list, $list);
//             $id->rowListData = $record_count_list->rowCountList;
//         }
//         $totalRows = count($data);
//     }

//     if ($request->has('start') && $request->has('limit')) {
//         $start = (int)$request->input('start');
//         $limit = (int)$request->input('limit');
//         $length = $limit;
//         $data = array_slice($data, $start, $length);
//     }

//     if (!empty($data)) {
//         return [
//             'success' => 'true',
//             'message' => 'Lists detail.',
//             'total_rows' => $totalRows,
//             'data' => $data
//         ];
//     }
//     return [
//         'success' => 'false',
//         'message' => 'Lists not created.',
//         'data' => []
//     ];
// }

public function getList($request)
{
    $titleSearch = null;
    if ($request->has('title') && !empty(trim($request->input('title')))) {
        $titleSearch = trim($request->input('title'));
    }

    $connection = 'mysql_' . $request->auth->parent_id;

    // --- Case 1: list_id & campaign_id provided ---
    if (
        $request->has('list_id') && is_numeric($request->input('list_id')) &&
        $request->has('campaign_id') && is_numeric($request->input('campaign_id'))
    ) {
        $sql = "SELECT
                    c.title AS campaign, 
                    l.title AS list, 
                    cl.campaign_id, 
                    cl.list_id, 
                    cl.updated_at, 
                    l.is_active,
                    l.lead_count,
                      l.is_dialing,    -- ✅ Added this line
                      l.created_at
                FROM
                    campaign_list AS cl
                LEFT JOIN list AS l ON l.id = cl.list_id
                LEFT JOIN campaign AS c ON c.id = cl.campaign_id
                WHERE cl.is_deleted = :is_deleted 
                  AND list_id = :list_id 
                  AND campaign_id = :campaign_id";

        $params = [
            'is_deleted' => 0,
            'list_id'    => $request->input('list_id'),
            'campaign_id'=> $request->input('campaign_id')
        ];

        if ($titleSearch) {
            $sql .= " AND l.title LIKE :title";
            $params['title'] = '%' . $titleSearch . '%';
        }

        $record = DB::connection($connection)->selectOne($sql, $params);
        $data = (array) $record;

        $sql = "SELECT * FROM list_header WHERE list_id = :list_id AND is_deleted = :is_deleted";
        $record = DB::connection($connection)->select($sql, [
            'is_deleted' => 0,
            'list_id' => $request->input('list_id')
        ]);

        $listHeader = (array) $record;
        $data['list_header'] = $listHeader;
        $totalRows = count($listHeader);

        if ($request->has('start') && $request->has('limit')) {
            $start = (int)$request->input('start');
            $limit = (int)$request->input('limit');
            $data['list_header'] = array_slice($listHeader, $start, $limit);
        }

    } else {
        // --- Case 2: all lists ---
        $sql = "SELECT
                    c.title AS campaign, 
                    l.title AS list, 
                    cl.campaign_id, 
                    cl.list_id, 
                    cl.updated_at, 
                    l.is_active,
                    l.lead_count,
                      l.is_dialing,    -- ✅ Added this line
                      l.created_at
                FROM
                    campaign_list AS cl
                LEFT JOIN list AS l ON l.id = cl.list_id
                LEFT JOIN campaign AS c ON c.id = cl.campaign_id
                WHERE cl.is_deleted = :is_deleted";

        $params = ['is_deleted' => 0];

        if ($titleSearch) {
            $sql .= " AND l.title LIKE :title";
            $params['title'] = '%' . $titleSearch . '%';
        }

        $data = DB::connection($connection)->select($sql, $params);

        foreach ($data as $id) {
            $list_id = $id->list_id;

            // ✅ If lead_count is not null, use it directly
            if (!is_null($id->lead_count)) {
                $id->rowListData = $id->lead_count;
            } else {
                // Otherwise, calculate from list_data
                $sql_count_list = "SELECT COUNT(1) AS rowCountList 
                                   FROM list_data 
                                   WHERE list_id = :list_id";

                $record_count_list = DB::connection($connection)
                    ->selectOne($sql_count_list, ['list_id' => $list_id]);

                $rowCountList = $record_count_list->rowCountList ?? 0;
                $id->rowListData = $rowCountList;

                // ✅ Update lead_count in list table
                DB::connection($connection)
                    ->table('list')
                    ->where('id', $list_id)
                    ->update(['lead_count' => $rowCountList]);
            }
        }

        $totalRows = count($data);
    }

    // --- Pagination ---
    if ($request->has('start') && $request->has('limit')) {
        $start = (int)$request->input('start');
        $limit = (int)$request->input('limit');
        $data = array_slice($data, $start, $limit);
    }

    // --- Response ---
    if (!empty($data)) {
        return [
            'success' => true,
            'message' => 'Lists detail.',
            'total_rows' => $totalRows,
            'data' => $data
        ];
    }

    return [
        'success' => false,
        'message' => 'Lists not created.',
        'data' => []
    ];
}
public function getListwithoutCampaign($request)
{
    $titleSearch = null;
    if ($request->has('title') && !empty(trim($request->input('title')))) {
        $titleSearch = trim($request->input('title'));
    }

    $connection = 'mysql_' . $request->auth->parent_id;

    /* ---------------- Single List ---------------- */
    if ($request->has('list_id') && is_numeric($request->input('list_id'))) {

        $sql = "SELECT 
                    l.id,
                    l.title AS l_title,
                    l.is_active,
                    l.is_dialing,
                    l.lead_count,
                    l.updated_at
                FROM list l
                WHERE l.id = :list_id
                  AND EXISTS (
                        SELECT 1
                        FROM campaign_list cl
                        WHERE cl.list_id = l.id
                          AND cl.is_deleted = 0
                  )";

        $params = [
            'list_id' => $request->input('list_id')
        ];

        if ($titleSearch) {
            $sql .= " AND l.title LIKE :title";
            $params['title'] = '%' . $titleSearch . '%';
        }

        $record = DB::connection($connection)->selectOne($sql, $params);

        if (!$record) {
            return [
                'success' => false,
                'message' => 'List not found.',
                'data' => []
            ];
        }

        $data = (array) $record;

        /* -------- List Header -------- */
        $listHeader = DB::connection($connection)->select(
            "SELECT *
             FROM list_header
             WHERE list_id = :list_id
               AND is_deleted = 0",
            ['list_id' => $request->input('list_id')]
        );

        $totalRows = count($listHeader);

        if ($request->has('start') && $request->has('limit')) {
            $listHeader = array_slice(
                $listHeader,
                (int) $request->input('start'),
                (int) $request->input('limit')
            );
        }

        $data['list_header'] = $listHeader;
    }
    /* ---------------- All Lists ---------------- */
    else {

        $sql = "SELECT
                    l.id,
                    l.title AS l_title,
                    l.is_active,
                    l.is_dialing,
                    l.lead_count,
                    l.updated_at
                FROM list l
                WHERE EXISTS (
                    SELECT 1
                    FROM campaign_list cl
                    WHERE cl.list_id = l.id
                      AND cl.is_deleted = 0
                )";

        $params = [];

        if ($titleSearch) {
            $sql .= " AND l.title LIKE :title";
            $params['title'] = '%' . $titleSearch . '%';
        }

        $data = DB::connection($connection)->select($sql, $params);

        foreach ($data as $list) {

            if (!is_null($list->lead_count)) {
                $list->rowListData = $list->lead_count;
            } else {
                $count = DB::connection($connection)->selectOne(
                    "SELECT COUNT(1) AS total
                     FROM list_data
                     WHERE list_id = :list_id",
                    ['list_id' => $list->id]
                );

                $list->rowListData = $count->total ?? 0;

                DB::connection($connection)
                    ->table('list')
                    ->where('id', $list->id)
                    ->update(['lead_count' => $list->rowListData]);
            }
        }

        $totalRows = count($data);
    }

    /* ---------------- Pagination ---------------- */
    if ($request->has('start') && $request->has('limit')) {
        $data = array_slice(
            $data,
            (int) $request->input('start'),
            (int) $request->input('limit')
        );
    }

    return [
        'success'     => true,
        'message'     => 'List details.',
        'total_rows' => $totalRows,
        'data'        => $data
    ];
}




    /*
     * Edit List
     * @param object $request
     * @return array
     */

public function editList($request)
{
    // Validate required inputs
    if (! $request->has('list_id') || ! is_numeric($request->input('list_id'))) {
        return ['success' => 'false', 'message' => 'Invalid or missing list_id'];
    }

    if (! $request->has('campaign_id') || ! is_numeric($request->input('campaign_id'))) {
        return ['success' => 'false', 'message' => 'Invalid or missing campaign_id'];
    }

    $parentConn = 'mysql_' . $request->auth->parent_id;
    $listId     = (int) $request->input('list_id');
    $campaignId = (int) $request->input('campaign_id');

    try {

        /**
         * 1️⃣ CHECK LIST EXISTS IN campaign_list (ANY campaign)
         *    (important fix)
         */
        $existing = DB::connection($parentConn)->selectOne(
            "SELECT * 
             FROM campaign_list 
             WHERE list_id = :list_id 
               AND is_deleted = 0
             LIMIT 1",
            ['list_id' => $listId]
        );

        if (! $existing) {
            return [
                'success' => 'false',
                'message' => 'List is not assigned to any campaign.'
            ];
        }

        DB::connection($parentConn)->beginTransaction();

        /**
         * 2️⃣ UPDATE LIST TITLE
         */
        if ($request->has('title') && trim($request->input('title')) !== '') {
            DB::connection($parentConn)->update(
                "UPDATE `list` SET title = :title WHERE id = :id",
                [
                    'title' => $request->input('title'),
                    'id'    => $listId
                ]
            );
        }

        /**
         * 3️⃣ UPDATE campaign_list (MOVE / STATUS / DELETE)
         */
        $updateClauses  = [];
        $updateBindings = [];

        if ($request->has('new_campaign_id') && is_numeric($request->input('new_campaign_id'))) {
            $updateClauses[] = "campaign_id = :new_campaign_id";
            $updateBindings['new_campaign_id'] = (int) $request->input('new_campaign_id');
        }

        if ($request->has('status') && is_numeric($request->input('status'))) {
            $updateClauses[] = "status = :status";
            $updateBindings['status'] = (int) $request->input('status');
        }

        $isDeleted = false;
        if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
            $updateClauses[] = "is_deleted = :is_deleted";
            $updateBindings['is_deleted'] = (int) $request->input('is_deleted');
            $isDeleted = ((int)$request->input('is_deleted') === 1);
        }

        if (! empty($updateClauses)) {
            $updateBindings['list_id'] = $listId;

            DB::connection($parentConn)->update(
                "UPDATE campaign_list 
                 SET " . implode(', ', $updateClauses) . "
                 WHERE list_id = :list_id",
                $updateBindings
            );
        }

        /**
         * 4️⃣ UPDATE list_header FLAGS
         */
        if (is_array($request->input('list_header'))) {
            foreach ($request->input('list_header') as $row) {
                if (! empty($row['id']) && is_numeric($row['id'])) {
                    DB::connection($parentConn)->update(
                        "UPDATE list_header 
                         SET is_search = :is_search,
                             is_dialing = :is_dialing,
                             is_visible = :is_visible,
                             is_editable = :is_editable,
                             label_id = :label_id
                         WHERE id = :id",
                        [
                            'id'          => (int)$row['id'],
                            'is_search'   => (int)($row['is_search'] ?? 0),
                            'is_dialing'  => (int)($row['is_dialing'] ?? 0),
                            'is_visible'  => (int)($row['is_visible'] ?? 0),
                            'is_editable' => (int)($row['is_editable'] ?? 0),
                            'label_id'    => isset($row['label_id']) ? (int)$row['label_id'] : null
                        ]
                    );
                }
            }
        }

        /**
         * 5️⃣ UPDATE list TABLE FLAGS
         */
        Lists::on($parentConn)->where('id', $listId)->update([
            'is_active'  => 1,
            'is_dialing' => $request->input('is_dialing', 0)
        ]);

        /**
         * 6️⃣ DUPLICATE REMOVAL
         */
        if ((int)$request->input('duplicate_check') === 1) {
            $dialCol = DB::connection($parentConn)->selectOne(
                "SELECT column_name 
                 FROM list_header 
                 WHERE list_id = :list_id AND is_dialing = 1",
                ['list_id' => $listId]
            );

            if (! empty($dialCol->column_name)) {
                $col = $dialCol->column_name;

                DB::connection($parentConn)->statement(
                    "DELETE ld FROM list_data ld
                     JOIN (
                        SELECT MIN(id) keep_id, `$col`
                        FROM list_data
                        WHERE list_id = :list_id
                        GROUP BY `$col`
                     ) t ON ld.`$col` = t.`$col`
                     AND ld.id <> t.keep_id
                     AND ld.list_id = :list_id",
                    ['list_id' => $listId]
                );
            }
        }

        DB::connection($parentConn)->commit();

        return [
            'success' => 'true',
            'message' => 'List updated successfully.'
        ];

    } catch (\Throwable $e) {

        DB::connection($parentConn)->rollBack();

        Log::error('editList.error', [
            'error' => $e->getMessage(),
            'list_id' => $listId,
            'campaign_id' => $campaignId
        ]);

        return [
            'success' => 'false',
            'message' => $e->getMessage()
        ];
    }
}


public function editListold($request)
{
    // Validate required inputs early
    if (! $request->has('list_id') || ! is_numeric($request->input('list_id'))) {
        return ['success' => 'false', 'message' => 'Invalid or missing list_id'];
    }
    if (! $request->has('campaign_id') || ! is_numeric($request->input('campaign_id'))) {
        return ['success' => 'false', 'message' => 'Invalid or missing campaign_id'];
    }

    $parentConn = 'mysql_' . $request->auth->parent_id;
    $listId = (int) $request->input('list_id');
    $campaignId = (int) $request->input('campaign_id');

    try {
        // 1) Ensure that the campaign_list entry exists for given list_id + campaign_id
        $checkCampaignList = DB::connection($parentConn)->selectOne(
            "SELECT COUNT(1) AS total FROM campaign_list WHERE list_id = :list_id AND campaign_id = :campaign_id",
            ['list_id' => $listId, 'campaign_id' => $campaignId]
        );

        if (! $checkCampaignList || (int)$checkCampaignList->total === 0) {
            return [
                'success' => 'false',
                'message' => 'The provided list_id is not assigned to this campaign_id.'
            ];
        }

        // Start transaction on the specific connection
        DB::connection($parentConn)->beginTransaction();

        $saveRecord = true;
        $updateClauses = [];
        $updateBindings = [];

        // 2) Update list title (if provided)
        if ($request->has('title') && trim($request->input('title')) !== '') {
            DB::connection($parentConn)->update(
                "UPDATE `list` SET `title` = :title WHERE `id` = :id",
                ['title' => $request->input('title'), 'id' => $listId]
            );
        }

        // 3) Prepare campaign_list update values (if any)
        if ($request->has('new_campaign_id') && is_numeric($request->input('new_campaign_id'))) {
            $updateClauses[] = "campaign_id = :new_campaign_id";
            $updateBindings['new_campaign_id'] = (int) $request->input('new_campaign_id');
        }
        if ($request->has('status') && is_numeric($request->input('status'))) {
            $updateClauses[] = "status = :status";
            $updateBindings['status'] = (int) $request->input('status');
        }

        $isDeletedFlag = false;
        if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
            $updateClauses[] = "is_deleted = :is_deleted";
            $updateBindings['is_deleted'] = (int) $request->input('is_deleted');
            if ((int)$request->input('is_deleted') === 1) {
                $isDeletedFlag = true;
            }
        }

        // If there are campaign_list updates OR is_deleted true, process them
        if (! empty($updateClauses)) {
            // Always include the where parameters for update
            $updateBindings['list_id'] = $listId;
            $updateBindings['campaign_id'] = $campaignId;

            if ($isDeletedFlag) {
                // ----- Deletion flow -----
                // 1) Delete from campaign_list for this list_id (if present)
                $recordCampaignList = DB::connection($parentConn)->selectOne(
                    "SELECT COUNT(1) AS rowCountListCampaign FROM campaign_list WHERE list_id = :list_id",
                    ['list_id' => $listId]
                );
                if ($recordCampaignList && (int)$recordCampaignList->rowCountListCampaign > 0) {
                    // DB::connection($parentConn)->delete(
                    //     "DELETE FROM campaign_list WHERE list_id = :list_id",
                    //     ['list_id' => $listId]
                    // );
                    DB::connection($parentConn)->update(
                        "UPDATE campaign_list 
                        SET is_deleted = 1 
                        WHERE list_id = :list_id AND campaign_id = :campaign_id",
                        [
                            'list_id' => $listId,
                            'campaign_id' => $campaignId
                        ]
                    );

                }

                // 2) Delete list_data rows for this list_id (if any)
                $recordListData = DB::connection($parentConn)->selectOne(
                    "SELECT COUNT(1) AS rowCountListData FROM list_data WHERE list_id = :list_id",
                    ['list_id' => $listId]
                );
                if ($recordListData && (int)$recordListData->rowCountListData > 0) {
                    DB::connection($parentConn)->delete(
                        "DELETE FROM list_data WHERE list_id = :list_id",
                        ['list_id' => $listId]
                    );
                }

                // 3) Delete lead_report rows for this list_id (if any)
                $recordLeadReport = DB::connection($parentConn)->selectOne(
                    "SELECT COUNT(1) AS rowCountListLeadReport FROM lead_report WHERE list_id = :list_id",
                    ['list_id' => $listId]
                );
                if ($recordLeadReport && (int)$recordLeadReport->rowCountListLeadReport > 0) {
                    DB::connection($parentConn)->delete(
                        "DELETE FROM lead_report WHERE list_id = :list_id",
                        ['list_id' => $listId]
                    );
                }

                // 4) Delete lead_temp rows for this list_id (if any)
                $recordLeadTemp = DB::connection($parentConn)->selectOne(
                    "SELECT COUNT(1) AS rowCountListLeadTemp FROM lead_temp WHERE list_id = :list_id",
                    ['list_id' => $listId]
                );
                if ($recordLeadTemp && (int)$recordLeadTemp->rowCountListLeadTemp > 0) {
                    DB::connection($parentConn)->delete(
                        "DELETE FROM lead_temp WHERE list_id = :list_id",
                        ['list_id' => $listId]
                    );
                }

                // 5) Delete list_header rows for this list_id (if any)
                $recordListHeader = DB::connection($parentConn)->selectOne(
                    "SELECT COUNT(1) AS rowCountListHeader FROM list_header WHERE list_id = :list_id",
                    ['list_id' => $listId]
                );

                // If the list_header record does not exist at all (unexpected), return a clear message
                if ($recordListHeader === null) {
                    DB::connection($parentConn)->rollBack();
                    return [
                        'success' => 'false',
                        'message' => 'No records found in list_header for this list (unexpected).'
                    ];
                }

                if ((int)$recordListHeader->rowCountListHeader > 0) {
                    DB::connection($parentConn)->delete(
                        "DELETE FROM list_header WHERE list_id = :list_id",
                        ['list_id' => $listId]
                    );
                }

                // 6) Load list model, validate active status, then delete list row
                $listModel = Lists::on($parentConn)->find($listId);
                if (! $listModel) {
                    DB::connection($parentConn)->rollBack();
                    return [
                        'success' => 'false',
                        'message' => 'List not found in lists table.'
                    ];
                }

                // Use strict comparison for is_active
                if ((int)$listModel->is_active === 0) {
                    DB::connection($parentConn)->rollBack();
                    return [
                        'success' => 'false',
                        'message' => 'This List is not active in lists table.'
                    ];
                }

                $notificationData = [
                    "action" => "List deleted",
                    "listId" => $listId,
                    "listName" => $listModel->title
                ];

                // delete the list record (soft or hard depending on model)
                $listModel->delete();

                // dispatch notification job (same connection as before)
                dispatch(new ListAddedNotificationJob($request->auth->parent_id, $campaignId, $notificationData))
                    ->onConnection("database");
            } else {
                // ----- Update campaign_list (non-delete) -----
                // Use commas to separate SET clauses (not AND)
                $updateSql = "UPDATE campaign_list SET " . implode(", ", $updateClauses) .
                             " WHERE list_id = :list_id AND campaign_id = :campaign_id";
                             
                DB::connection($parentConn)->update($updateSql, $updateBindings);
            }
        }

        // 4) Update list_header rows if provided in request
        if (! empty($request->input('list_header')) && is_array($request->input('list_header'))) {
            foreach ($request->input('list_header') as $value) {
                if (! empty($value['id']) && is_numeric($value['id'])) {
                    $updateParams = [
                        'id' => (int)$value['id'],
                        'is_search' => (! empty($value['is_search']) && is_numeric($value['is_search'])) ? (int)$value['is_search'] : 0,
                        'is_dialing' => (! empty($value['is_dialing']) && is_numeric($value['is_dialing'])) ? (int)$value['is_dialing'] : 0,
                        'is_visible' => (! empty($value['is_visible']) && is_numeric($value['is_visible'])) ? (int)$value['is_visible'] : 0,
                        'is_editable' => (! empty($value['is_editable']) && is_numeric($value['is_editable'])) ? (int)$value['is_editable'] : 0,
                        'label_id' => (! empty($value['label_id']) && is_numeric($value['label_id'])) ? (int)$value['label_id'] : null
                    ];

                    DB::connection($parentConn)->update(
                        "UPDATE list_header SET is_search = :is_search, is_dialing = :is_dialing, is_visible = :is_visible, is_editable = :is_editable, label_id = :label_id WHERE id = :id",
                        $updateParams
                    );
                }
            }
        }

        // 5) Update Lists table flags (is_active and optional is_dialing)
        $listUpdate = ['is_active' => 1];
        if ($request->has('is_dialing')) {
            $listUpdate['is_dialing'] = $request->input('is_dialing');
        }
        Lists::on($parentConn)->where('id', $listId)->update($listUpdate);

        // 6) Duplicate removal logic (only if duplicate_check requested)
        if ($request->has('duplicate_check') && (int)$request->input('duplicate_check') === 1) {
            // Find any dialing column for this list
            $recordDial = DB::connection($parentConn)->selectOne(
                "SELECT * FROM list_header WHERE list_id = :list_id AND is_dialing = 1",
                ['list_id' => $listId]
            );

            // If there is no dialing column, skip duplicate removal (this is normal for deleted lists)
            if (! empty($recordDial) && ! empty($recordDial->column_name)) {
                $columnName = $recordDial->column_name;

                // Safely build and run delete duplicates SQL.
                // Note: we must ensure the column name is an actual column (if your schema allows arbitrary column names, validate it)
                $sqlDeleteDuplicates = "
                    DELETE ld
                    FROM list_data ld
                    JOIN (
                        SELECT MIN(id) AS keep_id, `$columnName` AS phone_number
                        FROM list_data
                        WHERE list_id = :list_id1
                          AND `$columnName` IS NOT NULL
                          AND `$columnName` != ''
                        GROUP BY `$columnName`
                    ) AS keep_rows
                    ON ld.`$columnName` = keep_rows.phone_number
                    AND ld.list_id = :list_id2
                    AND ld.id <> keep_rows.keep_id
                ";

                DB::connection($parentConn)->statement($sqlDeleteDuplicates, [
                    'list_id1' => $listId,
                    'list_id2' => $listId
                ]);
            }
        }

        DB::connection($parentConn)->commit();

        // Final response
        return [
            'success' => 'true',
            'message' => 'Lists updated successfully.'
        ];
    } catch (\Throwable $e) {
        // Rollback if possible on the connection
        try {
            DB::connection($parentConn)->rollBack();
        } catch (\Throwable $inner) {
            // ignore rollback errors
        }

        Log::error("Lists.editList.error", [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
            "list_id" => $listId,
            "campaign_id" => $campaignId,
            "connection" => $parentConn
        ]);

        return [
            'success' => 'false',
            'message' => $e->getMessage()
        ];
    }
}

    /*
     * Add List
     * @param object $request
     * @return array
     */

    //   public function addList($request, $filePath)
    // {
    //     ini_set('max_execution_time', 1800);
    //     ini_set('memory_limit', '-1');


    //     $dataBase = 'mysql_' . $request->auth->parent_id;
    //     $campaignId = $request->input('campaign');

    //     try {
    //         $reader = Excel::toArray(new Excel(), $filePath);
    //     } catch (\Exception $e) {
    //         return array(
    //             'success' => 'false',
    //             'message' => 'Unable to read excel.'
    //         );
    //     }

    //     if (!empty($reader)) {
    //         ////////////////////////////
    //         $query = "INSERT INTO list (title) VALUE (:title)";
    //         $add = DB::connection($dataBase)->insert($query, ['title' => $request->input('title')]);
    //         if ($add == 1) {
    //             $sql = "SELECT id FROM list ORDER BY id DESC";
    //             $record = DB::connection($dataBase)->selectOne($sql, array());
    //             $data = (array) $record;
    //             $list_id = $data['id'];
    //             $listId = $list_id;

    //             $query = "INSERT INTO campaign_list (campaign_id, list_id) VALUE (:campaign_id, :list_id)";
    //             DB::connection($dataBase)->insert($query, array('campaign_id' => $campaignId, 'list_id' => $list_id));

    //             $date_array = array();
    //             $header_list = [];
    //             ////////////////////////////
    //             foreach ($reader as $row) {
    //                 if (is_array($row)) {
    //                     foreach ($row as $key => $value) {
    //                         if ($key == 0) {
    //                             $np = 0;
    //                             foreach ($value as $em => $ep) {
    //                                 $ncr = ++$np;
    //                                 $column_name = 'option_' . $ncr;
    //                                 if ($ncr > 30) {
    //                                     continue;
    //                                 }
    //                                 //$header_list[] = array('list_id'=>$list_id , 'column_name'=>$column_name , 'header'=>$ep );
    //                                 $h_list['list_id'] = $list_id;
    //                                 $h_list['column_name'] = $column_name;
    //                                /* if (empty($ep)) {
    //                                     $ep = null;
    //                                 }*/
    //                                 $h_list['header'] = $ep;
    //                                 //	if(empty($column_name) && empty($ep)){ continue; }
    //                                 $check_date = strlen(strrchr(strtolower($ep), "date"));

    //                                 if (strpos(strtolower($ep), 'date')) {
    //                                     $date_array[$ncr] = $ncr;
    //                                 }
    //                                 /*if (!empty($h_list['header'])) {
    //                                     $header_list[] = $h_list;
    //                                 }*/

                                    
    //                                     $header_list[] = $h_list;

    //                                 // $query[] = "INSERT INTO list_header (list_id, column_name, header) VALUE ($list_id, $column_name , $ep)";
    //                             }
    //                         } else {
    //                             $var_element[] = 'list_id';
    //                             $var_data[] = $list_id;
    //                             // $list_data['list_id']=$list_id;
    //                             $list_data = array("list_id" => $list_id);
    //                             if (empty($value[0]) && empty($value[1]) && empty($value[2])) {
    //                                 continue;
    //                             }
    //                             $k = 0;
    //                             foreach ($value as $emt => $ept) {
    //                                 $r = ++$k;
    //                                 if ($r > 30) {
    //                                     continue;
    //                                 }
    //                                 $var_element[] = 'option_' . $r;
    //                                 if (!empty($date_array[$r])) {
    //                                     if (is_int($ept)) {
    //                                         // +1 day difference added with date
    //                                         $ept = date("Y-m-d", (($ept - 25569) * 86400));
    //                                         $ept = date('Y-m-d', strtotime('+1 day', strtotime($ept)));
    //                                     }
    //                                 }

    //                                 $var_data[] = $ept;
    //                                 $list_data['option_' . $r] = $ept;
    //                             }
    //                             if (count($list_data) > 0) {
    //                                 $query_1[] = $list_data;
    //                             }
    //                             unset($var_data);
    //                             unset($var_element);
    //                             unset($list_data);
    //                         }
    //                         # code...
    //                     }
    //                 }
    //             }
    //         }
    //     } else {
    //         return array(
    //             'success' => 'false',
    //             'message' => 'Failed list upload process, File is empty',
    //             'list_id' => '',
    //             'campaign_id' => $campaignId
    //         );
    //     }

    //     if (count($query_1) > 0) {
    //         $save_data = true;
    //         foreach (array_chunk($header_list, 2000) as $t) {
    //             $save_data &= DB::connection($dataBase)->table('list_header')->insert($t);
    //         }
    //         foreach (array_chunk($query_1, 2000) as $t1) {
    //             $save_data &= DB::connection($dataBase)->table('list_data')->insert($t1);
    //         }

    //         DB::connection($dataBase)->table('list_data')->where('option_1', '=', '')->delete();

    //         $data = [
    //             "action" => "List added",
    //             "listId" => $listId,
    //             "listName" => $request->input('title'),
    //             "records" => count($query_1),
    //             "columns" => $header_list
    //         ];
    //         dispatch(new ListAddedNotificationJob($request->auth->parent_id, $campaignId, $data))->onConnection("database");

    //         return array(
    //             'success' => 'true',
    //             'message' => 'List added successfully.',
    //             'list_id' => $listId,
    //             'campaign_id' => $campaignId
    //         );
    //     }

    //     return array(
    //         'success' => 'false',
    //         'message' => 'Lists are not added. Unable to add data in list table'
    //     );
    // }
    public function addList($request, $filePath)
{
    ini_set('max_execution_time', 1800);
    ini_set('memory_limit', '-1');

    $dataBase = 'mysql_' . $request->auth->parent_id;
    $campaignId = $request->input('campaign');

    try {
        // ---- LOAD EXCEL USING PhpSpreadsheet ----
        $spreadsheet = IOFactory::load($filePath);
        $excelData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
    } catch (\Exception $e) {
        return [
            'success' => 'false',
            'message' => 'Unable to read excel.'
        ];
    }

    if (empty($excelData)) {
        return [
            'success' => 'false',
            'message' => 'Failed list upload process, File is empty',
            'list_id' => '',
            'campaign_id' => $campaignId
        ];
    }

    // ---------------------------------------------------
    // ALWAYS Extract Header First (Fix for your problem)
    // ---------------------------------------------------
    $header = array_shift($excelData);  // A,B,C keys OR numeric keys

    // Rest of rows (raw)
    $cleanData = $excelData;

    // ---------------------------------------------------
    // REMOVE DUPLICATES ONLY IF duplicate_check = 1
    // ---------------------------------------------------
    if ($request->input('duplicate_check') == 1) {

        $seen = [];
        $uniqueRows = [];

        foreach ($cleanData as $row) {

            // hash only values, not A,B,C keys
            $rowKey = md5(json_encode(array_values($row)));

            if (!isset($seen[$rowKey])) {
                $seen[$rowKey] = true;
                $uniqueRows[] = $row;
            }
        }

        $cleanData = $uniqueRows;
    }

    // ---------------------------------------------------
    // Rebuild excelData = header + cleaned data rows
    // ---------------------------------------------------
    $excelData = [];
    $excelData[] = $header;
    foreach ($cleanData as $r) {
        $excelData[] = $r;
    }

    // ---------------------------------------------------
    // INSERT LIST
    // ---------------------------------------------------
    $query = "INSERT INTO list (title, is_active, duplicate_check) 
              VALUES (:title, 1, :duplicate_check)";

    $add = DB::connection($dataBase)->insert($query, [
        'title' => $request->input('title'),
        'duplicate_check' => $request->input('duplicate_check') ?? 0
    ]);

    if ($add != 1) {
        return ['success' => 'false', 'message' => 'Unable to create list'];
    }

    $record = DB::connection($dataBase)
        ->selectOne("SELECT id FROM list ORDER BY id DESC LIMIT 1");

    $list_id = $record->id;
    $listId = $list_id;

    // LINK LIST TO CAMPAIGN
    DB::connection($dataBase)->insert(
        "INSERT INTO campaign_list (campaign_id, list_id) VALUE (:campaign_id, :list_id)",
        ['campaign_id' => $campaignId, 'list_id' => $list_id]
    );

    $date_array = [];
    $header_list = [];
    $query_1 = [];

    // ---------------------------------------------------
    // PROCESS HEADER + DATA ROWS
    // ---------------------------------------------------
    foreach ($excelData as $rowIndex => $row) {

        // HEADER ROW
        if ($rowIndex === 0) {
            $colIndex = 0;
            foreach ($row as $headerValue) {
                $colIndex++;
                if ($colIndex > 30) continue;

                $header_list[] = [
                    'list_id' => $list_id,
                    'column_name' => 'option_' . $colIndex,
                    'header' => $headerValue
                ];

                if (strpos(strtolower($headerValue), 'date') !== false) {
                    $date_array[$colIndex] = true;
                }
            }
            continue;
        }

        // SKIP BLANK ROWS
        if (
            ((trim(implode("", array_values($row))) === "")) ||
            (empty($row['A']) && empty($row['B']) && empty($row['C']))
        ) {
            continue;
        }

        $rowData = ['list_id' => $list_id];
        $colIndex = 0;

        foreach ($row as $cell) {
            $colIndex++;
            if ($colIndex > 30) continue;

            // Date conversion
            if (isset($date_array[$colIndex]) && is_numeric($cell)) {
                $cell = date("Y-m-d", (($cell - 25569) * 86400));
                $cell = date('Y-m-d', strtotime('+1 day', strtotime($cell)));
            }

            $rowData['option_' . $colIndex] = $cell;
        }

        $query_1[] = $rowData;
    }

    // ---------------------------------------------------
    // SAVE TO DATABASE
    // ---------------------------------------------------
    foreach (array_chunk($header_list, 2000) as $chunk) {
        DB::connection($dataBase)->table('list_header')->insert($chunk);
    }

    foreach (array_chunk($query_1, 2000) as $chunk2) {
        DB::connection($dataBase)->table('list_data')->insert($chunk2);
    }

    DB::connection($dataBase)->table('list_data')->where('option_1', '=', '')->delete();

    // SEND NOTIFICATION
    $data = [
        "action" => "List added",
        "listId" => $listId,
        "listName" => $request->input('title'),
        "records" => count($query_1),
        "columns" => $header_list
    ];

    dispatch(new ListAddedNotificationJob($request->auth->parent_id, $campaignId, $data))
        ->onConnection("database");

    return [
        'success' => 'true',
        'message' => 'List added successfully.',
        'list_id' => $listId,
        'campaign_id' => $campaignId
    ];
}

public function addListn($request, $filePath)
{
    ini_set('max_execution_time', 1800);
    ini_set('memory_limit', '-1');

    $dataBase = 'mysql_' . $request->auth->parent_id;
    $campaignId = $request->input('campaign');

    try {
        // ---- LOAD EXCEL USING PhpSpreadsheet ----
        $spreadsheet = IOFactory::load($filePath);
        $excelData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
    } catch (\Exception $e) {
        return [
            'success' => 'false',
            'message' => 'Unable to read excel.'
        ];
    }
    $header = array_shift($excelData);  // A,B,C keys OR numeric keys

    // Rest of rows (raw)
    $cleanData = $excelData;

   if ($request->input('duplicate_check') == 1) {

        $seen = [];
        $uniqueRows = [];

        foreach ($cleanData as $row) {

            // hash only values, not A,B,C keys
            $rowKey = md5(json_encode(array_values($row)));

            if (!isset($seen[$rowKey])) {
                $seen[$rowKey] = true;
                $uniqueRows[] = $row;
            }
        }

        $cleanData = $uniqueRows;
    }

    // ---------------------------------------------------
    // Rebuild excelData = header + cleaned data rows
    // ---------------------------------------------------
    $excelData = [];
    $excelData[] = $header;
    foreach ($cleanData as $r) {
        $excelData[] = $r;
    }



    // -------------------------------------------
    // ✔ NOW USE $excelData INSTEAD OF $reader
    // -------------------------------------------

    if (empty($excelData)) {
        return [
            'success' => 'false',
            'message' => 'Failed list upload process, File is empty',
            'list_id' => '',
            'campaign_id' => $campaignId
        ];
    }

    // -------------------------------------------
    // INSERT INTO list TABLE
    // -------------------------------------------

    $query = "INSERT INTO list (title, is_active, duplicate_check) 
          VALUES (:title, 1, :duplicate_check)";

$add = DB::connection($dataBase)->insert($query, [
    'title' => $request->input('title'),
    'duplicate_check' => $request->input('duplicate_check') ?? 0
]);


    if ($add != 1) {
        return ['success' => 'false', 'message' => 'Unable to create list'];
    }

    $sql = "SELECT id FROM list ORDER BY id DESC";
    $record = DB::connection($dataBase)->selectOne($sql);
    $list_id = $record->id;
    $listId = $list_id;

    // LINK LIST TO CAMPAIGN
    DB::connection($dataBase)->insert(
        "INSERT INTO campaign_list (campaign_id, list_id) VALUE (:campaign_id, :list_id)",
        ['campaign_id' => $campaignId, 'list_id' => $list_id]
    );

    $date_array = [];
    $header_list = [];
    $query_1 = [];

    // -------------------------------------------
    // PROCESS HEADER + ROWS
    // -------------------------------------------

    foreach ($excelData as $rowIndex => $row) {

        // HEADER ROW
        if ($rowIndex === 0) {
            $colIndex = 0;
            foreach ($row as $headerValue) {
                $colIndex++;
                if ($colIndex > 30) continue;

                $colName = 'option_' . $colIndex;
                $header_list[] = [
                    'list_id' => $list_id,
                    'column_name' => $colName,
                    'header' => $headerValue,
                ];

                // detect date keyword
                if (strpos(strtolower($headerValue), 'date') !== false) {
                    $date_array[$colIndex] = true;
                }
            }
            continue;
        }

        // SKIP BLANK ROWS
        if (empty($row[0]) && empty($row[1]) && empty($row[2])) continue;

        $rowData = ["list_id" => $list_id];

        $colIndex = 0;
        foreach ($row as $cell) {
            $colIndex++;
            if ($colIndex > 30) continue;

            // Date conversion
            if (isset($date_array[$colIndex]) && is_numeric($cell)) {
                $cell = date("Y-m-d", (($cell - 25569) * 86400));
                $cell = date('Y-m-d', strtotime('+1 day', strtotime($cell)));
            }

            $rowData['option_' . $colIndex] = $cell;
        }

        $query_1[] = $rowData;
    }

    // -------------------------------------------
    // SAVE TO DATABASE
    // -------------------------------------------

    $save_data = true;

    foreach (array_chunk($header_list, 2000) as $chunk) {
        $save_data &= DB::connection($dataBase)->table('list_header')->insert($chunk);
    }

    foreach (array_chunk($query_1, 2000) as $chunk2) {
        $save_data &= DB::connection($dataBase)->table('list_data')->insert($chunk2);
    }

    DB::connection($dataBase)->table('list_data')->where('option_1', '=', '')->delete();

    // SEND NOTIFICATION
    $data = [
        "action" => "List added",
        "listId" => $listId,
        "listName" => $request->input('title'),
        "records" => count($query_1),
        "columns" => $header_list
    ];

    dispatch(new ListAddedNotificationJob($request->auth->parent_id, $campaignId, $data))
        ->onConnection("database");

    return [
        'success' => 'true',
        'message' => 'List added successfully.',
        'list_id' => $listId,
        'campaign_id' => $campaignId
    ];
}

    function getLeadCount($request)
    {
        try {
            $dataBase = 'mysql_' . $request->auth->parent_id;
            $sql = "SELECT count(1) as rowCount FROM list_data ";
            $record = DB::connection($dataBase)->selectOne($sql);
            $response = (array) $record;
            $leadCount = $response['rowCount'];
            if ($leadCount > 0) {
                return array(
                    'success' => 'true',
                    'message' => 'Lead count',
                    'data' => $leadCount
                );
            } else {
                return array(
                    'success' => 'true',
                    'message' => 'User count not found',
                    'data' => 0
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }



    function updateCampaignListStatus($request)
    {
        $listId = $request->input('listId');
        $campaign_id = $request->input('campaign_id');
        $status = $request->input('status');
        $saveRecord = CampaignList::on('mysql_' . $request->auth->parent_id)->where('campaign_id', $campaign_id)->where('list_id', $listId)->update(array('status' => $status));

        $deleteFromLeadTemp = "DELETE FROM lead_temp WHERE list_id = :list_id and campaign_id = :campaign_id";
        $save_2 = DB::connection('mysql_' . $request->auth->parent_id)->delete($deleteFromLeadTemp, array('list_id' => $listId, 'campaign_id' => $campaign_id));

        $saveRecord = Lists::on('mysql_' . $request->auth->parent_id)->where('id', $listId)->update(array('is_active' => $status));
        if ($saveRecord > 0) {
            return array(
                'success' => 'true',
                'message' => 'Campaign List status updated successfully'
            );
        } else {
            return array(
                'success' => 'false',
                'message' => 'Campaign List update failed'
            );
        }
    }

    function updateListStatus($request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = CampaignList::on('mysql_' . $request->auth->parent_id)->where('list_id', $listId)->update(array('status' => $status));

        $deleteFromLeadTemp = "DELETE FROM lead_temp WHERE list_id = :list_id";
        $save_2 = DB::connection('mysql_' . $request->auth->parent_id)->delete($deleteFromLeadTemp, array('list_id' => $listId));

        $saveRecord = Lists::on('mysql_' . $request->auth->parent_id)->where('id', $listId)->update(array('is_active' => $status));
        if ($saveRecord > 0) {
            return array(
                'success' => 'true',
                'message' => 'List status updated successfully'
            );
        } else {
            return array(
                'success' => 'true',
                'message' => 'List update failed'
            );
        }
    }

    /**
     * Get Lead data and header
     * @param type $lead_id
     * @param type $parent_id
     * @return type
     */
//     function getLeadDataForEditPage($lead_id, $parent_id)
// {
//     try {
//         $leadDataArr = $inLabelArr = $inLeadArr = $finalLeadArr = $temp = [];

//         // Fetch lead data from main or archive table
//         $sql = "(SELECT * FROM list_data WHERE id = $lead_id) 
//                 UNION 
//                 (SELECT * FROM list_data_archive WHERE id = $lead_id)";
//         $record = DB::connection('mysql_' . $parent_id)->select($sql);
//         $listData = (array) $record;

//         if (!empty($listData)) {
//             $list_id = $listData[0]->list_id;
//             foreach ($listData[0] as $key => $val) {
//                 $inLeadArr[$key] = $val;
//             }
//         } else { // if no lead found, get a default list_id from list table
//             $sql = "SELECT id FROM list WHERE type = 2";
//             $record = DB::connection('mysql_' . $parent_id)->select($sql);
//             $list = (array) $record;
//             $list_id = $list[0]->id;
//         }

//         if ($list_id > 0) {
//             // Get all labels
//             $labels = DB::connection('mysql_' . $parent_id)
//                         ->select("SELECT id, title FROM label ORDER BY id ASC");

//             // Get all list_header columns for the list
//             $listHeaders = DB::connection('mysql_' . $parent_id)
//                              ->select("SELECT list_header.is_dialing, list_header.column_name, label.title, label.id
//                                        FROM list_header
//                                        INNER JOIN label ON label.id = list_header.label_id
//                                        WHERE list_header.list_id = $list_id
//                                        GROUP BY label.title
//                                        ORDER BY label.id ASC");

//             // Intermediate label array
//             foreach ($labels as $lab) {
//                 $inLabelArr[$lab->id] = $lab->title;
//             }

//             // Create lead array from list headers
//             foreach ($listHeaders as $header) {
//                 $temp['id'] = $header->id;
//                 $temp['title'] = $header->title;
//                 $temp['is_dialing'] = $header->is_dialing;
//                 $temp['column_name'] = $header->column_name;
//                 $temp['value'] = isset($inLeadArr[$header->column_name]) ? $inLeadArr[$header->column_name] : '';
//                 $leadDataArr[$header->id] = $temp;
//                 $temp = [];
//             }

//             // Create final lead array from labels
//             foreach ($inLabelArr as $key => $val) {
//                 if (isset($leadDataArr[$key])) {
//                     $finalLeadArr[$key] = $leadDataArr[$key];
//                 } else {
//                     $temp['id'] = $key;
//                     $temp['title'] = $val;
//                     $temp['value'] = '';
//                     $temp['is_dialing'] = 0;
//                     $finalLeadArr[$key] = $temp;
//                 }
//                 $temp = [];
//             }

//             // Filter only fields with non-empty values
//             $finalLeadArr = array_filter($finalLeadArr, function($item) {
//                 return isset($item['value']) && $item['value'] !== '';
//             });
//         }

//         return ['leadData' => (array) $finalLeadArr];

//     } catch (Exception $e) {
//         Log::error($e->getMessage());
//         return ['leadData' => []];
//     } catch (InvalidArgumentException $e) {
//         Log::error($e->getMessage());
//         return ['leadData' => []];
//     }
// }

function getLeadDataForEditPage($lead_id, $parent_id)
{
    try {
        $leadDataArr = $inLabelArr = $inLeadArr = $finalLeadArr = $temp = [];

        // ✅ Safety check for empty lead_id
        if (empty($lead_id) || !is_numeric($lead_id)) {
            return [
                'success' => false,
                'message' => 'Invalid lead ID',
                'leadData' => []
            ];
        }

        // ✅ Fetch lead data from main or archive table
        $sql = "(SELECT * FROM list_data WHERE id = $lead_id)
                UNION
                (SELECT * FROM list_data_archive WHERE id = $lead_id)";
                $record = DB::connection('mysql_' . $parent_id)->select($sql);
                Log::info("sql logged",['sql'=>$record]);
        $listData = (array) $record;

        $list_id = 0;

        if (!empty($listData)) {
            $list_id = $listData[0]->list_id;
            foreach ($listData[0] as $key => $val) {
                $inLeadArr[$key] = $val;
            }
        } else {
            // if no lead found, get a default list_id from list table
            $sql = "SELECT id FROM list WHERE type = 2 LIMIT 1";
            $record = DB::connection('mysql_' . $parent_id)->select($sql);
            $list = (array) $record;
            $list_id = $list[0]->id ?? 0;
        }

        if ($list_id > 0) {
            // ✅ Get all labels
            $labels = DB::connection('mysql_' . $parent_id)
                ->select("SELECT id, title FROM label WHERE is_deleted = 0 AND status = 1 ORDER BY display_order ASC");
                Log::info("sql logged",['labels'=>$labels]);

            // // ✅ Get all list_header columns for the list
            // $listHeaders = DB::connection('mysql_' . $parent_id)
            //     ->select("SELECT list_header.is_dialing, list_header.column_name, label.title, label.id
            //               FROM list_header
            //               INNER JOIN label ON label.id = list_header.label_id
            //               WHERE list_header.list_id = $list_id
            //               GROUP BY label.title
            //               ORDER BY label.id ASC");
            //     Log::info("sql logged",['listHeaders'=>$listHeaders]);
            Log::info("DEBUG list_id", ['list_id' => $list_id]);

$rawHeaders = DB::connection('mysql_' . $parent_id)
    ->table('list_header')
    ->where('list_id', $list_id)
    ->get();

Log::info("DEBUG raw list_header rows", ['rows' => $rawHeaders]);

$rawLabels = DB::connection('mysql_' . $parent_id)
    ->table('label')
    ->get();

Log::info("DEBUG all labels", ['labels' => $rawLabels]);

$listHeaders = DB::connection('mysql_' . $parent_id)
    ->select("
        SELECT 
            list_header.is_dialing, 
            list_header.column_name,
            list_header.is_visible, 
            list_header.is_editable, 
            label.title, 
            label.id
        FROM list_header
        INNER JOIN label 
        ON label.id = list_header.label_id
        WHERE list_header.list_id = $list_id
        AND is_visible= 1
        ORDER BY label.id ASC
    ");


Log::info("DEBUG joined listHeaders", ['listHeaders' => $listHeaders]);


            // Intermediate label array
            foreach ($labels as $lab) {
                $inLabelArr[$lab->id] = $lab->title;
            }

            // Create lead array from list headers
            foreach ($listHeaders as $header) {
                $temp['id'] = $header->id;
                $temp['title'] = $header->title;
                $temp['is_dialing'] = $header->is_dialing;
                $temp['is_visible'] = $header->is_visible;
                $temp['is_editable'] = $header->is_editable;
                $temp['column_name'] = $header->column_name;
                $temp['value'] = isset($inLeadArr[$header->column_name]) ? $inLeadArr[$header->column_name] : '';
                $leadDataArr[$header->id] = $temp;
                $temp = [];
            }

            // Create final lead array from labels
            foreach ($inLabelArr as $key => $val) {
                if (isset($leadDataArr[$key])) {
                    $finalLeadArr[$key] = $leadDataArr[$key];
                } else {
                    $temp['id'] = $key;
                    $temp['title'] = $val;
                    $temp['value'] = '';
                    $temp['is_dialing'] = 0;
                    $temp['column_name'] = '';
                    $finalLeadArr[$key] = $temp;
                }
                $temp = [];
            }

            // Filter only fields with non-empty values
            $finalLeadArr = array_filter($finalLeadArr, function($item) {
                return isset($item['value']) && $item['value'] !== '';
            });
            // ✅ Sort by ID ascending before returning
usort($finalLeadArr, function ($a, $b) {
    return $a['id'] <=> $b['id'];
});

        }

        // ✅ Final clean return (converted to numeric array)
        return [
            'success' => true,
            'message' => 'Edit Lead Data',
            'leadData' => array_values($finalLeadArr) // ✅ ensures JSON array, not object
        ];

    } catch (Exception $e) {
        Log::error($e->getMessage());
        return [
            'success' => false,
            'message' => 'Error fetching lead data: ' . $e->getMessage(),
            'leadData' => []
        ];
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
        return [
            'success' => false,
            'message' => 'Invalid argument: ' . $e->getMessage(),
            'leadData' => []
        ];
    }
}


    function getLeadDataForEditPage_copy($lead_id, $parent_id)
    {
        try {
            $leadDataArr = $inLabelArr = $inLeadArr = $finalLeadArr = $temp = [];


            $connectionName = 'mysql_' . $parent_id;
            $dbName = DB::connection($connectionName)->getDatabaseName();

            // Get column names dynamically using information_schema
            $listDataCols = DB::connection($connectionName)
                ->table('information_schema.columns')
                ->where('table_schema', $dbName)
                ->where('table_name', 'list_data')
                ->orderBy('ordinal_position')
                ->pluck('column_name')
                ->toArray();

            $archiveCols = DB::connection($connectionName)
                ->table('information_schema.columns')
                ->where('table_schema', $dbName)
                ->where('table_name', 'list_data_archive')
                ->orderBy('ordinal_position')
                ->pluck('column_name')
                ->toArray();

            // Step 2: Find the difference
            $missingCols = array_diff($listDataCols, $archiveCols);

            // Step 3: Build select statements
            $listDataSelect = implode(', ', $listDataCols);

            $archiveSelect = collect($listDataCols)->map(function ($col) use ($archiveCols) {
                return in_array($col, $archiveCols) ? $col : "NULL AS $col";
            })->implode(', ');

            $sql = "(SELECT $listDataSelect FROM list_data WHERE id = $lead_id) UNION (SELECT $archiveSelect FROM list_data_archive WHERE id = $lead_id)";
            $record = DB::connection('mysql_' . $parent_id)->select($sql);
            $listData = (array) $record;

            if (!empty($listData)) {
                $list_id = $listData[0]->list_id;
                foreach ($listData[0] as $key => $val) {
                    $inLeadArr[$key] = $val;
                }
            } else { //if no lead id found then get from list table having type = 2
                $sql = "SELECT id FROM list WHERE type = 2";
                $record = DB::connection('mysql_' . $parent_id)->select($sql);
                $list = (array) $record;
                $list_id = $list[0]->id;
            }

            if ($list_id > 0) {
                $sql = "SELECT id, title FROM label ORDER BY label.id ASC"; //get all labels
                $labels = DB::connection('mysql_' . $parent_id)->select($sql);

                //get all list_header columun (option_1,option_2)
                $sql = $sql = "SELECT list_header.is_dialing, list_header.column_name, label.title, label.id "
                    . "FROM list_header inner join label on label.id = list_header.label_id  "
                    . "WHERE list_header.list_id IN(" . $list_id . ") group by label.title ORDER BY label.id ASC";
                $listHeaders = DB::connection('mysql_' . $parent_id)->select($sql);

                //intermidiate label array
                foreach ($labels as $lab) {
                    $inLabelArr[$lab->id] = $lab->title;
                }
                //Create lead array from intermidiate List header array
                foreach ($listHeaders as $header) {
                    $temp['id'] = $header->id;
                    $temp['title'] = $header->title;
                    $temp['is_dialing'] = $header->is_dialing;
                    $temp['column_name'] = $header->column_name;
                    $temp['value'] = isset($inLeadArr[$header->column_name]) ? $inLeadArr[$header->column_name] : '';
                    $leadDataArr[$header->id] = $temp;
                    $temp = [];
                }
                //Create final lead array from  Lead array
                foreach ($inLabelArr as $key => $val) {
                    if (isset($leadDataArr[$key])) {
                        $finalLeadArr[$key] = $leadDataArr[$key];
                    } else {
                        $temp['id'] = $key;
                        $temp['title'] = $val;
                        $temp['value'] = '';
                        $temp['is_dialing'] = 0;
                        $finalLeadArr[$key] = $temp;
                    }
                    $temp = [];
                }
            }
            return ['leadData' => (array) $finalLeadArr];
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /**
     * Update / create lead / list data
     * @param type $request
     * @param type $parent_id
     * @return type
     */
    function updateLeadData($request, $parent_id)
    {
        $newColCnt = 0;
        $leadId = $request->input('lead_id');
        $number = $request->input('number');
        $arrLabelId = $request->input('label_id');
        $arrLabelVal = $request->input('label_value');;
        $list_id = $this->getListFromListData($leadId, $parent_id);
        //get all list_header columun (option_1,option_2)
        $sql = "SELECT list_header.is_dialing, list_header.column_name, label.title, label.id "
            . "FROM list_header inner join label on label.id = list_header.label_id  "
            . "WHERE list_header.list_id = $list_id group by label.title ORDER BY label.id ASC";
        $listHeaders = DB::connection('mysql_' . $parent_id)->select($sql);

        //Create lead array from intermidiate List header array
        $leadDataArr = [];
        foreach ($listHeaders as $header) {
            if (($key = array_search($header->column_name, $this->column_name)) !== false) {
                unset($this->column_name[$key]);
            }
            $leadDataArr[$header->id] = $header->column_name;
        }
        $this->column_name = array_values($this->column_name);

        if ($leadId == 0) {
            $query = "INSERT INTO list_data (list_id) VALUE (:list_id)";
            DB::connection('mysql_' . $parent_id)->insert($query, array('list_id' => $list_id));
            $query = "SELECT id FROM list_data ORDER BY id DESC LIMIT 1";
            $lead = DB::connection('mysql_' . $parent_id)->select($query);
            if (isset($lead[0]->id)) {
                $leadId = $lead[0]->id;
                $query = "UPDATE cdr SET lead_id = $leadId WHERE number = :number";
                DB::connection('mysql_' . $parent_id)->update($query, array('number' => $number));
                $query = "UPDATE cdr_archive SET lead_id = $leadId WHERE number = :number";
                DB::connection('mysql_' . $parent_id)->update($query, array('number' => $number));
            }
        }

        for ($i = 0; $i < count($arrLabelId); $i++) {
            if (isset($arrLabelVal[$i]) && $arrLabelVal[$i] != '') {
                if (isset($leadDataArr[$arrLabelId[$i]])) {
                    $query = "UPDATE list_data SET " . $leadDataArr[$arrLabelId[$i]] . " = :option_value WHERE id = :lead_id";
                    DB::connection('mysql_' . $parent_id)->update($query, array('option_value' => $arrLabelVal[$i], 'lead_id' => $leadId));
                } else {
                    $query = "INSERT INTO list_header (column_name, list_id, label_id) VALUE (:column_name, :list_id, :label)";
                    DB::connection('mysql_' . $parent_id)->insert($query, array('column_name' => $this->column_name[$newColCnt], 'list_id' => $list_id, 'label' => $arrLabelId[$i]));
                    usleep(25000);
                    $query = "UPDATE list_data SET " . $this->column_name[$newColCnt] . " = :option_value WHERE id = :lead_id";
                    DB::connection('mysql_' . $parent_id)->update($query, array('option_value' => $arrLabelVal[$i], 'lead_id' => $leadId));
                    $newColCnt++;
                }
            }
        }

        return array(
            'success' => 'true',
            'message' => 'Lead has been updated successfully'
        );
    }


    function updateLeadData_copy($request, $parent_id)
    {
        $newColCnt = 0;
        $leadId = $request->input('lead_id');
        $number = $request->input('number');
        $arrLabelId = $request->input('label_id');
        $arrLabelVal = $request->input('label_value');;
        $list_id = $this->getListFromListData_copy($leadId, $parent_id);
        //get all list_header columun (option_1,option_2)
        $sql = "SELECT list_header.is_dialing, list_header.column_name, label.title, label.id "
            . "FROM list_header inner join label on label.id = list_header.label_id  "
            . "WHERE list_header.list_id = $list_id group by label.title ORDER BY label.id ASC";
        $listHeaders = DB::connection('mysql_' . $parent_id)->select($sql);

        //Create lead array from intermidiate List header array
        $leadDataArr = [];
        foreach ($listHeaders as $header) {
            if (($key = array_search($header->column_name, $this->column_name)) !== false) {
                unset($this->column_name[$key]);
            }
            $leadDataArr[$header->id] = $header->column_name;
        }
        $this->column_name = array_values($this->column_name);

        if ($leadId == 0) {
            $query = "INSERT INTO list_data (list_id) VALUE (:list_id)";
            DB::connection('mysql_' . $parent_id)->insert($query, array('list_id' => $list_id));
            $query = "SELECT id FROM list_data ORDER BY id DESC LIMIT 1";
            $lead = DB::connection('mysql_' . $parent_id)->select($query);
            if (isset($lead[0]->id)) {
                $leadId = $lead[0]->id;
                $query = "UPDATE cdr SET lead_id = $leadId WHERE number = :number";
                DB::connection('mysql_' . $parent_id)->update($query, array('number' => $number));
                $query = "UPDATE cdr_archive SET lead_id = $leadId WHERE number = :number";
                DB::connection('mysql_' . $parent_id)->update($query, array('number' => $number));
            }
        }

        for ($i = 0; $i < count($arrLabelId); $i++) {
            if (isset($arrLabelVal[$i]) && $arrLabelVal[$i] != '') {
                if (isset($leadDataArr[$arrLabelId[$i]])) {
                    $query = "UPDATE list_data SET " . $leadDataArr[$arrLabelId[$i]] . " = :option_value WHERE id = :lead_id";
                    DB::connection('mysql_' . $parent_id)->update($query, array('option_value' => $arrLabelVal[$i], 'lead_id' => $leadId));
                } else {
                    $query = "INSERT INTO list_header (column_name, list_id, label_id) VALUE (:column_name, :list_id, :label)";
                    DB::connection('mysql_' . $parent_id)->insert($query, array('column_name' => $this->column_name[$newColCnt], 'list_id' => $list_id, 'label' => $arrLabelId[$i]));
                    usleep(25000);
                    $query = "UPDATE list_data SET " . $this->column_name[$newColCnt] . " = :option_value WHERE id = :lead_id";
                    DB::connection('mysql_' . $parent_id)->update($query, array('option_value' => $arrLabelVal[$i], 'lead_id' => $leadId));
                    $newColCnt++;
                }
            }
        }

        return array(
            'success' => 'true',
            'message' => 'Lead has been updated successfully'
        );
    }


    function changeDisposition($request, $parent_id)
    {

        $cdr_id = $request->input('cdr_id');
        $disposition_id = $request->input('disposition_id');

        $query = "UPDATE cdr SET disposition_id = $disposition_id WHERE id = :id";
        DB::connection('mysql_' . $parent_id)->update($query, array('id' => $cdr_id));
        $query = "UPDATE cdr_archive SET disposition_id = $disposition_id WHERE id = :id";
        DB::connection('mysql_' . $parent_id)->update($query, array('id' => $cdr_id));

        return array(
            'success' => 'true',
            'message' => 'disposition has been updated successfully'
        );
    }

    /**
     * get lead / list
     * if no lead id found then get from list table having type = 2
     * @param type $leadId
     * @param type $parent_id
     * @return type
     */
    function getListFromListData($lead_id, $parent_id)
    {
        $sql = "(SELECT * FROM list_data WHERE id = $lead_id) "
            . "UNION (SELECT * FROM list_data_archive WHERE id = $lead_id)";
        $record = DB::connection('mysql_' . $parent_id)->select($sql);
        $listData = (array) $record;

        if (!empty($listData)) {
            $list_id = $listData[0]->list_id;
        } else { //if no lead id found then get from list table having type = 2
            $sql = "SELECT id FROM list WHERE type = 2";
            $record = DB::connection('mysql_' . $parent_id)->select($sql);
            $list = (array) $record;
            $list_id = $list[0]->id;
        }

        return $list_id;
    }


    function getListFromListData_copy($lead_id, $parent_id)
    {

        $connectionName = 'mysql_' . $parent_id;
        $dbName = DB::connection($connectionName)->getDatabaseName();

        // Get column names dynamically using information_schema
        $listDataCols = DB::connection($connectionName)
            ->table('information_schema.columns')
            ->where('table_schema', $dbName)
            ->where('table_name', 'list_data')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->toArray();

        $archiveCols = DB::connection($connectionName)
            ->table('information_schema.columns')
            ->where('table_schema', $dbName)
            ->where('table_name', 'list_data_archive')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->toArray();

        // Step 2: Find the difference
        $missingCols = array_diff($listDataCols, $archiveCols);

        // Step 3: Build select statements
        $listDataSelect = implode(', ', $listDataCols);

        $archiveSelect = collect($listDataCols)->map(function ($col) use ($archiveCols) {
            return in_array($col, $archiveCols) ? $col : "NULL AS $col";
        })->implode(', ');


        $sql = "(SELECT  $listDataSelect  FROM list_data WHERE id = $lead_id) "
            . "UNION (SELECT $archiveSelect  FROM list_data_archive WHERE id = $lead_id)";
        $record = DB::connection('mysql_' . $parent_id)->select($sql);
        $listData = (array) $record;

        if (!empty($listData)) {
            $list_id = $listData[0]->list_id;
        } else { //if no lead id found then get from list table having type = 2
            $sql = "SELECT id FROM list WHERE type = 2";
            $record = DB::connection('mysql_' . $parent_id)->select($sql);
            $list = (array) $record;
            $list_id = $list[0]->id;
        }

        return $list_id;
    }
}
