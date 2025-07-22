<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
use DB;
use App\Model\Client\Departments;

class CallTimings extends Model
{
    public $timestamps = false;

    protected $table = "call_timings";
    protected $tableDept = "department";
    
    protected $fillable = ["day", "from_time", "to_time", "department_id"];
    
    /*
    * Get all call timings of client
    */
    public function getCallTimings($request)
    {
        try {
            $sql = "SELECT * FROM " . $this->table." CT"
                    . " RIGHT JOIN department D ON CT.department_id = D.id "
                    . " WHERE 1=1";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Call Timings.',
                    'data' => $data
                );
            }

            return array(
                'success' => 'false',
                'message' => 'No Call Timings Found.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        }
    }
    
    /*
    * Get Department Call Timings
    */
    public function getDepartmentCallTimings($request)
    {
        try {
            $sql = "SELECT * FROM " . $this->table." CT"
                    . " RIGHT JOIN department D ON CT.department_id = D.id "
                    . " WHERE department_id = $request->dept_id ";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Call Timings.',
                    'data' => $data
                );
            }

            return array(
                'success' => 'false',
                'message' => 'No Call Timings Found.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        }
    }
    
    /*
    * Save office hours of client
    */
    public function saveCallTimings($request)
    {
       try {
            if($this->checkDuplicateDepartmentName($request))
            {
                return array(
                    'success' => 'false',
                    'message' => 'Department name already in use',
                    'data' => array()
                );
            }
            $deptId = $this->editDepartment($request); // save department

            foreach($request->data['day'] as $key => $val)
            {
                $query = "SELECT * FROM " . $this->table . " WHERE day = :day AND department_id = :department_id";
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, ['day' => $val, 'department_id' => $request->data['dept_id']]);
                $data = (array)$record;
                if(isset($request->data['from'][$key]) && isset($request->data['to'][$key])) {
                    if(!empty($data)) {
                        $sql = "UPDATE " . $this->table . " SET from_time = '".$request->data['from'][$key]."',"
                                . " to_time = '".$request->data['to'][$key]."' "
                                . " WHERE day = '$val' AND department_id = $deptId";
                        DB::connection('mysql_' . $request->auth->parent_id)->update($sql);
                    } else {
                        $params['day'] = $val;
                        $params['from_time'] = $request->data['from'][$key];
                        $params['to_time'] = $request->data['to'][$key];
                        $params['department_id'] = $deptId;
                        $query = "INSERT INTO " . $this->table . " (day,from_time,to_time, department_id) VALUE (:day,:from_time,:to_time,:department_id)";
                        $add = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $params);
                    }
                } else {
                    $sql = "DELETE FROM " . $this->table ." WHERE day = '$val' AND department_id = $deptId";
                    DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
                }
            }

            return array(
                'success' => 'true',
                'message' => 'Call Time have been saved successfully',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        }
    }
    
    /**
    * Validate duplicate dept name
    * @param type $request
    * @return boolean
    */
    private function checkDuplicateDepartmentName($request)
    {
        $where['name'] = $request->data['name'];
        if($request->data['dept_id'] > 0)
        {
            $where['id'] = $request->data['dept_id'];
            $query = "SELECT id, name FROM " . $this->tableDept . " WHERE id != :id AND name = :name";
        } else {
            $query = "SELECT id FROM " . $this->tableDept . " WHERE name = :name";
        }
        
        $dept = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $where);
        if(!empty($dept))
        {
            return true;
        } else {
            return false;
        }
    }
    
    /*
    * Save Department of client
    */
    public function editDepartment($request)
    {
        $deptid = $params['id'] = $request->data['dept_id'];
        $query = "SELECT id FROM " . $this->tableDept . " WHERE id = :id";
        $dept = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $params);
        $params['name'] = $request->data['name'];
        $params['description'] = $request->data['description'];
        if(!empty($dept)) {
            $sql = "UPDATE " . $this->tableDept . " SET name = :name, description = :description "
                    . " WHERE id = :id";
            $deptobj = DB::connection('mysql_' . $request->auth->parent_id)->update($sql, $params);
        } else {
            unset($params['id']);
            $query = "INSERT INTO " . $this->tableDept . " (name,description) VALUE (:name,:description)";
            $deptid = Departments::on('mysql_' . $request->auth->parent_id)->insertGetId([
                'name' => $params['name'],
                'description' => $params['description']
            ]);
            
        }
        return $deptid;
    }

}
