<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

class ExcludeNumber extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'exclude_number';
    /*
     *Fetch Exclude Number list
     *@param integer $id
     *@return array
     */
    public function excludeNumberDetail($request)
    { 
        try {
        $searchTerm = $request->input('search');
        $limitString = '';
        $parameters = [];

        $query = "SELECT SQL_CALC_FOUND_ROWS * FROM  $this->table";

        if (!empty($searchTerm)) {
            $query .= " WHERE (first_name LIKE CONCAT(?, '%') OR last_name LIKE CONCAT(?, '%') OR company_name LIKE CONCAT(?, '%') OR number LIKE CONCAT(?, '%'))";
            $parameters[] = $searchTerm;
            $parameters[] = $searchTerm;
            $parameters[] = $searchTerm;
            $parameters[] = $searchTerm;
        }

        if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $query .= " LIMIT ?, ?";
            $parameters[] = $request->input('lower_limit');
            $parameters[] = $request->input('upper_limit');
        }

        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $parameters);

        $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
        $recordCount = (array)$recordCount;

        $data = (array)$record;

        if (!empty($data)) {
            return [
                'success' => true,
                'message' => 'Exclude Number.',
                'data' => $data,
                'record_count' => $recordCount['count'],
                'searchTerm'=>$searchTerm
            ];
        }

        return [
            'success' => false,
            'message' => 'Exclude Number not found.',
            'data' => [],
            'record_count' => 0,
            'errors' => [],
            'searchTerm'=>$searchTerm
        ];
    } catch (Exception $e) {
        Log::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
    }
       
    }

    public function excludeNumberDetailo($request)
    {
        try
        {
            $data = array();
            $searchStr = array();
            if($request->has('number') && is_numeric($request->input('number')))
            {
                array_push($searchStr, "number like CONCAT(:number, '%')");
                $data['number'] = $request->input('number');
            }
            if($request->has('campaign_id') && is_numeric($request->input('campaign_id')))
            {
                array_push($searchStr, 'campaign_id = :campaign_id');
                $data['campaign_id'] = $request->input('campaign_id');
            }
            if ($request->has('first_name') && !empty($request->input('first_name')))
            {
                array_push($searchStr, "first_name like CONCAT(:first_name, '%')");
                $data['first_name'] = $request->input('first_name');
            }
            if ($request->has('last_name') && !empty($request->input('last_name'))) {
                array_push($searchStr, "last_name like CONCAT(:last_name, '%')");
                $data['last_name'] = $request->input('last_name');
            }
            if ($request->has('company_name') && !empty($request->input('company_name'))) {
                array_push($searchStr, "company_name like CONCAT(:company_name, '%')");
                $data['company_name'] = $request->input('company_name');
            }
            $str = !empty($searchStr) ? "  WHERE ".implode(" AND ", $searchStr) : '';
             
        $limitString = '';
        if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $data['lower_limit'] = $request->input('lower_limit');
            $data['upper_limit'] = $request->input('upper_limit');
            $limitString = " LIMIT :lower_limit, :upper_limit";
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM " . $this->table . $str . $limitString;
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
            $recordCount = (array) $recordCount;
            $data = (array)$record;
            if(!empty($data))
            {
                return array(
                    'success'=> 'true',
                    'message'=> 'Exclude Number detail.',
                    'data'   => $data,
                    'record_count' => $recordCount['count']
                );
            }
            return array(
                'success'=> 'false',
                'message'=> 'Exclude Number not created.',
                'data'   => array(),
                'record_count'=>0
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
     *Update Exclude Number details
     *@param object $request
     *@return array
     */
    public function excludeNumberUpdate($request)
    {
        try {
            if ($request->has('number') && is_numeric($request->input('number')) && $request->has('campaign_id') && is_numeric($request->input('campaign_id')))
            {
                $updateString = array();
                $data['number'] = $request->input('number');
                $data['campaign_id'] = $request->input('campaign_id');
                if ($request->has('new_campaign_id') && is_numeric($request->input('new_campaign_id'))) {
                    array_push($updateString, 'campaign_id = :new_campaign_id');
                    $data['new_campaign_id'] = $request->input('new_campaign_id');
                }
                if ($request->has('first_name') && !empty($request->input('first_name')))
                {
                    array_push($updateString, 'first_name = :first_name');
                    $data['first_name'] = $request->input('first_name');
                }
                if ($request->has('last_name') && !empty($request->input('last_name'))) {
                    array_push($updateString, 'last_name = :last_name');
                    $data['last_name'] = $request->input('last_name');
                }
                if ($request->has('company_name') && !empty($request->input('company_name'))) {
                    array_push($updateString, 'company_name = :company_name');
                    $data['company_name'] = $request->input('company_name');
                }
                if (!empty($updateString) && !empty($data))
                {
                    $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE number = :number AND campaign_id = :campaign_id";
                    $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                    if ($save == 1) {
                        return array(
                            'success' => 'true',
                            'message' => 'Exclude Number updated successfully.'
                        );
                    } else {
                        return array(
                            'success' => 'false',
                            'message' => 'Exclude Number are updated.'
                        );
                    }
                }

                return array(
                    'success' => 'false',
                    'message' => 'Exclude Number doesn\'t exist.'
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
     *Add Exclude Number details
     *@param object $request
     *@return array
     */
    public function addExcludeNumber($request)
    {
        try
        {
            if($request->has('number') && is_numeric($request->input('number')) && $request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
                $data['number'] = $request->input('number');
                $data['campaign_id'] = $request->input('campaign_id');
                $data['first_name'] = ($request->has('first_name') && !empty($request->input('first_name'))) ? $request->input('first_name') : "";
                $data['last_name'] = ($request->has('last_name') && !empty($request->input('last_name'))) ? $request->input('last_name') : "";
                $data['company_name'] = ($request->has('company_name') && !empty($request->input('company_name'))) ? $request->input('company_name') : "";
                $query = "INSERT INTO ".$this->table." (number, campaign_id, first_name, last_name, company_name) VALUE (:number, :campaign_id, :first_name, :last_name, :company_name)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                if($add == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Exclude Number added successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Exclude Number are not added successfully.'
                    );
                }
            }

            return array(
                'success'=> 'false',
                'message'=> 'Exclude Number are not added successfully.'
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
     *Delete Exclude Number details
     *@param object $request
     *@return array
     */
    public function excludeNumberDelete($request)
    {
        try
        {
            if ($request->has('number') && is_numeric($request->input('number')) && $request->has('campaign_id') && is_numeric($request->input('campaign_id')))
            {
                $data['number'] = $request->input('number');
                $data['campaign_id'] = $request->input('campaign_id');
                $query = "DELETE FROM ".$this->table." WHERE number = :number AND campaign_id = :campaign_id";
                $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                if($save == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Exclude Number deleted successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Exclude Number are not deleted successfully.'
                    );
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Exclude Number doesn\'t exist.'
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


            public function uploadExcludeNumber($request, $filePath)
    {

        
        try
        {
            if
            (!empty($filePath))
            {
                $dataBase = 'mysql_'.$request->auth->parent_id;
                try
                {
                    $reader = Excel::toArray(new Excel(), $filePath);
                }
                catch (\Exception $e)
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Unable to read excel.'
                    );
                }
               
                   
                       
                        if(!empty($reader))
                        {
                            $count = 0;
                            foreach ($reader as $row)
                            {
                                $i=0;
                                 foreach ($row as $item=>$value)
                                    {
                                        if($item!=0){
                               $data['number'] = $value[0];
                               $data['campaign_id'] = $value[1];
                               $data['first_name'] = $value[2];
                               $data['last_name'] = $value[3];
                               $data['company_name'] = $value[4];

                               $data['updated_at'] = $value[5];

                               //echo "<pre>";print_r($data);

                            $query = "INSERT INTO ".$this->table." (number, campaign_id, first_name,last_name,company_name,updated_at) VALUE (:number, :campaign_id, :first_name,:last_name,:company_name,:updated_at)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->insert($query, $data);

                            }

                                    }
                                }


                                if($add == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'ExcludeNumber added successfully.'
                    );
                }

                                
                                
                                           
                        }
                        else
                        {
                            return array(
                                'success'=> 'false',
                                'message'=> 'ExcludeNumber not added successfully, File is empty',
                                
                            );
                        }
                                     
            }

            return array(
                'success'=> 'false',
                'message'=> 'ExcludeNumber are not added successfully.'
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
