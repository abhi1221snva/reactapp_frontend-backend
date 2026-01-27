<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
class Disposition extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    /*
     *Fetch dispositions list
     *@param integer $id
     *@return array
     */
    // public function dispositionDetail($request)
    // {
    //     $searchStr = array('is_deleted = :is_deleted');
    //     $data['is_deleted'] = 0;
    //     if($request->has('disposition_id') && is_numeric($request->input('disposition_id')))
    //     {
    //         array_push($searchStr, 'id = :id');
    //         $data['id'] = $request->input('disposition_id');
    //     }
    //     //Fetch data from master
    //     $sql = "SELECT * FROM disposition  WHERE ".implode(" AND ", $searchStr);;
    //     //$record =  DB::connection('master')->select($sql, $data);
    //     $record =  DB::connection('master')->select($sql, $data);
    //     $dataMaster = (array)$record;

    //     //Fetch data from client
    //     $sql = "SELECT * FROM disposition  WHERE ".implode(" AND ", $searchStr)." order by title";
    //     $record =  DB::connection('mysql_'.$request->auth->parent_id)->select($sql, $data);
    //     $dataClient = (array)$record;
    //     //$data = array_merge($dataMaster, $dataClient);
    //     $data = array_merge($dataClient);
        
    //     // Apply pagination if present
    //     if ($request->has(['start', 'limit'])) {
    //         $start = (int)$request->input('start');
    //         $limit = (int)$request->input('limit');
    //         $data = array_slice($data, $start, $limit, true); // paginate array
    //     }
    //     if(!empty($data))
    //     {
    //         return array(
    //             'success'=> 'true',
    //             'message'=> 'Dispositions detail.',
    //             'data'   => $data
    //         );
    //     }
    //     return array(
    //         'success'=> 'false',
    //         'message'=> 'Dispositions not created.',
    //         'data'   => array()
    //     );
    // }
//    public function dispositionDetail($request)
// {
//     try {
//         $searchStr = ['is_deleted = :is_deleted'];
//         $data['is_deleted'] = 0;

//         // Filter by disposition ID
//         if ($request->has('disposition_id') && is_numeric($request->input('disposition_id'))) {
//             $searchStr[] = 'id = :id';
//             $data['id'] = $request->input('disposition_id');
//         }

//         // Filter by search keyword (title)
//         if ($request->has('title') && !empty($request->input('title'))) {
//             $searchKeyword = '%' . $request->input('title') . '%';
//             $searchStr[] = '(title LIKE :search)';
//             $data['search'] = $searchKeyword;
//         }

//         $whereClause = implode(" AND ", $searchStr);

//         // Fetch from master DB
//         $sqlMaster = "SELECT * FROM disposition WHERE $whereClause";
//         $masterRecords = DB::connection('master')->select($sqlMaster, $data);

//         // Fetch from client DB
//         $sqlClient = "SELECT * FROM disposition WHERE $whereClause";
//         $clientRecords = DB::connection('mysql_' . $request->auth->parent_id)->select($sqlClient, $data);

//         // Merge both
//         $mergedData = array_merge((array)$masterRecords, (array)$clientRecords);

//         // Sort by title
//         usort($mergedData, fn($a, $b) => strcmp($a->title, $b->title));

//         // Total before pagination
//         $total = count($mergedData);

//         // Apply pagination if present
//         if ($request->has('start') && $request->has('limit')) {
//             $start = (int)$request->input('start');
//             $limit = (int)$request->input('limit');
//             $mergedData = array_slice($mergedData, $start, $limit);
//         }

//         if (!empty($mergedData)) {
//             return [
//                 'success' => 'true',
//                 'message' => 'Dispositions detail.',
//                 'total_rows'   => $total,
//                 'data'    => $mergedData,
//             ];
//         }

//         return [
//             'success' => 'false',
//             'message' => 'No dispositions found.',
//             'data'    => [],
//             'total'   => 0,
//         ];
//     } catch (\Exception $e) {
//         return [
//             'success' => 'false',
//             'message' => 'Something went wrong: ' . $e->getMessage(),
//             'data'    => [],
//             'total'   => 0,
//         ];
//     }
// }

