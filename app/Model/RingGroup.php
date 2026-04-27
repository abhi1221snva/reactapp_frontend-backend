<?php

namespace App\Model;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Model\User;
use App\Model\Master\Client;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

class RingGroup extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'ring_group';
    /*
     *Fetch dnc list
     *@param integer $id
     *@return array
     */

public function ringGroupDetailold($request)
{
    try {
        $data = [];
        $searchConditions = [];

        // If `ring_id` is passed, filter by it
        if ($request->has('ring_id') && is_numeric($request->input('ring_id'))) {
            $searchConditions[] = 'id = :id';
            $data['id'] = $request->input('ring_id');
        }

        // If `search` is passed, add search filter (on name column as example)
        if ($request->filled('search')) {
            $searchConditions[] = 'title LIKE :search';
            $data['search'] = '%' . $request->input('search') . '%';
        }

        $tableName = "`" . $this->table . "`";
        $whereClause = !empty($searchConditions) ? " WHERE " . implode(" AND ", $searchConditions) : '';

        // Count query
        $sqlCount = "SELECT count(*) as rowCount FROM $tableName $whereClause";
        $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->select($sqlCount, $data);
        $recCount = $recordCount[0]->rowCount;

        if ($recCount == 0) {
            return [
                'success' => true,
                'message' => 'Record not found.',
                'data'    => [],
                'total'   => 0
            ];
        }

        // Fetch records
        $sql = "SELECT * FROM $tableName $whereClause";
        $records = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
        $ringGroupsData = (array) $records;

        // Extension processing
        foreach ($ringGroupsData as $key_ext => $ext) {
            $array_extension = [];
            $exten = str_replace(['PJSIP/', 'SIP/'], '', $ext->extensions ?? '');
            $replace = str_replace('-', '&', $exten);
            $extension = array_filter(array_unique(explode('&', $replace)));

            foreach ($extension as $check) {
                if (!empty($check) && is_numeric($check)) {
                    $userSql = "SELECT * FROM users WHERE extension = :ext1 OR alt_extension = :ext2 LIMIT 1";
                    $userRecord = DB::connection('master')->selectOne($userSql, [
                        'ext1' => $check,
                        'ext2' => $check
                    ]);

                    if (!empty($userRecord)) {
                        $array_extension[] = $userRecord->first_name . ' ' . $userRecord->last_name . '-' . $check;
                    }
                }
            }

            $ringGroupsData[$key_ext]->extension_name = implode(',', $array_extension);
        }

        // Manual pagination using array_slice
        $start = (int) $request->input('start', 0);
        $limit = (int) $request->input('limit', 10);
        $paginatedData = array_slice($ringGroupsData, $start, $limit);

        return [
            'success' => true,
            'message' => 'Ring Group detail.',
            'data'    => $paginatedData,
            'total'   => $recCount,
            'start'   => $start,
            'limit'   => $limit
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => 'Oops! Something failed.',
            'errors'  => [$e->getMessage()]
        ];
    }
}
// public function ringGroupDetail($request)
// {
//     try {
//         $data = [];
//         $searchConditions = [];

//         // Filter by ring_id if passed
//         if ($request->has('ring_id') && is_numeric($request->input('ring_id'))) {
//             $searchConditions[] = 'id = :id';
//             $data['id'] = $request->input('ring_id');
//         }

//         // Filter by search term if passed
//         if ($request->filled('search')) {
//             $searchConditions[] = 'title LIKE :search';
//             $data['search'] = '%' . $request->input('search') . '%';
//         }

//         $tableName = "`" . $this->table . "`";
//         $whereClause = !empty($searchConditions) ? " WHERE " . implode(" AND ", $searchConditions) : '';

//         // Count query
//         $sqlCount = "SELECT COUNT(*) as rowCount FROM $tableName $whereClause";
//         $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->select($sqlCount, $data);
//         $recCount = $recordCount[0]->rowCount;

