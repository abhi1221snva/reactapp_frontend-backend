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

    public function getListHeader($request)
    {
        try {
            $data = array();
            $searchStr = array();
            if ($request->has('list_data') && is_array($request->input('list_data'))) {
                $data['list_id'] = $request->input('list_data');
            }
            if ($data['list_id'][0] == '0') {
                $list = implode(',', $data['list_id']);
                $list = "'" . implode("', '", $data['list_id']) . "'";
                $data['list_id'] = $list;
                $sql = "SELECT list_header.column_name,label.title FROM list_header inner join label on label.id = list_header.label_id  WHERE list_header.list_id NOT IN(" . $list . ") group by label.title";
            } else {
                $list = implode(',', $data['list_id']);
                $list = "'" . implode("', '", $data['list_id']) . "'";
                $data['list_id'] = $list;
                $sql = "SELECT list_header.column_name,label.title FROM list_header inner join label on label.id = list_header.label_id  WHERE list_header.list_id IN(" . $list . ") group by label.title";
            }
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array) $record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'List header detail.',
                    'data' => $data
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'List Header not created.',
                    'data' => array()
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
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
                      l.is_dialing     -- ✅ Added this line
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
                      l.is_dialing     -- ✅ Added this line
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
    public function getList_oldcode($request)
    {
        if ($request->has('list_id') && is_numeric($request->input('list_id')) && $request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
            $sql = "SELECT
                    c.title as campaign, l.title as list, cl.campaign_id, cl.list_id, cl.updated_at , l.is_active
                FROM
                campaign_list as cl
                LEFT JOIN list as l ON l.id = cl.list_id
                LEFT JOIN campaign as c ON c.id = cl.campaign_id
                WHERE cl.is_deleted = :is_deleted AND list_id = :list_id AND campaign_id = :campaign_id";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, array('is_deleted' => 0, 'list_id' => $request->input('list_id'), 'campaign_id' => $request->input('campaign_id')));
            $data = (array) $record;

            $sql = "SELECT * FROM list_header WHERE list_id = :list_id AND is_deleted = :is_deleted";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('is_deleted' => 0, 'list_id' => $request->input('list_id')));
            $listHeader = (array) $record;
            $data['list_header'] = $listHeader;
        } else {
            $sql = "SELECT
                          c.title as campaign, l.title as list, cl.campaign_id, cl.list_id, cl.updated_at, l.is_active
                        FROM
                        campaign_list as cl
                        LEFT JOIN list as l ON l.id = cl.list_id
                        LEFT JOIN campaign as c ON c.id = cl.campaign_id
                        WHERE cl.is_deleted = :is_deleted";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('is_deleted' => 0));
            $data = (array) $record;

            foreach ($data as $key => $id) {
                $list['list_id'] = $id->list_id;
                $sql_count_list = "SELECT count(1) as rowCountList FROM list_data WHERE list_id = :list_id ";
                $record_count_list = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_count_list, $list);
                $id->rowListData = $record_count_list->rowCountList;
            }
        }

        if (!empty($data)) {
            return array(
                'success' => 'true',
                'message' => 'Lists detail.',
                'data' => $data
            );
        }
        return array(
            'success' => 'false',
            'message' => 'Lists not created.',
            'data' => array()
        );
    }

    /*
     * Edit List
     * @param object $request
     * @return array
     */

//   public function editList($request)
// {
//     $saveRecord = true;

//     try {
//         if (
//             $request->has('list_id') && is_numeric($request->input('list_id')) &&
//             $request->has('campaign_id') && is_numeric($request->input('campaign_id'))
//         ) {
//             $save_1 = $save_2 = $save_3 = '';
//             $isDeleted = false;
//             $updateString = [];
//             $data = [];

//             // Update title if provided
//             if ($request->has('title') && !empty($request->input('title'))) {
//                 $query = "UPDATE list SET title = :title WHERE id = :id";
//                 $save_1 = DB::connection('mysql_' . $request->auth->parent_id)
//                     ->update($query, [
//                         'title' => $request->input('title'),
//                         'id'    => $request->input('list_id')
//                     ]);
//             }

