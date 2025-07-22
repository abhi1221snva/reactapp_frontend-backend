<?php

namespace App\Http\Controllers;
use App\Model\Coupon;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
    * Create a new controller instance.
    *
    * @return void
    */
    private $request;
    public function __construct(Request $request, Coupon $coupon)
    {
        $this->request = $request;
        $this->model = $coupon;
    }

    /*
    * Fetch all Coupons
    * @return json
    */
    public function getCouponsList()
    {
        $response = $this->model->getCouponsList();
        return response()->json($response);
    }
    
    /*
    * Fetch Coupon detail
    * @return json
    */
    public function getCouponDetail()
    {
        $response = $this->model->getCouponDetail($this->request);
        return response()->json($response);
    }
    
    /*
    * Update coupon
    * @return json
    */
    public function edit()
    {
        $valRules = [
            'type'   => 'required|string',
            'amount'   => 'required|numeric',
            'currency_code'   => 'required|string',
            'start_at'   => 'required|string',
            'expire_at'   => 'required|string',
            'status'   => 'required|string',
        ];
        
        if($this->request->coupon_id > 0)
        {
            $valRules['name'] = 'required|string|unique:coupons,name,'.$this->request->coupon_id;
            $valRules['code'] = 'required|string|unique:coupons,code,'.$this->request->coupon_id;
        }
        else
        {
            $valRules['name'] = 'required|string|unique:coupons,name';
            $valRules['code'] = 'required|string|unique:coupons,code';
        }
        
        $this->validate($this->request, $valRules);
        $response = $this->model->edit($this->request);
        return response()->json($response);
    }   
}