//         if ($recCount == 0) {
//             return [
//                 'success' => true,
//                 'message' => 'Record not found.',
//                 'data'    => [],
//                 'total'   => 0
//             ];
//         }

//         // Handle pagination only if both start and limit are provided
//         $usePagination = $request->has('start') && $request->has('limit');

//         if ($usePagination) {
//             $start = (int) $request->input('start');
//             $limit = (int) $request->input('limit');
//             $data['limit'] = $limit;
//             $data['offset'] = $start;

//             $sql = "SELECT * FROM $tableName $whereClause LIMIT :limit OFFSET :offset";
//         } else {
//             $sql = "SELECT * FROM $tableName $whereClause";
//             $start = 0;
//             $limit = $recCount;
//         }

//         // Fetch data
//         $records = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
//         $ringGroupsData = (array) $records;

//         // Process extensions
//         foreach ($ringGroupsData as $key_ext => $ext) {
//             $array_extension = [];
//             $exten = str_replace(['PJSIP/', 'SIP/'], '', $ext->extensions ?? '');
//             $replace = str_replace('-', '&', $exten);
//             $extension = array_filter(array_unique(explode('&', $replace)));

//             foreach ($extension as $check) {
//                 if (!empty($check) && is_numeric($check)) {
//                     $userSql = "SELECT * FROM users WHERE extension = :ext1 OR alt_extension = :ext2 LIMIT 1";
//                     $userRecord = DB::connection('master')->selectOne($userSql, [
//                         'ext1' => $check,
//                         'ext2' => $check
//                     ]);

//                     if (!empty($userRecord)) {
//                         $array_extension[] = $userRecord->first_name . ' ' . $userRecord->last_name . '-' . $check;
//                     }
//                 }
//             }

//             $ringGroupsData[$key_ext]->extension_name = implode(',', $array_extension);
//         }

//         return [
//             'success' => true,
//             'message' => 'Ring Group detail.',
//             'data'    => $ringGroupsData,
//             'total'   => $recCount,
//             'start'   => $start,
//             'limit'   => $limit
//         ];