//             // Campaign ID update
//             if ($request->has('new_campaign_id') && is_numeric($request->input('new_campaign_id'))) {
//                 $updateString[] = 'campaign_id = :new_campaign_id';
//                 $data['new_campaign_id'] = $request->input('new_campaign_id');
//             }

//             // Status update
//             if ($request->has('status') && is_numeric($request->input('status'))) {
//                 $updateString[] = 'status = :status';
//                 $data['status'] = $request->input('status');
//             }

//             // Deleted flag
//             if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
//                 $updateString[] = 'is_deleted = :is_deleted';
//                 $data['is_deleted'] = $request->input('is_deleted');
//                 $isDeleted = true;
//             }

//             // If we have something to update
//             if (!empty($updateString) && !empty($data)) {
//                 $data['list_id'] = $request->input('list_id');
//                 $data['campaign_id'] = $request->input('campaign_id');

//                 if ($isDeleted) {
//                     // Delete related records
//                     $relatedTables = [
//                         'campaign_list',
//                         'list_data',
//                         'lead_report',
//                         'lead_temp',
//                         'list_header'
//                     ];

//                     foreach ($relatedTables as $table) {
//                         $sqlCount = "SELECT count(1) as rowCount FROM $table WHERE list_id = :list_id";
//                         $record = DB::connection('mysql_' . $request->auth->parent_id)
//                             ->selectOne($sqlCount, ['list_id' => $request->input('list_id')]);

//                         if ($record && $record->rowCount > 0) {
//                             $query = "DELETE FROM $table WHERE list_id = :list_id";
//                             DB::connection('mysql_' . $request->auth->parent_id)
//                                 ->delete($query, ['list_id' => $request->input('list_id')]);
//                         }
//                     }

//                     // Delete list itself
//                     $listModel = Lists::on('mysql_' . $request->auth->parent_id)
//                         ->findOrFail($request->input('list_id'));

//                     $notificationData = [
//                         "action"   => "List deleted",
//                         "listId"   => $request->input('list_id'),
//                         "listName" => $listModel->title
//                     ];

//                     $listModel->delete();

//                     dispatch(new ListAddedNotificationJob(
//                         $request->auth->parent_id,
//                         $request->input('campaign_id'),
//                         $notificationData
//                     ))->onConnection("database");

//                 } else {
//                     // Normal update
//                     $query = "UPDATE campaign_list SET " . implode(", ", $updateString) .
//                              " WHERE list_id = :list_id AND campaign_id = :campaign_id";
//                     $save_2 = DB::connection('mysql_' . $request->auth->parent_id)
//                         ->update($query, $data);
//                 }
//             }

//             // Update list_header only if not deleted
//             if (!$isDeleted && !empty($request->input('list_header')) && is_array($request->input('list_header'))) {
//                 $strIsDialSelectedColumn = '';
//                 $boolIsDialingFound = false;

//                 foreach ($request->input('list_header') as $value) {
//                     if (!empty($value['id']) && is_numeric($value['id'])) {
//                         $update = [
//                             'id'         => $value['id'],
//                             'is_search'  => (!empty($value['is_search']) && is_numeric($value['is_search'])) ? $value['is_search'] : 0,
//                             'is_dialing' => (!empty($value['is_dialing']) && is_numeric($value['is_dialing'])) ? $value['is_dialing'] : 0,
//                             'is_visible' => (!empty($value['is_visible']) && is_numeric($value['is_visible'])) ? $value['is_visible'] : 0,
//                             'is_editable'=> (!empty($value['is_editable']) && is_numeric($value['is_editable'])) ? $value['is_editable'] : 0,
//                             'label_id'   => (!empty($value['label_id']) && is_numeric($value['label_id'])) ? $value['label_id'] : null
//                         ];

//                         $query = "UPDATE list_header 
//                                   SET is_search = :is_search,
//                                       is_dialing = :is_dialing,
//                                       is_visible = :is_visible,
//                                       is_editable = :is_editable,
//                                       label_id = :label_id
//                                   WHERE id = :id";

//                         DB::connection('mysql_' . $request->auth->parent_id)->update($query, $update);

