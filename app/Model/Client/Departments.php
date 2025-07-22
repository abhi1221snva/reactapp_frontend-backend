<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
use DB;

class Departments extends Model
{
    public $timestamps = false;

    protected $table = "department";
    
    protected $fillable = ["name", "description"];
    
    /*
    * Get Departmanets of client
    */
    public function getDepartments($request)
    {
        try {
            if(isset($request->dept_id) && $request->dept_id > 0) {
                $sql = "SELECT * FROM " . $this->table. " WHERE id = $request->dept_id";
            } else {
                $sql = "SELECT * FROM " . $this->table;
            }
            
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Departments.',
                    'data' => $data
                );
            }

            return array(
                'success' => 'false',
                'message' => 'No Departments Found.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        }
    }
    
    /*
    * Save Department of client
    */
    public function editDepartment($request)
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
            $params['id'] = $request->dept_id;
            $query = "SELECT id FROM " . $this->table . " WHERE id = :id";
            $dept = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $params);
            $params['name'] = $request->name;
            $params['description'] = $request->description;
            if(!empty($dept)) {
                $sql = "UPDATE " . $this->table . " SET name = :name, description = :description "
                        . " WHERE id = :id";
                DB::connection('mysql_' . $request->auth->parent_id)->update($sql, $params);
            } else {
                unset($params['id']);
                $query = "INSERT INTO " . $this->table . " (name,description) VALUE (:name,:description)";
                DB::connection('mysql_' . $request->auth->parent_id)->update($query, $params);
            }

            return array(
                'success' => 'true',
                'message' => 'Department have been saved successfully',
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
        $where['name'] = $request->data->name;
        if($request->data->dept_id > 0)
        {
            $where['id'] = $request->data->dept_id;
            $query = "SELECT id, name FROM " . $this->table . " WHERE id != :id AND name = :name";
        } else {
            $query = "SELECT id FROM " . $this->table . " WHERE name = :name";
        }
        
        $dept = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $where);
        if(!empty($dept))
        {
            return true;
        } else {
            return false;
        }
    }
   
}