//     } catch (\Exception $e) {
//         return [
//             'success' => false,
//             'message' => 'Oops! Something failed.',
//             'errors'  => [$e->getMessage()]
//         ];
//     }
// }
public function ringGroupDetail($request)
{
    try {
        $data = [];
        $searchConditions = [];

        // Filter by ring_id if passed
        if ($request->has('ring_id') && is_numeric($request->input('ring_id'))) {
            $searchConditions[] = 'id = :id';
            $data['id'] = $request->input('ring_id');
        }

        // Filter by search term if passed
        if ($request->filled('search')) {
            $searchConditions[] = '(title LIKE :search1 OR description LIKE :search2)';
            $data['search1'] = '%' . $request->input('search') . '%';
            $data['search2'] = '%' . $request->input('search') . '%';
        }

        $tableName = "`" . $this->table . "`";
        $whereClause = !empty($searchConditions) ? " WHERE " . implode(" AND ", $searchConditions) : '';

        // Count query
        $sqlCount = "SELECT COUNT(*) as rowCount FROM $tableName $whereClause";
        $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->select($sqlCount, $data);
        $recCount = $recordCount[0]->rowCount ?? 0;

        if ($recCount == 0) {
            return [
                'success' => true,
                'message' => 'Record not found.',
                'data'    => [],
                'total'   => 0
            ];
        }

        // Handle pagination
        $usePagination = $request->has('start') && $request->has('limit');

        if ($usePagination) {
            $start = (int) $request->input('start');
            $limit = (int) $request->input('limit');
            $data['limit'] = $limit;
            $data['offset'] = $start;

            $sql = "SELECT * FROM $tableName $whereClause LIMIT :limit OFFSET :offset";
        } else {
            $sql = "SELECT * FROM $tableName $whereClause";
            $start = 0;
            $limit = $recCount;
        }

        // ✅ Fetch as array of objects (keep objects intact)
        $ringGroupsData = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);

        // Collect all unique extension numbers across all ring groups for batch lookup
        $allExtNums = [];
        $ringExtMap = []; // key_ext => [ext numbers]

        foreach ($ringGroupsData as $key_ext => $ext) {
            $extenRaw = trim($ext->extensions ?? '');
            $extenRaw = str_replace(['PJSIP/', 'SIP/'], '', $extenRaw);
            $extenRaw = str_replace(['-', ',', ' '], '&', $extenRaw);
            $extensionList = array_values(array_filter(array_unique(explode('&', $extenRaw))));
            $ringExtMap[$key_ext] = $extensionList;
            foreach ($extensionList as $check) {
                if (!empty($check) && is_numeric($check)) {
                    $allExtNums[$check] = true;
                }
            }
        }

        // Batch query: fetch all users matching any of these extension numbers (with tenant filter)
        $userLookup = []; // extension_number => user object
        if (!empty($allExtNums)) {
            $extNumList = array_keys($allExtNums);
            $placeholders = implode(',', array_fill(0, count($extNumList), '?'));
            $tenantId = $request->auth->parent_id;
            $userSql = "SELECT id, first_name, last_name, extension, alt_extension
                        FROM users
                        WHERE parent_id = ? AND (extension IN ($placeholders) OR alt_extension IN ($placeholders))";
            $bindings = array_merge([$tenantId], $extNumList, $extNumList);
            $userRecords = DB::connection('master')->select($userSql, $bindings);

            foreach ($userRecords as $ur) {
                if (!empty($ur->extension)) $userLookup[$ur->extension] = $ur;
                if (!empty($ur->alt_extension)) $userLookup[$ur->alt_extension] = $ur;
            }
        }

        foreach ($ringGroupsData as $key_ext => $ext) {
            $array_extension = [];
            $extension_ids = [];
            $extensionList = $ringExtMap[$key_ext] ?? [];

            foreach ($extensionList as $check) {
                if (!empty($check) && is_numeric($check) && isset($userLookup[$check])) {
                    $userRecord = $userLookup[$check];
                    $matchedExt = ($userRecord->extension == $check)
                        ? $userRecord->extension
                        : $userRecord->alt_extension;

                    if (!in_array($userRecord->id, $extension_ids)) {
                        $array_extension[] = "{$userRecord->first_name} {$userRecord->last_name}-{$matchedExt}";
                        $extension_ids[] = $userRecord->id;
                    }
                }
            }

            $ringGroupsData[$key_ext]->extension_name = implode(',', $array_extension);
            $ringGroupsData[$key_ext]->extension_id = $extension_ids;
            $ringGroupsData[$key_ext]->extension_count = count($extension_ids);
        }

        return [
            'success' => true,
            'message' => 'Ring Group detail.',
            'data'    => $ringGroupsData,
            'total'   => $recCount,
            'start'   => $start,
            'limit'   => $limit
        ];

    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => 'Oops! Something failed.',
            'errors'  => [$e->getMessage()]
        ];
    }
}


    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    // public function ringGroupUpdate($request)
    // {


    //     try
    //     {

    //          $updateString = array();

    //           $data['id'] = $request->input('ring_id');


    //           if($request->has('description') && $request->input('description')) {
    //                 array_push($updateString, 'description = :description');
    //                 $data['description'] = $request->input('description');
    //             }



    //         if(is_array($request->input('extension'))){
    //             $count = 0;
    //             foreach ($request->input('extension') as $key=>$value)
    //             {
    //             ++$count;

    //                 $user_data['alt_extension'] = User::where('extension',$value)->get()->first();
    //                 $ext[] = 'SIP/'.$value.'&'.'SIP/'.$user_data['alt_extension']->alt_extension;
    //                 //$ext[] = 'SIP/'.$user_data['alt_extension']->alt_extension;

    //                 //phone number 

    //                 $client = Client::where('id',$request->auth->parent_id)->get()->first();
    //                 if(!empty($client))
    //                 {
    //                     $tech_prefix = $client->tech_prefix;
    //                     $user_data['mobile'] = User::where('extension',$value)->get()->first();
    //                     $ext_phone[] = 'PJSIP/telnyx/'.$tech_prefix.$user_data['mobile']->mobile;
    //                 }
    //                 else
    //                 {
    //                     $user_data['mobile'] = User::where('extension',$value)->get()->first();
    //                     $ext_phone[] = 'SIP/telnyx/'.$user_data['mobile']->mobile;
    //                 }

                    
    //             }

    //             //return $ext;


    //             if($request->input('ring_type') == 1)
    //             {
    //             $extension = implode('&',$ext);

    //             }
    //             else
    //             {
    //             $extension = implode('-',$ext);

    //             }
    //             //echo "<pre>";print_r($extension);die;


    //             array_push($updateString, 'extensions = :extensions');
    //                 $data['extensions'] = $extension;


    //             $extension_phone = implode('&',$ext_phone);
    //             //echo "<pre>";print_r($extension);die;


    //             array_push($updateString, 'phone_number = :phone_number');
    //                 $data['phone_number'] = $extension_phone;
    //         }


    //         if(is_array($request->input('emails'))){
    //             foreach ($request->input('emails') as $key=>$value)
    //             {
    //                 $emails_list[] = $value;
    //             }
    //             $emails = implode(',',$emails_list);
    //             //echo "<pre>";print_r($extension);die;


    //             array_push($updateString, 'emails = :emails');
    //                 $data['emails'] = $emails;
    //         }

    //         if($request->has('title') && !empty($request->input('title'))) {
    //              array_push($updateString, 'title = :title');
    //                 $data['title'] = $request->input('title');
               
    //            // $data['id'] = $request->ring_id;
    //         }

    //          if($request->has('ring_type') && !empty($request->input('ring_type'))) {
    //              array_push($updateString, 'ring_type = :ring_type');
    //                 $data['ring_type'] = $request->input('ring_type');
               
    //            // $data['id'] = $request->ring_id;
    //         }

    //         if($request->has('receive_on') && !empty($request->input('receive_on'))) {
    //              array_push($updateString, 'receive_on = :receive_on');
    //                 $data['receive_on'] = $request->input('receive_on');
               
    //            // $data['id'] = $request->ring_id;
    //         }

    //           array_push($updateString, 'extension_count = :extension_count');
    //                 $data['extension_count'] = $count;

           

    //         //echo $request->ring_id;die;

    //               //  return $data;


    //             //echo "<pre>";print_r($data);die;

               
    //               $query = "UPDATE ".$this->table." set ".implode(" , ", $updateString)." WHERE id = :id";
    //                 $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
    //                 Log::info('reached',['save'=>$save]);
    //                 if($save == 1)
    //                 {
    //                     return array(
    //                         'success'=> 'true',
    //                         'message'=> 'Ring Group updated successfully.'
    //                     );
    //                 }
    //                 else
    //                 {
    //                     return array(
    //                         'success'=> 'false',
    //                         'message'=> 'Ring Group are not updated successfully.'
    //                     );
    //                 }
    //         }

           
    //     catch (Exception $e)
    //     {
    //         Log::log($e->getMessage());
    //     }
    //     catch (InvalidArgumentException $e)
    //     {
    //         Log::log($e->getMessage());
    //     }
    // }
    public function ringGroupUpdate($request)
{
    try {
        $updateString = [];
        $data = [];

        // ✅ Validate ring_id
        $ringId = $request->input('ring_id');
        if (empty($ringId) || !is_numeric($ringId)) {
            return [
                'success' => 'false',
                'message' => 'Invalid or missing ring group ID.'
            ];
        }

        // Check if ring group exists
        $existingRing = DB::connection('mysql_' . $request->auth->parent_id)
            ->table($this->table)
            ->where('id', $ringId)
            ->first();

        if (!$existingRing) {
            return [
                'success' => 'false',
                'message' => 'Ring group not found.'
            ];
        }

        $data['id'] = $ringId;
        $count = 0; // keep track of extensions count

        // ✅ Description
        if ($request->has('description') && $request->input('description')) {
            $updateString[] = 'description = :description';
            $data['description'] = $request->input('description');
        }

        // ✅ Extensions validation and processing
        if (is_array($request->input('extension')) && !empty($request->input('extension'))) {
            $ext = [];
            $ext_phone = [];

            foreach ($request->input('extension') as $value) {
                ++$count;

                $user = User::where('extension', $value)
                    ->where('parent_id', $request->auth->parent_id)
                    ->first();

                if (!$user) {
                    return [
                        'success' => 'false',
                        'message' => "Extension {$value} not found."
                    ];
                }

                // Get alt extension if exists
                $altExtension = $user->alt_extension ?? null;
                if (!$altExtension) {
                    return [
                        'success' => 'false',
                        'message' => "Alt extension not found for extension {$value}."
                    ];
                }

                $ext[] = 'PJSIP/' . $value . '&PJSIP/' . $altExtension;

                // Fetch client for tech prefix
                $client = Client::find($request->auth->parent_id);
                $tech_prefix = $client ? $client->tech_prefix : '';

                // Add phone format (skip if no mobile, matching addRingGroup behavior)
                if (!empty($user->mobile)) {
                    $ext_phone[] = 'PJSIP/telnyx/' . $tech_prefix . $user->mobile;
                }
            }

            // Combine based on ring type
            $ringType = $request->input('ring_type', 1);
            $extension = $ringType == 1 ? implode('&', $ext) : implode('-', $ext);

            $updateString[] = 'extensions = :extensions';
            $data['extensions'] = $extension;

            $extension_phone = implode('&', $ext_phone);
            $updateString[] = 'phone_number = :phone_number';
            $data['phone_number'] = $extension_phone;

            $updateString[] = 'extension_count = :extension_count';
            $data['extension_count'] = $count;
        } else {
            return [
                'success' => 'false',
                'message' => 'No extensions provided or invalid format.'
            ];
        }

        // ✅ Emails
        if (is_array($request->input('emails')) && !empty($request->input('emails'))) {
            $emails = implode(',', $request->input('emails'));
            $updateString[] = 'emails = :emails';
            $data['emails'] = $emails;
        } else {
            return [
                'success' => 'false',
                'message' => 'No valid emails provided.'
            ];
        }

        // ✅ Title
        if ($request->has('title') && !empty($request->input('title'))) {
            $updateString[] = 'title = :title';
            $data['title'] = $request->input('title');
        }

        // ✅ Ring Type
        if ($request->has('ring_type') && !empty($request->input('ring_type'))) {
            $updateString[] = 'ring_type = :ring_type';
            $data['ring_type'] = $request->input('ring_type');
        }

        // ✅ Receive On
        if ($request->has('receive_on') && !empty($request->input('receive_on'))) {
            $updateString[] = 'receive_on = :receive_on';
            $data['receive_on'] = $request->input('receive_on');
        }

        // ✅ Update query
        $query = "UPDATE " . $this->table . " SET " . implode(', ', $updateString) . " WHERE id = :id";
        $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

        if ($save == 1) {
            return [
                'success' => 'true',
                'message' => 'Ring Group updated successfully.'
            ];
        } else {
            return [
                'success' => 'false',
                'message' => 'No changes made or update failed.'
            ];
        }
    } catch (\Throwable $e) {
        Log::error("RingGroupUpdate.error", [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return [
            'success' => 'false',
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

    /*
     *Add dnc details
     *@param object $request
     *@return array
     */
    public function addRingGroup($request)
    {
        try
        {
            // Validate title is provided
            if (!$request->has('title') || empty(trim($request->input('title')))) {
                return [
                    'success' => 'false',
                    'message' => 'Title is required.'
                ];
            }

            // Validate ring_type
            $ringType = $request->input('ring_type', 1);
            if (!in_array($ringType, [1, 2, 3])) {
                return [
                    'success' => 'false',
                    'message' => 'Invalid ring type. Must be 1, 2, or 3.'
                ];
            }

            $extension = '';
            $extension_mobile = '';
            $count = 0;

            if (is_array($request->input('extension')) && !empty($request->input('extension'))) {
                $ext = [];
                $ext_phone = [];

                $client = Client::where('id', $request->auth->parent_id)->first();
                $tech_prefix = !empty($client) ? $client->tech_prefix : '';

                foreach ($request->input('extension') as $value)
                {
                    $user = User::where('extension', $value)
                        ->where('parent_id', $request->auth->parent_id)
                        ->first();

                    if (!$user) {
                        return [
                            'success' => 'false',
                            'message' => "Extension {$value} not found."
                        ];
                    }

                    if (empty($user->alt_extension)) {
                        return [
                            'success' => 'false',
                            'message' => "Alt extension not found for extension {$value}."
                        ];
                    }

                    ++$count;
                    $ext[] = 'PJSIP/' . $value . '&PJSIP/' . $user->alt_extension;

                    if (!empty($user->mobile)) {
                        $ext_phone[] = 'PJSIP/telnyx/' . $tech_prefix . $user->mobile;
                    }
                }

                $extension_mobile = implode('&', $ext_phone);

                if ($ringType == 1) {
                    $extension = implode('&', $ext);
                } else {
                    $extension = implode('-', $ext);
                }
            } else {
                return [
                    'success' => 'false',
                    'message' => 'At least one extension is required.'
                ];
            }

            $emails = '';
            if (is_array($request->input('emails'))) {
                $email_list = [];
                foreach ($request->input('emails') as $value) {
                    if (!empty(trim($value))) {
                        $email_list[] = trim($value);
                    }
                }
                $emails = implode(',', $email_list);
            }

            $data = [];
            $data['title'] = trim($request->input('title'));
            $data['description'] = $request->input('description', '');
            $data['extensions'] = $extension;
            $data['phone_number'] = $extension_mobile;
            $data['emails'] = $emails;
            $data['ring_type'] = $ringType;
            $data['receive_on'] = $request->input('receive_on', 'web_phone');
            $data['extension_count'] = $count;

            $query = "INSERT INTO " . $this->table . " (title, description, extensions, phone_number, emails, ring_type, extension_count, receive_on) VALUE (:title, :description, :extensions, :phone_number, :emails, :ring_type, :extension_count, :receive_on)";
            $add = DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);

            if ($add == 1) {
                return [
                    'success' => 'true',
                    'message' => 'Ring Group added successfully.'
                ];
            }

            return [
                'success' => 'false',
                'message' => 'Ring Group not added successfully.'
            ];
        }
        catch (\Exception $e)
        {
            Log::error('RingGroup.addRingGroup error', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return [
                'success' => 'false',
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function ringDelete($request)
    {
        try
        {
            if (!$request->has('ring_id') || !is_numeric($request->input('ring_id'))) {
                return [
                    'success' => 'false',
                    'message' => 'Ring Group doesn\'t exist.'
                ];
            }

            $data['id'] = $request->input('ring_id');
            $query = "DELETE FROM " . $this->table . " WHERE id = :id";
            $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

            if ($save == 1) {
                return [
                    'success' => 'true',
                    'message' => 'Ring Group deleted successfully.'
                ];
            }

            return [
                'success' => 'false',
                'message' => 'Ring Group not found or already deleted.'
            ];
        }
        catch (\Exception $e)
        {
            Log::error('RingGroup.ringDelete error', ['message' => $e->getMessage()]);
            return [
                'success' => 'false',
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

}