//                         if ($update['is_dialing'] == 1 && !empty($value['column_name'])) {
//                             $strIsDialSelectedColumn = $value['column_name'];
//                             $boolIsDialingFound = true;
//                         }
//                     }
//                 }

//                 Lists::on('mysql_' . $request->auth->parent_id)
//                     ->where('id', $request->input('list_id'))
//                     ->update(['is_active' => 1]);

//                 // Remove duplicates if dialing column exists
//                 $record_list_data = DB::connection('mysql_' . $request->auth->parent_id)
//                     ->selectOne("SELECT * FROM list_header WHERE list_id = :list_id AND is_dialing = 1", [
//                         'list_id' => $request->input('list_id')
//                     ]);

//                 if ($record_list_data && !empty($record_list_data->column_name)) {
//                     $listId = $request->input('list_id');
//                     $columnName = $record_list_data->column_name;

//                     $sql_delete_duplicates = "
//                         DELETE ld
//                         FROM list_data ld
//                         JOIN (
//                             SELECT MIN(id) AS keep_id, `$columnName` AS phone_number
//                             FROM list_data
//                             WHERE list_id = :list_id1
//                               AND `$columnName` IS NOT NULL
//                               AND `$columnName` != ''
//                             GROUP BY `$columnName`
//                         ) AS keep_rows
//                         ON ld.`$columnName` = keep_rows.phone_number
//                         AND ld.list_id = :list_id2
//                         AND ld.id <> keep_rows.keep_id
//                     ";

//                     DB::connection('mysql_' . $request->auth->parent_id)
//                         ->statement($sql_delete_duplicates, [
//                             'list_id1' => $listId,
//                             'list_id2' => $listId
//                         ]);
//                 }
//             }
//         }


//         if($request->input('is_deleted') == 1)
//         {

//              return [
//             'success' => 'true',
//             'message' => 'Lists deleted successfully.'
//         ];
//         }
//         else
//         {

//         }

//         return [
//             'success' => 'true',
//             'message' => 'Lists updated successfully.'
//         ];

//     } catch (\Throwable $e) {
//         Log::error("Lists.editList.error", [
//             "message" => $e->getMessage(),
//             "file"    => $e->getFile(),
//             "line"    => $e->getLine()
//         ]);

