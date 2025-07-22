<?php

namespace App\Http\Controllers\Ringless;
use App\Http\Controllers\Controller;


use App\Model\Client\Ringless\RinglessIvr;
use Illuminate\Http\Request;

class RinglessIvrController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, RinglessIvr $ivr)
    {
        $this->request = $request;
        $this->model = $ivr;
    }

    /*
     * Fetch Dnc details
     * @return json
     */
    public function getIvr()
    {
        $response = $this->model->ivrDetail($this->request);
        return response()->json($response);
    }
    /*
     * Update Dnc detail
     * @return json
     */
    public function editIvr()
    {
        $this->validate($this->request, [
            'ann_id' => 'string',
            'ivr_id'   => 'string',
            'ivr_desc'   => 'string',
            
            'id'        => 'required|numeric'
        ]);
        $response = $this->model->ivrUpdate($this->request);
        return response()->json($response);
    }
    /*
     *Add Dnc details
     *@return json
     */
    public function addIvr()
    {
        $this->validate($this->request, [
            'ann_id' => 'string',
            'ivr_id'   => 'string',
            'ivr_desc'   => 'string',
            
            'id'        => 'required|numeric'
        ]);
        $response = $this->model->addIvr($this->request);
        return response()->json($response);
    }
    /*
     *Delete Dnc
     *@return json
     */
    public function deleteIvr()
    {
        $this->validate($this->request, [
           
            'id'        => 'required|numeric'
        ]);
        $response = $this->model->ivrDelete($this->request);
        return response()->json($response);
    }



   
}
