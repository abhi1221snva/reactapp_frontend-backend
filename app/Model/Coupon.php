<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'coupons';
    
    /**
    * Get coupon list
    * @return type
    */
    public function getCouponsList()
    {
        try
        {
            $sql = "SELECT * FROM ".$this->table;
            $record =  DB::connection('master')->select($sql);
            $data = (array)$record;
            return array(
                'success'=> 'true',
                'message'=> 'Coupon list.',
                'data'   => $data
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
    }
    
    /**
    * Get coupon Detail
    * @return type
    */
    public function getCouponDetail($request)
    {
        try
        {
            $sql = "SELECT * FROM ".$this->table. " WHERE id = :id";
            $record =  DB::connection('master')->select($sql, ['id' => $request->coupon_id]);
            $data = (array)$record;
            return array(
                'success'=> 'true',
                'message'=> 'Coupon details.',
                'data'   => $data
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
    }
    
    /**
    * Edit
    * @param type $request
    * @return type
    */
    public function edit($request)
    {
        try
        {
            $data['name'] = $request->input('name');
            $data['code'] = $request->input('code');
            $data['type'] = $request->input('type');
            $data['amount'] = $request->input('amount');
            $data['currency_code'] = $request->input('currency_code');
            $data['start_at'] = $request->input('start_at');
            $data['expire_at'] = $request->input('expire_at');
            $data['status'] = $request->input('status');
            
            if($request->coupon_id)
            {
                $data['coupon_id'] = $request->input('coupon_id');
                $query = "UPDATE ".$this->table." SET "
                        . "name = :name, "
                        . "code = :code, "
                        . "type = :type, "
                        . "amount = :amount, "
                        . "currency_code = :currency_code, "
                        . "start_at = :start_at, "
                        . "expire_at = :expire_at, "
                        . "status = :status "
                        . " WHERE id = :coupon_id";
                $add =  DB::connection('master')->update($query, $data);
                $msg = "Coupon has been updated successfully";
            }
            else
            {
                $query = "INSERT INTO ".$this->table." (name, code, type, amount, currency_code, start_at, expire_at, status) "
                    . "VALUE (:name, :code, :type, :amount, :currency_code, :start_at, :expire_at, :status)";
                $add =  DB::connection('master')->insert($query, $data);
                $msg = "Coupon has been added successfully";
            }
            
            return array(
                'success'=> 'true',
                'message'=> $msg
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
    }
    
    
}