//         return [
//             'success' => 'false',
//             'message' => $e->getMessage()
//         ];
//     }
// }
 public function editList($request)
    {
        $saveRecord = true;
        try {
            if ($request->has('list_id') && is_numeric($request->input('list_id')) && $request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
                $save_1 = '';
                $save_2 = '';
                $save_3 = '';
                $isDeleted = "false";
                $updateString = array();
                if ($request->has('title') && !empty($request->input('title'))) {
                    $query = "UPDATE list set title = :title WHERE id = :id";
                    $save_1 = DB::connection('mysql_' . $request->auth->parent_id)->update($query, array('title' => $request->input('title'), 'id' => $request->input('list_id')));
                }
                if ($request->has('new_campaign_id') && is_numeric($request->input('new_campaign_id'))) {
                    array_push($updateString, 'campaign_id = :new_campaign_id');
                    $data['new_campaign_id'] = $request->input('new_campaign_id');
                }
                if ($request->has('status') && is_numeric($request->input('status'))) {
                    array_push($updateString, 'status = :status');
                    $data['status'] = $request->input('status');
                }
                if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
                    array_push($updateString, 'is_deleted = :is_deleted');
                    $data['is_deleted'] = $request->input('is_deleted');
                    $isDeleted = "true";
                }
                if (!empty($updateString) && !empty($data)) {
                    $data['list_id'] = $request->input('list_id');
                    $data['campaign_id'] = $request->input('campaign_id');

                    if ($isDeleted == "true") {
                        $sql_campaign_list = "SELECT count(1) as rowCountListCampaign FROM campaign_list WHERE list_id = :list_id ";
                        $record_campaign_list = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_campaign_list, array('list_id' => $request->input('list_id')));
                        if ($record_campaign_list->rowCountListCampaign > 0) {
                            $query = "DELETE FROM campaign_list WHERE list_id = :list_id";
                            $save_2 = DB::connection('mysql_' . $request->auth->parent_id)->delete($query, array('list_id' => $request->input('list_id')));
                        }

                        $sql_list_data = "SELECT count(1) as rowCountListData FROM list_data WHERE list_id = :list_id ";
                        $record_list_data = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_list_data, array('list_id' => $request->input('list_id')));
                        if ($record_list_data->rowCountListData > 0) {
                            $query = "DELETE FROM list_data WHERE list_id = :list_id";
                            $save_3 = DB::connection('mysql_' . $request->auth->parent_id)->delete($query, array('list_id' => $request->input('list_id')));
                        }
                        $sql_lead_report = "SELECT count(1) as rowCountListLeadReport FROM lead_report WHERE list_id = :list_id ";
                        $record_lead_report = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_lead_report, array('list_id' => $request->input('list_id')));
                        if ($record_lead_report->rowCountListLeadReport > 0) {
                            $query = "DELETE FROM lead_report WHERE list_id = :list_id";
                            $save_6 = DB::connection('mysql_' . $request->auth->parent_id)->delete($query, array('list_id' => $request->input('list_id')));
                        }

                        $sql_lead_temp = "SELECT count(1) as rowCountListLeadTemp FROM lead_temp WHERE list_id = :list_id ";
                        $record_lead_temp = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_lead_temp, array('list_id' => $request->input('list_id')));

                        if ($record_lead_temp->rowCountListLeadTemp > 0) {
                            $query = "DELETE FROM lead_temp WHERE list_id = :list_id";
                            $save_4 = DB::connection('mysql_' . $request->auth->parent_id)->delete($query, array('list_id' => $request->input('list_id')));
                        }

                        $sql_list_header = "SELECT count(1) as rowCountListHeader FROM list_header WHERE list_id = :list_id ";
                        $record_list_header = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_list_header, array('list_id' => $request->input('list_id')));
                        if ($record_list_header->rowCountListHeader > 0) {
                            $query = "DELETE FROM list_header WHERE list_id = :list_id";
                            $save_5 = DB::connection('mysql_' . $request->auth->parent_id)->delete($query, array('list_id' => $request->input('list_id')));
                        }

                        #$query = "DELETE FROM list WHERE id = :id";
                        #$delete_1 = DB::connection('mysql_' . $request->auth->parent_id)->delete($query, array('id' => $request->input('list_id')));
                        $listModel = Lists::on('mysql_' . $request->auth->parent_id)->findOrFail($request->input('list_id'));
                        $notificationData = [
                            "action" => "List deleted",
                            "listId" => $request->input('list_id'),
                            "listName" => $listModel->title
                        ];
                        $listModel->delete();

                        dispatch(new ListAddedNotificationJob($request->auth->parent_id, $request->input('campaign_id'), $notificationData))->onConnection("database");
                    } else {
                        $query = "UPDATE campaign_list set " . implode(" AND ", $updateString) . " WHERE list_id = :list_id AND campaign_id = :campaign_id";
                        $save_2 = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                    }
                }

                $strIsDialSelectedColumn = '';
                $boolIsDialingFound = false;

                if (!empty($request->input('list_header')) && is_array($request->input('list_header'))) {
                    foreach ($request->input('list_header') as $item => $value) {
                        if (!empty($value['id']) && is_numeric($value['id'])) {
                            $update['id'] = $value['id'];
                            $update['is_search'] = (!empty($value['is_search']) && is_numeric($value['is_search'])) ? $value['is_search'] : 0;
                            $update['is_dialing'] = (!empty($value['is_dialing']) && is_numeric($value['is_dialing'])) ? $value['is_dialing'] : 0;
                            $update['is_visible'] = (!empty($value['is_visible']) && is_numeric($value['is_visible'])) ? $value['is_visible'] : 0;
                            $update['is_editable'] = (!empty($value['is_editable']) && is_numeric($value['is_editable'])) ? $value['is_editable'] : 0;
                            $update['label_id'] = (!empty($value['label_id']) && is_numeric($value['label_id'])) ? $value['label_id'] : null;
                            $query = "UPDATE list_header set is_search = :is_search , is_dialing = :is_dialing, is_visible = :is_visible, is_editable = :is_editable, label_id = :label_id WHERE id = :id";
                            $save_3 = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $update);
                            //$query = "UPDATE list_header set is_search = :is_search , is_dialing = :is_dialing, is_visible = :is_visible, is_editable = :is_editable, label_id = :label_id WHERE id = :id";
                            //$saveRecord &=  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $update);

                            if ($update['is_dialing'] == 1) {
                                $strIsDialSelectedColumn = $value['column_name'];
                                $boolIsDialingFound = true;
                            }
                        }
                    }
         