public function dispositionDetail($request)
{
    try {
        $searchStr = ['is_deleted = :is_deleted'];
        $data['is_deleted'] = 0;

        // Filter by disposition ID
        if ($request->has('disposition_id') && is_numeric($request->input('disposition_id'))) {
            $searchStr[] = 'id = :id';
            $data['id'] = $request->input('disposition_id');
        }

        // Filter by title search
        if ($request->has('title') && !empty($request->input('title'))) {
            $data['search'] = '%' . $request->input('title') . '%';
            $searchStr[] = '(title LIKE :search)';
        }

        $whereClause = implode(" AND ", $searchStr);

        // Fetch ONLY from client DB
        $sql = "SELECT * FROM disposition WHERE $whereClause";
        $records = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);

        // Sort alphabetically
        usort($records, fn($a, $b) => strcmp($a->title, $b->title));

        $total = count($records);

        // Pagination
        if ($request->has('start') && $request->has('limit')) {
            $start = (int)$request->input('start');
            $limit = (int)$request->input('limit');

            $records = array_slice($records, $start, $limit);
        }

        if (!empty($records)) {
            return [
                'success' => 'true',
                'message' => 'Dispositions detail.',
                'total_rows' => $total,
                'data' => $records,
            ];
        }

        return [
            'success' => 'false',
            'message' => 'No dispositions found.',
            'total_rows' => 0,
            'data' => [],
        ];

    } catch (\Exception $e) {
        return [
            'success' => 'false',
            'message' => 'Something went wrong: ' . $e->getMessage(),
            'total_rows' => 0,
            'data' => [],
        ];
    }
}


    /*
     *Update disposition details
     *@param object $request
     *@return array
     */
    // public function dispositionUpdate($request)
    // {
    //     try
    //     {
    //         if($request->has('disposition_id') && is_numeric($request->input('disposition_id')))
    //         {
    //             $updateString = array();
    //             if($request->has('title') && !empty($request->input('title'))) {
    //                 array_push($updateString, 'title = :title');
    //                 $data['title'] = $request->input('title');
    //             }

    //             if($request->has('d_type') && !empty($request->input('d_type'))) {
    //                 array_push($updateString, 'd_type = :d_type');
    //                 $data['d_type'] = $request->input('d_type');
    //             }
    //             if($request->has('enable_sms') && is_numeric($request->input('enable_sms'))) {
    //                 array_push($updateString, 'enable_sms = :enable_sms');
    //                 $data['enable_sms'] = $request->input('enable_sms');
    //             }
    //              if($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
    //                  array_push($updateString, 'is_deleted = :is_deleted');
    //                  $data['is_deleted'] = $request->input('is_deleted');
    //              }
    //             if(!empty($updateString) && !empty($data))
    //             {
    //                 $data['id'] = $request->input('disposition_id');
    //                 $query = "UPDATE disposition set ".implode(" , ", $updateString)." WHERE id = :id";
    //                 $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
    //                 if($save == 1)
    //                 {
    //                     return array(
    //                         'success'=> 'true',
    //                         'message'=> 'Dispositions updated successfully.'
    //                     );
    //                 }
    //                 else
    //                 {
    //                     return array(
    //                         'success'=> 'false',
    //                         'message'=> 'Dispositions are not updated successfully.'
    //                     );
    //                 }
    //             }

    //         }
    //         return array(
    //             'success'=> 'false',
    //             'message'=> 'Dispositions doesn\'t exist.'
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
    public function dispositionUpdateold($request)
{
    try {

        if (!$request->has('disposition_id') || !is_numeric($request->input('disposition_id'))) {
            return [
                'success' => 'false',
                'message' => "Disposition doesn't exist."
            ];
        }

        $dispositionId = $request->input('disposition_id');
        $clientDb = 'mysql_' . $request->auth->parent_id;

        $updateString = [];
        $data = [];

        // Title
        if ($request->has('title') && !empty($request->input('title'))) {
            $updateString[] = 'title = :title';
            $data['title'] = $request->input('title');
        }

        // Type
        if ($request->has('d_type') && !empty($request->input('d_type'))) {
            $updateString[] = 'd_type = :d_type';
            $data['d_type'] = $request->input('d_type');
        }

        // Enable SMS
        if ($request->has('enable_sms') && is_numeric($request->input('enable_sms'))) {
            $updateString[] = 'enable_sms = :enable_sms';
            $data['enable_sms'] = $request->input('enable_sms');
        }

        // Is Deleted (Soft Delete)
        $isDeleting = false;
        if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
            $updateString[] = 'is_deleted = :is_deleted';
            $data['is_deleted'] = $request->input('is_deleted');

            if ($request->input('is_deleted') == 1) {
                $isDeleting = true;   // mark for campaign_disposition deletion also
            }
                // 🔴 CHECK assignment BEFORE delete
            if ($request->input('is_deleted') == 1) {

                $assigned = DB::connection($clientDb)
                    ->table('campaign_disposition')
                    ->where('disposition_id', $dispositionId)
                    ->where('is_deleted', 0)
                    ->exists();

                if (!$assigned) {
                    return [
                        'success' => 'false',
                        'message' => 'Disposition is not assigned to any campaign.'
                    ];
                }

                $isDeleting = true;
            }

            $updateString[] = 'is_deleted = :is_deleted';
            $data['is_deleted'] = $request->input('is_deleted');
        }

        if (empty($updateString)) {
            return [
                'success' => 'false',
                'message' => 'Nothing to update.'
            ];
        }

        // Final update
        $data['id'] = $dispositionId;
        $query = "UPDATE disposition SET " . implode(", ", $updateString) . " WHERE id = :id";

        $save = DB::connection($clientDb)->update($query, $data);

        // ❗ If deleted → update campaign_disposition also
        if ($isDeleting) {
            DB::connection($clientDb)->update(
                "UPDATE campaign_disposition SET is_deleted = 1 WHERE disposition_id = ?",
                [$dispositionId]
            );
        }

        if ($save == 1) {
            return [
                'success' => 'true',
                'message' => 'Disposition updated successfully.'
            ];
        }

        return [
            'success' => 'false',
            'message' => 'Disposition was not updated.'
        ];

    } catch (\Exception $e) {

        return [
            'success' => 'false',
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}
public function dispositionUpdate($request)
{
    try {

        if (!$request->has('disposition_id') || !is_numeric($request->input('disposition_id'))) {
            return [
                'success' => 'false',
                'message' => "Disposition doesn't exist."
            ];
        }
        // Title (must be unique)
     

        $dispositionId = $request->input('disposition_id');
        $clientDb = 'mysql_' . $request->auth->parent_id;

        $updateString = [];
        $data = [];

        // Title
        // if ($request->has('title') && !empty($request->input('title'))) {
        //     $updateString[] = 'title = :title';
        //     $data['title'] = $request->input('title');
        // }
           if ($request->has('title') && !empty($request->input('title'))) {

            $exists = DB::connection($clientDb)
                ->table('disposition')
                ->where('title', $request->input('title'))
                ->where('id', '!=', $dispositionId)
                ->where('is_deleted', 0)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => "Disposition title already exists."
                ], 409);
            }

            $updateString[] = 'title = :title';
            $data['title'] = $request->input('title');
        }

        // Type
        if ($request->has('d_type') && !empty($request->input('d_type'))) {
            $updateString[] = 'd_type = :d_type';
            $data['d_type'] = $request->input('d_type');
        }

        // Enable SMS
        if ($request->has('enable_sms') && is_numeric($request->input('enable_sms'))) {
            $updateString[] = 'enable_sms = :enable_sms';
            $data['enable_sms'] = $request->input('enable_sms');
        }

        // Is Deleted (Soft Delete)
        $isDeleting = false;
        if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {

            // 🔴 CHECK assignment BEFORE delete
            if ($request->input('is_deleted') == 1) {

                $assigned = DB::connection($clientDb)
                    ->table('campaign_disposition')
                    ->where('disposition_id', $dispositionId)
                    ->where('is_deleted', 0)
                    ->exists();

                   if ($assigned) {
                        return [
                            'success' => 'false',
                            'message' => 'Disposition is assigned to a campaign and cannot be deleted.'
                        ];
                    }

                $isDeleting = true;
            }

            $updateString[] = 'is_deleted = :is_deleted';
            $data['is_deleted'] = $request->input('is_deleted');
        }

        if (empty($updateString)) {
            return [
                'success' => 'false',
                'message' => 'Nothing to update.'
            ];
        }

        // Final update
        $data['id'] = $dispositionId;
        $query = "UPDATE disposition SET " . implode(", ", $updateString) . " WHERE id = :id";

        $save = DB::connection($clientDb)->update($query, $data);

        // 🔁 If deleted → update campaign_disposition also
        if ($isDeleting) {
            DB::connection($clientDb)->update(
                "UPDATE campaign_disposition SET is_deleted = 1 WHERE disposition_id = ?",
                [$dispositionId]
            );
        }

        if ($save == 1) {
            return [
                'success' => 'true',
                'message' => 'Disposition updated successfully.'
            ];
        }

        return [
            'success' => 'false',
            'message' => 'Disposition was not updated.'
        ];

    } catch (\Exception $e) {
        return [
            'success' => 'false',
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

    /*
     *Add disposition details
     *@param object $request
     *@return array
     */
    public function addDisposition($request)
    {
        try
        {
            if($request->has('title') && !empty($request->input('title'))) {

            $exists = DB::connection('mysql_'.$request->auth->parent_id)
                ->table('disposition')
                ->where('title', $request->input('title'))
                ->exists();

            if ($exists) {
             
                return response()->json([
                    'success' => false,
                    'message' => "Disposition title already exists."
                ], 409);

            }
                $data['title'] = $request->input('title');
                $data['d_type'] = $request->input('d_type');
                $data['enable_sms'] = $request->input('enable_sms');
                $query = "INSERT INTO disposition (title,d_type,enable_sms) VALUE (:title,:d_type,:enable_sms)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->insert($query, $data);
                if($add == 1)
                {
                    $newAdd =  DB::connection('mysql_'.$request->auth->parent_id)->selectOne("SELECT * FROM disposition ORDER BY id DESC ", array());
                    $newAdd = (array)$newAdd;
                    return array(
                        'success'=> 'true',
                        'message'=> 'Dispositions added successfully.',
                        'data'   => $newAdd
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Dispositions are not added successfully.'
                    );
                }
            }

            return array(
                'success'=> 'false',
                'message'=> 'Dispositions are not added successfully.'
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }
    /*
     *Update Campaign Disposition details
     *@param object $request
     *@return array
     */
    public function editCampaignDisposition($request)
    {
        try
        {
            if($request->has('campaign_id') && is_numeric($request->input('campaign_id')))
            {
                $campaignId = $request->has('campaign_id');
                $sql = "UPDATE campaign_disposition SET is_deleted = :is_deleted WHERE campaign_id = :campaign_id";
                DB::connection('mysql_'.$request->auth->parent_id)->update($sql, array('is_deleted' => 1, 'campaign_id' => $campaignId));
                if($request->has('disposition_id') && is_array($request->input('disposition_id')))
                {
                    foreach ($request->input('disposition_id') as $value)
                    {
                        $sql = "INSERT INTO campaign_disposition (campaign_id, disposition_id) VALUE (:campaign_id, :disposition_id) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
                        DB::connection('mysql_'.$request->auth->parent_id)->insert($sql, array('is_deleted' => 0, 'campaign_id' => $campaignId, 'disposition_id' => $value));
                    }
                }
                return array(
                    'success'=> 'true',
                    'message'=> 'Campaign dispositions updated successfully.',
                    'data'   => array()
                );
            }
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }
    /*
     *Campaign Disposition details
     *@param object $request
     *@return array
     */
    public function getCampaignDisposition($request)
    {
        try
        {
            $searchStr = array('campaign_disposition.is_deleted = :is_deleted');
            $data['is_deleted'] = 0;
            if($request->has('campaign_id') && is_numeric($request->input('campaign_id')))
            {
                array_push($searchStr, 'campaign_disposition.campaign_id = :campaign_id');
                $data['campaign_id'] = $request->input('campaign_id');

                //$sql = "SELECT * FROM campaign_disposition  WHERE ".implode(" AND ", $searchStr);
                $sql = "SELECT campaign_disposition.*,disposition.title FROM campaign_disposition inner join disposition on disposition.id = campaign_disposition.disposition_id WHERE ".implode(" AND ", $searchStr);
                $record =  DB::connection('mysql_'.$request->auth->parent_id)->select($sql, $data);
                $data = (array)$record;
                if(!empty($data))
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Dispositions detail.',
                        'data'   => $data
                    );
                }
                return array(
                    'success'=> 'false',
                    'message'=> 'Campaign is not associated to any dispositions.',
                    'data'   => array()
                );
            }
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }
    function updateDispositionStatus($request) 
    {
        $listId = $request->input('listId');
        $status = $request->input('status');
        $clientDb = 'mysql_' . $request->auth->parent_id;

        // 🔴 Block status change if disposition is assigned to any campaign
        $assigned = DB::connection($clientDb)
            ->table('campaign_disposition')
            ->where('disposition_id', $listId)
            ->where('is_deleted', 0)
            ->exists();

       if ($assigned && (int)$status === 0) {
    return [
        'success' => 'false',
        'message' => 'Disposition is assigned to a campaign and you can’t disable it.'
    ];
}

        $saveRecord =     
        DB::connection('mysql_' . $request->auth->parent_id)
        ->table('disposition')
        ->where('id', $listId)
        ->update(['status' => $status]);
        if ($saveRecord > 0) {
            return array(
                            'success'=>'true',
                'message' => 'Disposition status updated successfully'
                        );
        } else {
            return array(
                            'status' => 'false',
                'message' => 'Status  update failed'
                        );
                }
    }
}
