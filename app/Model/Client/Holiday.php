<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
use DB;
use App\Model\Client\Departments;

class Holiday extends Model
{
    public $timestamps = false;

    protected $table = "holiday";
    
    protected $fillable = ["name", "date", "month"];
    
    /*
    * Get all call hoidays of client
    */
    public function getAllHolidays($request)
    {
        try {
            $sql = "SELECT * FROM " . $this->table;
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Holidays.',
                    'data' => $data
                );
            }

            return array(
                'success' => 'false',
                'message' => 'No Holidays Found.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        }
    }
    
    /*
    * Get holiday detail
    */
    public function getHolidayDetail($request)
    {
        try {
            $sql = "SELECT * FROM " . $this->table." WHERE id = $request->holiday_id ";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Holiday detail',
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
    
    
    /**
    * Validate duplicate dept name
    * @param type $request
    * @return boolean
    */
    private function checkDuplicateHoliday($request)
    {
        $where['date'] = $request->data['date'];
        $where['month'] = $request->data['month'];
        if($request->data['holiday_id'] > 0)
        {
            $where['id'] = $request->data['holiday_id'];
            $query = "SELECT id FROM " . $this->table . " WHERE id != :id AND date = :date AND month = :month";
        } else {
            $query = "SELECT id FROM " . $this->table . " WHERE date = :date AND month = :month";
        }
        
        $holi = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $where);
        if(!empty($holi))
        {
            return true;
        } else {
            return false;
        }
    }
    
    /*
    * Save Holiday Details
    */
    public function saveHolidayDetail($request)
    {
        try
        {
            if($this->checkDuplicateHoliday($request))
            {
                return array(
                    'success' => 'false',
                    'message' => 'Date already marked as holiday',
                    'data' => array()
                );
            }
            $params['id'] = $request->data['holiday_id'];
            $query = "SELECT id FROM " . $this->table . " WHERE id = :id";
            $hol = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $params);
            $params['name'] = $request->data['name'];
            $params['date'] = $request->data['date'];
            $params['month'] = $request->data['month'];
            if(!empty($hol)) {
                $sql = "UPDATE " . $this->table . " SET name = :name, date = :date, month = :month "
                        . " WHERE id = :id";
                DB::connection('mysql_' . $request->auth->parent_id)->update($sql, $params);
            } else {
                unset($params['id']);
                $sql = "INSERT INTO " . $this->table . " (name,date,month) VALUE (:name,:date, :month)";
                DB::connection('mysql_' . $request->auth->parent_id)->update($sql , $params);
            }
            return array(
                    'success' => 'true',
                    'message' => 'Holiday has been saved successfully.',
                    'data' => array()
                );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        }
    }
    
    /*
    * Delete Holida
    */
    public function deleteHoliday($request)
    {
        try {
            $sql = "DELETE FROM " . $this->table. " WHERE id = :holiday_id";
            DB::connection('mysql_' . $request->auth->parent_id)->select($sql, ['holiday_id' => $request->holiday_id]);

            return array(
                'success' => 'true',
                'message' => 'Holiday has been deleted successfully',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        }
    }

}