$updateData = ['is_active' => 1];

// ✅ If request has dialing column, include it in update
if ($request->has('is_dialing')) {
    $updateData['is_dialing'] = $request->input('is_dialing');
}

$saveRecord &= Lists::on('mysql_' . $request->auth->parent_id)
    ->where('id', $request->input('list_id'))
    ->update($updateData);
                }

                $sql_list_data = "SELECT * FROM list_header WHERE list_id = :list_id and is_dialing=1 ";
                        $record_list_data = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_list_data, array('list_id' => $request->input('list_id')));
                if (empty($record_list_header)) {
                    return [
                        'success' => 'false',
                        'message' => 'No records found in list header for this list.'
                    ];
                }
                       // echo "<pre>";print_r($record_list_data);die;

$listId = $request->input('list_id');
$columnName = $record_list_data->column_name;
// ✅ Run duplicate removal only if 'is_dialing' = 1
if ($request->has('duplicate_check') && $request->input('duplicate_check') == 1) {

    $sql_list_data = "SELECT * FROM list_header WHERE list_id = :list_id AND is_dialing = 1";
    $record_list_data = DB::connection('mysql_' . $request->auth->parent_id)
        ->selectOne($sql_list_data, ['list_id' => $request->input('list_id')]);

    // Only proceed if a dialing column exists
    if (!empty($record_list_data) && !empty($record_list_data->column_name)) {

        $listId = $request->input('list_id');
        $columnName = $record_list_data->column_name;

        $sql_delete_duplicates = "
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

        DB::connection('mysql_' . $request->auth->parent_id)
            ->statement($sql_delete_duplicates, [
                'list_id1' => $listId,
                'list_id2' => $listId
            ]);
    }
}

            }
            if ($saveRecord) {
                return array(
                    'success' => 'true',
                    'message' => 'Lists updated successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Lists update failed.'
                );
            }
        } catch (\Throwable $e) {
            Log::error("Lists.editList.error", [
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            return array(
                'success' => 'false',
                'message' => $e->getMessage()
            );
        }
    }

    /*
     * Add List
     * @param object $request
     * @return array
     */

      public function addList($request, $filePath)
    {
        ini_set('max_execution_time', 1800);
        ini_set('memory_limit', '-1');


        $dataBase = 'mysql_' . $request->auth->parent_id;
        $campaignId = $request->input('campaign');

        try {
            $reader = Excel::toArray(new Excel(), $filePath);
        } catch (\Exception $e) {
            return array(
                'success' => 'false',
                'message' => 'Unable to read excel.'
            );
        }

        if (!empty($reader)) {
            ////////////////////////////
            $query = "INSERT INTO list (title) VALUE (:title)";
            $add = DB::connection($dataBase)->insert($query, ['title' => $request->input('title')]);
            if ($add == 1) {
                $sql = "SELECT id FROM list ORDER BY id DESC";
                $record = DB::connection($dataBase)->selectOne($sql, array());
                $data = (array) $record;
                $list_id = $data['id'];
                $listId = $list_id;

                $query = "INSERT INTO campaign_list (campaign_id, list_id) VALUE (:campaign_id, :list_id)";
                DB::connection($dataBase)->insert($query, array('campaign_id' => $campaignId, 'list_id' => $list_id));

                $date_array = array();
                $header_list = [];
                ////////////////////////////
                foreach ($reader as $row) {
                    if (is_array($row)) {
                        foreach ($row as $key => $value) {
                            if ($key == 0) {
                                $np = 0;
                                foreach ($value as $em => $ep) {
                                    $ncr = ++$np;
                                    $column_name = 'option_' . $ncr;
                                    if ($ncr > 30) {
                                        continue;
                                    }
                                    //$header_list[] = array('list_id'=>$list_id , 'column_name'=>$column_name , 'header'=>$ep );
                                    $h_list['list_id'] = $list_id;
                                    $h_list['column_name'] = $column_name;
                                   /* if (empty($ep)) {
                                        $ep = null;
                                    }*/
                                    $h_list['header'] = $ep;
                                    //	if(empty($column_name) && empty($ep)){ continue; }
                                    $check_date = strlen(strrchr(strtolower($ep), "date"));

                                    if (strpos(strtolower($ep), 'date')) {
                                        $date_array[$ncr] = $ncr;
                                    }
                                    /*if (!empty($h_list['header'])) {
                                        $header_list[] = $h_list;
                                    }*/

                                    
                                        $header_list[] = $h_list;

                                    // $query[] = "INSERT INTO list_header (list_id, column_name, header) VALUE ($list_id, $column_name , $ep)";
                                }
                            } else {
                                $var_element[] = 'list_id';
                                $var_data[] = $list_id;
                                // $list_data['list_id']=$list_id;
                                $list_data = array("list_id" => $list_id);
                                if (empty($value[0]) && empty($value[1]) && empty($value[2])) {
                                    continue;
                                }
                                $k = 0;
                                foreach ($value as $emt => $ept) {
                                    $r = ++$k;
                                    if ($r > 30) {
                                        continue;
                                    }
                                    $var_element[] = 'option_' . $r;
                                    if (!empty($date_array[$r])) {
                                        if (is_int($ept)) {
                                            // +1 day difference added with date
                                            $ept = date("Y-m-d", (($ept - 25569) * 86400));
                                            $ept = date('Y-m-d', strtotime('+1 day', strtotime($ept)));
                                        }
                                    }

                                    $var_data[] = $ept;
                                    $list_data['option_' . $r] = $ept;
                                }
                                if (count($list_data) > 0) {
                                    $query_1[] = $list_data;
                                }
                                unset($var_data);
                                unset($var_element);
                                unset($list_data);
                            }
                            # code...
                        }
                    }
                }
            }
        } else {
            return array(
                'success' => 'false',
                'message' => 'Failed list upload process, File is empty',
                'list_id' => '',
                'campaign_id' => $campaignId
            );
        }

        if (count($query_1) > 0) {
            $save_data = true;
            foreach (array_chunk($header_list, 2000) as $t) {
                $save_data &= DB::connection($dataBase)->table('list_header')->insert($t);
            }
            foreach (array_chunk($query_1, 2000) as $t1) {
                $save_data &= DB::connection($dataBase)->table('list_data')->insert($t1);
            }

            DB::connection($dataBase)->table('list_data')->where('option_1', '=', '')->delete();

            $data = [
                "action" => "List added",
                "listId" => $listId,
                "listName" => $request->input('title'),
                "records" => count($query_1),
                "columns" => $header_list
            ];
            dispatch(new ListAddedNotificationJob($request->auth->parent_id, $campaignId, $data))->onConnection("database");

            return array(
                'success' => 'true',
                'message' => 'List added successfully.',
                'list_id' => $listId,
                'campaign_id' => $campaignId
            );
        }

        return array(
            'success' => 'false',
            'message' => 'Lists are not added. Unable to add data in list table'
        );
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

            // ✅ Get all list_header columns for the list
            $listHeaders = DB::connection('mysql_' . $parent_id)
                ->select("SELECT list_header.is_dialing, list_header.column_name, label.title, label.id
                          FROM list_header
                          INNER JOIN label ON label.id = list_header.label_id
                          WHERE list_header.list_id = $list_id
                          GROUP BY label.title
                          ORDER BY label.id ASC");

            // Intermediate label array
            foreach ($labels as $lab) {
                $inLabelArr[$lab->id] = $lab->title;
            }

            // Create lead array from list headers
            foreach ($listHeaders as $header) {
                $temp['id'] = $header->id;
                $temp['title'] = $header->title;
                $temp['is_dialing'] = $header->is_dialing;
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
