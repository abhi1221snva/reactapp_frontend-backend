<?php

namespace App\Model;

use App\Http\Helper\Log;
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
        try
        {
            $data = array();
            $searchStr = array();
            if($request->has('ring_id') && is_numeric($request->input('ring_id')))
            {
                array_push($searchStr, 'id = :id');
                $data['id'] = $request->input('ring_id');
            }
           
            $str = !empty($searchStr) ? "  WHERE ".implode(" AND ", $searchStr) : '';

	    $sql_data = "SELECT count(*) as rowCount FROM ".$this->table.$str;
            $recordCount =  DB::connection('mysql_'.$request->auth->parent_id)->select($sql_data, $data);
	    $dataCount = (array)$recordCount;
            $recCount = $recordCount[0]->rowCount;
	    if($recCount==0){
		return array(
                    'success'=> 'true',
                    'message'=> 'Record not found.',
                    'data'   => array()
                );

	    }

            $sql = "SELECT * FROM ".$this->table.$str;
            $record =  DB::connection('mysql_'.$request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;

            foreach($data as $key_ext => $ext)
            {
                $array_extension =array();
                $exten = str_replace('SIP/','', $ext->extensions);

                $replace = str_replace('-','&',$exten);
                $extension = array_unique(explode('&',$replace));
                foreach($extension as $key=> $check)
                {
                    $sql = "SELECT * FROM users where extension=".$check."";
                    $record = DB::connection('master')->selectOne($sql,array());  
                    $recordList = $record;
                    if(!empty($recordList))
                    {
                        $array_extension[] = $recordList->first_name.' '.$recordList->last_name.'-'.$check;
                    }
                    else
                    {
                        $sql_alt = "SELECT * FROM users where extension=".$check."";
                        $record_alt = DB::connection('master')->selectOne($sql_alt,array());  
                        $recordListAlt = $record_alt;
                        if(!empty($recordListAlt))
                    {
                        $array_extension[] = $recordListAlt->first_name.' '.$recordListAlt->last_name.'-'.$check;
                    }
                    }
                }
                $data[$key_ext]->extension_name = implode(',',$array_extension);
            }

            if(!empty($data))
            {
                return array(
                    'success'=> 'true',
                    'message'=> 'Ring Group detail.',
                    'data'   => $data
                );
            }
            return array(
                'success'=> 'false',
                'message'=> 'Ring Group not created.',
                'data'   => array()
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
// public function ringGroupDetail($request)
//     {
//         try
//         {
//             $data = array();
//             $searchConditions = []; // Renamed for clarity, holds the SQL WHERE clauses
            
//             // Ensure $this->table is correctly defined in your class:
//             // protected $table = 'ring_group'; // Or your actual table name

//             if($request->has('ring_id') && is_numeric($request->input('ring_id')))
//             {
//                 // Ensure the placeholder is consistently :id (named parameter)
//                 $searchConditions[] = 'id = :id'; // Using [] for array push is cleaner
//                 $data['id'] = $request->input('ring_id');
//             }
            
//             // Add backticks around the table name for safety
//             $tableName = "`".$this->table."`";

//             $str = !empty($searchConditions) ? " WHERE ".implode(" AND ", $searchConditions) : '';

//             // --- Apply the change here ---
//             $sql_data = "SELECT count(*) as rowCount FROM ".$tableName.$str;
//             $recordCount = DB::connection('mysql_'.$request->auth->parent_id)->select($sql_data, $data);
//             $recCount = $recordCount[0]->rowCount;

//             if($recCount == 0){
//                 return array(
//                     'success'=> 'true',
//                     'message'=> 'Record not found.',
//                     'data'   => array()
//                 );
//             }

//             // --- Apply the change here too ---
//             $sql = "SELECT * FROM ".$tableName.$str;
//             $record = DB::connection('mysql_'.$request->auth->parent_id)->select($sql, $data);
//             $ringGroupsData = (array)$record; // Renamed $data to $ringGroupsData

//             foreach($ringGroupsData as $key_ext => $ext)
//             {
//                 $array_extension = array();
//                 $exten = str_replace('SIP/','', $ext->extensions ?? '');
//                 $replace = str_replace('-','&',$exten);
//                 $extension = array_filter(array_unique(explode('&',$replace)));

//                 foreach($extension as $key=> $check)
//                 {
//                     if (!empty($check) && is_numeric($check)) { 
//                         // Using different named parameters for each instance in the OR clause
//                         $userSql = "SELECT * FROM users WHERE extension = :extension_num1 OR alt_extension = :extension_num2 LIMIT 1";
//                         $userRecord = DB::connection('master')->selectOne(
//                             $userSql, 
//                             [
//                                 'extension_num1' => $check,
//                                 'extension_num2' => $check
//                             ]
//                         );
                        
//                         if(!empty($userRecord))
//                         {
//                             $array_extension[] = $userRecord->first_name.' '.$userRecord->last_name.'-'.$check;
//                         }
//                     }
//                 }
//                 $ringGroupsData[$key_ext]->extension_name = implode(',',$array_extension);
//             }
//              // Apply pagination if present
//         if ($request->has(['start', 'limit'])) {
//             $start = (int)$request->input('start');
//             $limit = (int)$request->input('limit');
//             $ringGroupsData = array_slice($ringGroupsData, $start, $limit, true); // paginate array
//         }
//             if(!empty($ringGroupsData))
//             {
//                 return array(
//                     'success'=> 'true',
//                     'message'=> 'Ring Group detail.',
//                     'data'=> $ringGroupsData
//                 );
//             }
//             return array(
//                 'success'=> 'false',
//                 'message'=> 'Ring Group not created.',
//                 'data' => array()
//             );
//         }
//         catch (Exception $e)
//         {
//             Log::error("Error in ringGroupDetail: " . $e->getMessage(), ['exception' => $e]);
//             return [
//                 'success' => false, // Return actual boolean false
//                 'message' => 'Oops! Something failed.',
//                 'errors'  => [$e->getMessage()]
//             ];
//         }
//     }
public function ringGroupDetail($request)
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
            $exten = str_replace('SIP/', '', $ext->extensions ?? '');
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

    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function ringGroupUpdate($request)
    {


        try
        {

             $updateString = array();

              $data['id'] = $request->input('ring_id');


              if($request->has('description') && $request->input('description')) {
                    array_push($updateString, 'description = :description');
                    $data['description'] = $request->input('description');
                }



            if(is_array($request->input('extension'))){
                $count = 0;
                foreach ($request->input('extension') as $key=>$value)
                {
                ++$count;

                    $user_data['alt_extension'] = User::where('extension',$value)->get()->first();
                    $ext[] = 'SIP/'.$value.'&'.'SIP/'.$user_data['alt_extension']->alt_extension;
                    //$ext[] = 'SIP/'.$user_data['alt_extension']->alt_extension;

                    //phone number 

                    $client = Client::where('id',$request->auth->parent_id)->get()->first();
                    if(!empty($client))
                    {
                        $tech_prefix = $client->tech_prefix;
                        $user_data['mobile'] = User::where('extension',$value)->get()->first();
                        $ext_phone[] = 'SIP/telnyx/'.$tech_prefix.$user_data['mobile']->mobile;
                    }
                    else
                    {
                        $user_data['mobile'] = User::where('extension',$value)->get()->first();
                        $ext_phone[] = 'SIP/telnyx/'.$user_data['mobile']->mobile;
                    }

                    
                }

                //return $ext;


                if($request->input('ring_type') == 1)
                {
                $extension = implode('&',$ext);

                }
                else
                {
                $extension = implode('-',$ext);

                }
                //echo "<pre>";print_r($extension);die;


                array_push($updateString, 'extensions = :extensions');
                    $data['extensions'] = $extension;


                $extension_phone = implode('&',$ext_phone);
                //echo "<pre>";print_r($extension);die;


                array_push($updateString, 'phone_number = :phone_number');
                    $data['phone_number'] = $extension_phone;
            }


            if(is_array($request->input('emails'))){
                foreach ($request->input('emails') as $key=>$value)
                {
                    $emails_list[] = $value;
                }
                $emails = implode(',',$emails_list);
                //echo "<pre>";print_r($extension);die;


                array_push($updateString, 'emails = :emails');
                    $data['emails'] = $emails;
            }

            if($request->has('title') && !empty($request->input('title'))) {
                 array_push($updateString, 'title = :title');
                    $data['title'] = $request->input('title');
               
               // $data['id'] = $request->ring_id;
            }

             if($request->has('ring_type') && !empty($request->input('ring_type'))) {
                 array_push($updateString, 'ring_type = :ring_type');
                    $data['ring_type'] = $request->input('ring_type');
               
               // $data['id'] = $request->ring_id;
            }

            if($request->has('receive_on') && !empty($request->input('receive_on'))) {
                 array_push($updateString, 'receive_on = :receive_on');
                    $data['receive_on'] = $request->input('receive_on');
               
               // $data['id'] = $request->ring_id;
            }

              array_push($updateString, 'extension_count = :extension_count');
                    $data['extension_count'] = $count;

           

            //echo $request->ring_id;die;

                  //  return $data;


                //echo "<pre>";print_r($data);die;

               
                  $query = "UPDATE ".$this->table." set ".implode(" , ", $updateString)." WHERE id = :id";
                    $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                    if($save == 1)
                    {
                        return array(
                            'success'=> 'true',
                            'message'=> 'Ring Group updated successfully.'
                        );
                    }
                    else
                    {
                        return array(
                            'success'=> 'false',
                            'message'=> 'Ring Group are not updated successfully.'
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
     *Add dnc details
     *@param object $request
     *@return array
     */
    public function addRingGroup($request)
    {
        try
        {

            if(is_array($request->input('extension'))){
                $count = 0;
                foreach ($request->input('extension') as $key=>$value)
                {
                    $user_data['alt_extension'] = User::where('extension',$value)->get()->first();
                    ++$count;
                    $ext[] = 'SIP/'.$value.'&'.'SIP/'.$user_data['alt_extension']->alt_extension;
                    //$ext[] = 'SIP/'.$user_data['alt_extension']->alt_extension;
               

                    //phone number

                    $user_data['mobile'] = User::where('extension',$value)->get()->first();
                    ++$count;

                    $client = Client::where('id',$request->auth->parent_id)->get()->first();
                    if(!empty($client))
                    {
                        $tech_prefix = $client->tech_prefix;
                        $ext_phone[] = 'SIP/telnyx/'.$tech_prefix.$user_data['mobile']->mobile;
                    }
                    else
                    {
                        $ext_phone[] = 'SIP/telnyx/'.$user_data['mobile']->mobile;
                    }


                    
                   
                    
                }
                $extension_mobile = implode('&',$ext_phone);

                if($request->input('ring_type') == 1)
                {
                $extension = implode('&',$ext);

                }
                else
                {
                $extension = implode('-',$ext);

                }
                

                //echo "<pre>";print_r($extension);die;
            }

            if(is_array($request->input('emails'))){
                foreach ($request->input('emails') as $key=>$value)
                {
                    $email_listl[] = $value;
                }
                $emails = implode(',',$email_listl);
                //echo "<pre>";print_r($extension);die;
            }

            

            if($request->has('title') && !empty($request->input('title'))) {
                $data['title'] = $request->input('title');
                 $data['description'] = $request->input('description');

                $data['extensions'] = $extension;
                $data['phone_number'] = $extension_mobile;

                

                 if($request->has('emails') && !empty($request->input('emails'))) {
                $data['emails'] = $emails;
                }
                else
                {
                    $data['emails']="";
                }

                if($request->has('ring_type') && !empty($request->input('ring_type'))) {
                $data['ring_type'] = $request->input('ring_type');
                }

                if($request->has('receive_on') && !empty($request->input('receive_on'))) {
                $data['receive_on'] = $request->input('receive_on');
                }

                $data['extension_count'] = $count;
               
                $query = "INSERT INTO ".$this->table." (title, description, extensions,phone_number,emails,ring_type,extension_count,receive_on) VALUE (:title, :description, :extensions, :phone_number,:emails,:ring_type,:extension_count,:receive_on)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->insert($query, $data);
                if($add == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Ring Group added successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Ring Group not added successfully.'
                    );
                }
            }

            return array(
                'success'=> 'false',
                'message'=> 'Ring Group are not added successfully.'
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
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function ringDelete($request)
    {
        try
        {
            if($request->has('ring_id') && is_numeric($request->input('ring_id')))
            {
                $data['id'] = $request->input('ring_id');
                $query = "DELETE FROM ".$this->table." WHERE id = :id";
                $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                if($save == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Ring Group deleted successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Ring Group are not deleted successfully.'
                    );
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Ring Group doesn\'t exist.'
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

}
