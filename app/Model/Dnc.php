<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;



class Dnc extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'dnc';
    /*
     *Fetch dnc list
     *@param integer $id
     *@return array
     */

    public function dncDetail(Request $request)
    {
        try {
            $searchTerm = $request->input('search');
            $limitString = '';
            $parameters = [];

            $query = "SELECT * FROM dnc";

            if (!empty($searchTerm)) {
                $query .= " WHERE (number LIKE CONCAT(?, '%') OR extension LIKE CONCAT(?, '%'))";
                $parameters[] = $searchTerm;
                $parameters[] = $searchTerm;
            }

            $countQuery = "SELECT COUNT(*) as count " . substr($query, strpos($query, 'FROM'));
            $countParameters = $parameters;

            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $query .= " LIMIT ?, ?";
                $parameters[] = $request->input('lower_limit');
                $parameters[] = $request->input('upper_limit');
            }

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $parameters);

            $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($countQuery, $countParameters);
            $recordCount = (array)$recordCount;

            $data = (array)$record;

            if (!empty($data)) {
                return [
                    'success' => true,
                    'message' => 'DNC Detail.',
                    'data' => $data,
                    'record_count' => $recordCount['count'],
                    'searchTerm' => $searchTerm
                ];
            }

            return [
                'success' => false,
                'message' => 'DNC not found.',
                'data' => [],
                'record_count' => 0,
                'errors' => [],
                'searchTerm' => $searchTerm
            ];
        } catch (Exception $e) {
            Log::error($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::error($e->getMessage());
        }
    }

    // public function dncDetail($request)
    // {

    //     try {

    //         $data = array();
    //         $searchStr = array();

    //          $searchTerm = $request->input('search');



    //         if (!empty($searchTerm)) {
    //             array_push($searchStr, "(extension LIKE CONCAT(:search, '%') OR number LIKE CONCAT(:search, '%'))");
    //             $data['search'] = $searchTerm;

    //         }

    //         $str = !empty($searchStr) ? " WHERE " . implode(" AND ", $searchStr) : '';

    //         $limitString = '';
    //         if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
    //             $data['lower_limit'] = $request->input('lower_limit');
    //             $data['upper_limit'] = $request->input('upper_limit');
    //             $limitString = " LIMIT :lower_limit, :upper_limit";
    //         }

    //         $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM dnc" . $str . $limitString;
    //         $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);

    //         $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
    //         $recordCount = (array) $recordCount;

    //         $data = (array)$record;
    //         if (!empty($data)) {
    //             return array(
    //                 'success' => 'true',
    //                 'message' => 'Dnc detail.',
    //                 'data' => $data,
    //                 'record_count' => $recordCount['count'],

    //             );
    //         }
    //         return array(
    //             'success' => 'false',
    //             'message' => 'Dnc not created.',
    //             'data' => array(),
    //             'record_count'=>0
    //         );
    //     } catch (Exception $e) {
    //         Log::log($e->getMessage());
    //     } catch (InvalidArgumentException $e) {
    //         Log::log($e->getMessage());
    //     }
    // }


    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function dncUpdate($request)
    {
        try {
            if ($request->has('number') && is_numeric($request->input('number'))) {
                $updateString = array();
                $data['number'] = $request->input('number');
                if ($request->has('extension') && is_numeric($request->input('extension'))) {
                    array_push($updateString, 'extension = :extension');
                    $data['extension'] = $request->input('extension');
                } else {
                    array_push($updateString, 'extension = :extension');
                    $data['extension'] = $request->auth->extension;
                }
                if ($request->has('comment') && !empty($request->input('comment'))) {
                    array_push($updateString, 'comment = :comment');
                    $data['comment'] = $request->input('comment');
                }
                if (!empty($updateString) && !empty($data)) {
                    $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE number = :number";
                    $save =  DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                    if ($save == 1) {
                        return array(
                            'success' => 'true',
                            'message' => 'Dnc updated successfully.'
                        );
                    } else {
                        return array(
                            'success' => 'false',
                            'message' => 'Dnc are not updated successfully.'
                        );
                    }
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Dnc doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    /*
     *Add dnc details
     *@param object $request
     *@return array
     */
    public function addDnc($request)
    {
        try {
            if ($request->has('number') && !empty($request->input('number'))) {
                $data['number'] = $request->input('number');
                $data['extension'] = ($request->has('extension') && !empty($request->input('extension'))) ? $request->input('extension') : $request->auth->extension;
                $data['comment'] = ($request->has('comment') && !empty($request->input('comment'))) ? $request->input('comment') : "";

                $sql = "SELECT * FROM " . $this->table . " WHERE number = '" . $data['number'] . "'";
                $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql);

                if (!empty($record)) {
                    return array(
                        'success' => 'false',
                        'message' => 'Number is already there in our DO NOT CALL registry.'
                    );
                }

                $query = "INSERT INTO " . $this->table . " (number, extension, comment) VALUE (:number, :extension, :comment)";
                $add =  DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);
                if ($add == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'Dnc added successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Dnc are not added successfully.'
                    );
                }
            }

            return array(
                'success' => 'false',
                'message' => 'Dnc are not added successfully.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    /*
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function dncDelete($request)
    {
        try {
            if ($request->has('number') && is_numeric($request->input('number'))) {
                $data['number'] = $request->input('number');
                $query = "DELETE FROM " . $this->table . " WHERE number = :number";
                $save =  DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                if ($save == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'Dnc deleted successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Dnc are not deleted successfully.'
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Dnc doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }



    public function uploadDnc($request, $filePath)
    {
        try {
            if (!empty($filePath)) {
                $dataBase = 'mysql_' . $request->auth->parent_id;
                try {
                    $reader = Excel::toArray(new Excel(), $filePath);
                } catch (\Exception $e) {
                    return array(
                        'success' => 'false',
                        'message' => 'Unable to read excel.'
                    );
                }



                if (!empty($reader)) {
                    $count = 0;
                    foreach ($reader as $row) {
                        $i = 0;
                        foreach ($row as $item => $value) {
                            if ($item != 0) {
                                $data['number'] = $value[0];
                                $data['extension'] = $value[1];
                                $data['comment'] = $value[2];
                                $data['updated_at'] = $value[3];

                                //echo "<pre>";print_r($data);

                                $query = "INSERT INTO " . $this->table . " (number, extension, comment,updated_at) VALUE (:number, :extension, :comment,:updated_at)";
                                $add =  DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);
                            }
                        }
                    }


                    if ($add == 1) {
                        return array(
                            'success' => 'true',
                            'message' => 'Dnc added successfully.'
                        );
                    }
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'DNC not added successfully, File is empty',

                    );
                }
            }

            return array(
                'success' => 'false',
                'message' => 'Dnc  not added successfully.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
}
